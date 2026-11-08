<?php

declare(strict_types=1);

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use App\Payroll\PayrollEntry;
use App\Payroll\PayrollPayment;
use App\Payroll\PayrollRun;
use App\Payroll\PayrollService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || app()->environment('production')) {
    throw new RuntimeException('This stress harness requires a disposable non-production MySQL database.');
}

if (($argv[1] ?? '') === 'worker') {
    [$script, , $action, $actorId, $targetId, $payload, $barrier, $slot] = $argv;
    file_put_contents($barrier.'/ready-'.$slot, 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($barrier.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout');
        }
        usleep(10000);
    }
    DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
    $service = app(PayrollService::class);
    $actor = User::query()->findOrFail((int) $actorId);
    $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    try {
        $id = match ($action) {
            'approve' => (function () use ($service, $actor, $targetId) {
                $service->finalize($actor, PayrollRun::query()->findOrFail((int) $targetId));

                return (int) $targetId;
            })(),
            'pay' => $service->payment($actor, PayrollEntry::query()->findOrFail((int) $targetId), $data)->id,
            'calculate' => (function () use ($service, $actor, $targetId) {
                $service->calculate($actor, PayrollRun::query()->findOrFail((int) $targetId));

                return (int) $targetId;
            })(),
            'review' => $service->review($actor, PayrollRun::query()->findOrFail((int) $targetId), EmployeeProfile::query()->findOrFail((int) $data['employee_id']),
                $data['date'], $data['decision'], (int) $data['amount_minor'], $data['reason'])->id,
            'policy' => $service->policy($actor, $data)->id,
            'compensation' => $service->compensation($actor, EmployeeProfile::query()->findOrFail((int) $targetId), $data)->id,
            default => throw new RuntimeException('Unknown worker action'),
        };
        echo json_encode(['result' => 'ok', 'id' => $id], JSON_THROW_ON_ERROR);
    } catch (ValidationException $e) {
        echo json_encode(['result' => 'validation', 'fields' => array_keys($e->errors())], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        echo json_encode(['result' => 'error', 'type' => get_class($e), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
    }
    exit;
}

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<array{action: string, actor: int, target: int, payload: array<string, mixed>}> $jobs
 * @return list<array<string, mixed>>
 */
function race(array $jobs): array
{
    $barrier = sys_get_temp_dir().'/hrms-mysql-race-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    $processes = [];
    try {
        foreach ($jobs as $slot => $job) {
            $command = [PHP_BINARY, __FILE__, 'worker', $job['action'], (string) $job['actor'], (string) $job['target'],
                json_encode($job['payload'], JSON_THROW_ON_ERROR), $barrier, (string) $slot];
            $pipes = [];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            check(is_resource($process), 'Could not start independent PHP worker');
            $processes[] = [$process, $pipes];
        }
        $deadline = microtime(true) + 15;
        while (count(glob($barrier.'/ready-*')) !== count($jobs)) {
            check(microtime(true) < $deadline, 'Workers did not reach barrier');
            usleep(10000);
        }
        touch($barrier.'/go');
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            stream_set_timeout($pipes[1], 20);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $result = json_decode($stdout, true);
            check(is_array($result), 'Worker failed: '.$stderr.' '.$stdout);
            $results[] = $result;
        }

        return $results;
    } finally {
        foreach (glob($barrier.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($barrier);
    }
}

$started = microtime(true);
$hr = User::factory()->create(['role' => Role::HR]);
$admin = User::factory()->create(['role' => Role::Admin]);
$worker = User::factory()->create();
$employee = EmployeeProfile::create(['company_id' => $hr->company_id, 'user_id' => $worker->id, 'employee_number' => 'STRESS-'.bin2hex(random_bytes(4)), 'name' => 'Fictional Stress Employee']);
$service = app(PayrollService::class);
$service->policy($hr, ['effective_from' => '2020-01-01', 'currency' => 'BDT', 'proration_basis' => 'calendar_days', 'reason' => 'Disposable concurrency fixture']);
$approvalRounds = 8;
for ($i = 0; $i < $approvalRounds; $i++) {
    $run = $service->createRun($hr, '2026-10-01', 'supplemental', [$employee->id], 'Disposable concurrency fixture');
    $service->adjustment($hr, $run, $employee, ['kind' => 'correction_credit', 'amount_minor' => 100000, 'idempotency_key' => 'stress-'.$i, 'reason' => 'Fictional correction']);
    $service->calculate($hr, $run);
    $result = race([
        ['action' => 'approve', 'actor' => $admin->id, 'target' => $run->id, 'payload' => []],
        ['action' => 'approve', 'actor' => $admin->id, 'target' => $run->id, 'payload' => []],
    ]);
    check(count(array_filter($result, fn ($r) => $r['result'] === 'ok')) === 1, 'Approval race did not yield one success: '.json_encode($result));
    check($run->fresh()->status === 'finalized' && $run->entries()->count() === 1, 'Approval changed entry cardinality');
    if ($i === 0) {
        $entry = $run->entries()->sole();
        $paymentRace = race([
            ['action' => 'pay', 'actor' => $hr->id, 'target' => $entry->id, 'payload' => ['amount_minor' => 60000, 'paid_on' => '2026-11-07', 'reference' => 'STRESS-A', 'idempotency_key' => 'pay-a']],
            ['action' => 'pay', 'actor' => $hr->id, 'target' => $entry->id, 'payload' => ['amount_minor' => 60000, 'paid_on' => '2026-11-07', 'reference' => 'STRESS-B', 'idempotency_key' => 'pay-b']],
        ]);
        check(count(array_filter($paymentRace, fn ($r) => $r['result'] === 'ok')) === 1, 'Overpayment race failed: '.json_encode($paymentRace));
        check((int) $entry->payments()->sum('amount_minor') === 60000, 'Payment sum exceeded net');
        $identical = ['amount_minor' => 10000, 'paid_on' => '2026-11-07', 'reference' => 'STRESS-C', 'idempotency_key' => 'pay-c'];
        $retryRace = race([
            ['action' => 'pay', 'actor' => $hr->id, 'target' => $entry->id, 'payload' => $identical],
            ['action' => 'pay', 'actor' => $hr->id, 'target' => $entry->id, 'payload' => $identical],
        ]);
        check(count(array_filter($retryRace, fn ($r) => $r['result'] === 'ok')) === 2, 'Identical retry race failed: '.json_encode($retryRace));
        check(PayrollPayment::where('entry_id', $entry->id)->where('idempotency_key', 'pay-c')->count() === 1, 'Duplicate payment key');
        $conflicting = race([
            ['action' => 'pay', 'actor' => $hr->id, 'target' => $entry->id, 'payload' => ['amount_minor' => 10000, 'paid_on' => '2026-11-07', 'reference' => 'STRESS-D', 'idempotency_key' => 'pay-d']],
            ['action' => 'pay', 'actor' => $hr->id, 'target' => $entry->id, 'payload' => ['amount_minor' => 20000, 'paid_on' => '2026-11-07', 'reference' => 'STRESS-E', 'idempotency_key' => 'pay-d']],
        ]);
        check(count(array_filter($conflicting, fn ($r) => $r['result'] === 'ok')) === 1, 'Conflicting retry race failed: '.json_encode($conflicting));
        check((int) $entry->payments()->sum('amount_minor') <= $entry->net_minor, 'Reconciliation exceeded net');
    }
}

$run = $service->createRun($hr, '2026-10-01', 'supplemental', [$employee->id], 'Calculate versus review fixture');
$service->adjustment($hr, $run, $employee, ['kind' => 'correction_credit', 'amount_minor' => 100000, 'idempotency_key' => 'calc-race', 'reason' => 'Fictional correction']);
$result = race([
    ['action' => 'calculate', 'actor' => $hr->id, 'target' => $run->id, 'payload' => []],
    ['action' => 'review', 'actor' => $hr->id, 'target' => $run->id, 'payload' => ['employee_id' => $employee->id, 'date' => '2026-10-01', 'decision' => 'overtime', 'amount_minor' => 1000, 'reason' => 'Fictional approved amount']],
]);
check(count(array_filter($result, fn ($r) => $r['result'] === 'ok')) === 2, 'Calculation/review race failed: '.json_encode($result));
if ($run->fresh()->calculated_at === null) {
    $service->calculate($hr, $run);
}
$service->finalize($admin, $run->fresh());
check((int) $run->entries()->sole()->net_minor === 101000, 'Review amount missing from finalized entry');

$seconds = round(microtime(true) - $started, 3);
echo json_encode(['status' => 'passed', 'processes_per_race' => 2, 'approval_repetitions' => $approvalRounds,
    'payment_races' => 3, 'calculate_review_races' => 1, 'deadlocks_or_timeouts' => 0,
    'elapsed_seconds' => $seconds], JSON_THROW_ON_ERROR).PHP_EOL;
