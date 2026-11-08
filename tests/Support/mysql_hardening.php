<?php

declare(strict_types=1);

use App\Attendance\AttendanceRecord;
use App\Attendance\AttendanceService;
use App\Compliance\CompliancePack;
use App\Compliance\ComplianceService;
use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Leave\LeaveBalanceEntry;
use App\Leave\LeavePolicy;
use App\Leave\LeaveRequest;
use App\Leave\LeaveService;
use App\Leave\LeaveType;
use App\Models\User;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use App\Payroll\PayrollAttendanceReview;
use App\Payroll\PayrollEntry;
use App\Payroll\PayrollPayment;
use App\Payroll\PayrollRun;
use App\Payroll\PayrollService;
use App\Scheduling\ShiftAssignment;
use App\Scheduling\ShiftScheduleService;
use App\Scheduling\ShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, (string) $exception.PHP_EOL);
    exit(1);
});
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
            'punch' => app(AttendanceService::class)->punch($actor, EmployeeProfile::query()->findOrFail((int) $targetId), $data['action'], $data['key'])->id,
            'attendance_manual' => app(AttendanceService::class)->manual($actor, EmployeeProfile::query()->findOrFail((int) $targetId), $data['action'], CarbonImmutable::parse($data['occurred_at']), $data['reason'], $data['key'])->id,
            'attendance_decide' => app(AttendanceService::class)->decide($actor, AttendanceRecord::query()->findOrFail((int) $targetId), $data['decision'], $data['reason'])->id,
            'shift' => app(ShiftScheduleService::class)->assign($actor, EmployeeProfile::query()->findOrFail((int) $targetId), ShiftTemplate::query()->findOrFail((int) $data['template_id']),
                $data['start'], $data['end'], $data['weekdays'], $data['reason'], $data['key'], $data['supersedes_id'] ?? null)->id,
            'leave_decide' => app(LeaveService::class)->decide($actor, LeaveRequest::query()->findOrFail((int) $targetId), $data['action'], $data['reason'])->id,
            'leave_cancel' => app(LeaveService::class)->cancel($actor, LeaveRequest::query()->findOrFail((int) $targetId), $data['reason'])->id,
            'compliance_create' => app(ComplianceService::class)->create($actor, $data['from'], $data['to'] ?? null)->id,
            'compliance_activate' => (function () use ($actor, $targetId) {
                app(ComplianceService::class)->activate($actor, CompliancePack::findOrFail((int) $targetId));

                return (int) $targetId;
            })(),
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
$department = Department::firstOrCreate(['company_id' => $hr->company_id, 'name' => 'Stress Operations']);
$position = Position::firstOrCreate(['company_id' => $hr->company_id, 'name' => 'Stress Officer']);
$location = Location::firstOrCreate(['company_id' => $hr->company_id, 'name' => 'Stress Dhaka'], ['timezone' => 'Asia/Dhaka']);
app(AssignmentService::class)->assign($employee, $department, $position, $location, CarbonImmutable::parse('2020-01-01'), null, null);
$service = app(PayrollService::class);
$service->policy($hr, ['effective_from' => '2020-01-01', 'effective_to' => '2026-12-31', 'currency' => 'BDT', 'proration_basis' => 'calendar_days', 'reason' => 'Disposable concurrency fixture']);
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

$run = $service->createRun($hr, '2026-09-01', 'supplemental', [$employee->id], 'Finalize versus review fixture');
$service->adjustment($hr, $run, $employee, ['kind' => 'correction_credit', 'amount_minor' => 100000, 'idempotency_key' => 'finalize-review-race', 'reason' => 'Fictional correction']);
$service->calculate($hr, $run);
$finalizeReviewRace = race([
    ['action' => 'approve', 'actor' => $admin->id, 'target' => $run->id, 'payload' => []],
    ['action' => 'review', 'actor' => $hr->id, 'target' => $run->id, 'payload' => ['employee_id' => $employee->id, 'date' => '2026-09-01', 'decision' => 'overtime', 'amount_minor' => 1000, 'reason' => 'Fictional approved amount']],
]);
check(count(array_filter($finalizeReviewRace, fn ($r) => $r['result'] === 'ok')) === 1, 'Finalize/review race did not serialize: '.json_encode($finalizeReviewRace));
if ($run->fresh()->status === 'finalized') {
    check((int) $run->entries()->sole()->net_minor === 100000 && PayrollAttendanceReview::where('run_id', $run->id)->count() === 0, 'Finalized payroll accepted a later review');
} else {
    check($run->fresh()->calculated_at === null && PayrollAttendanceReview::where('run_id', $run->id)->count() === 1, 'Review did not invalidate draft calculation');
    $service->calculate($hr, $run);
    $service->finalize($admin, $run->fresh());
    check((int) $run->entries()->sole()->net_minor === 101000, 'Recalculated review missing from finalized entry');
}

$policyPayload = ['effective_from' => '2027-01-01', 'currency' => 'BDT', 'proration_basis' => 'calendar_days', 'reason' => 'Future fictional policy'];
$policyRace = race([
    ['action' => 'policy', 'actor' => $hr->id, 'target' => 0, 'payload' => $policyPayload],
    ['action' => 'policy', 'actor' => $admin->id, 'target' => 0, 'payload' => $policyPayload],
]);
check(count(array_filter($policyRace, fn ($r) => $r['result'] === 'ok')) === 1, 'Overlapping policy race failed: '.json_encode($policyRace));
check((int) DB::table('payroll_policies')->where('company_id', $hr->company_id)->where('effective_from', '>=', '2027-01-01')->count() === 1, 'Overlapping policy was saved');

$compensationPayload = ['effective_from' => '2027-01-01', 'currency' => 'BDT', 'monthly_base_minor' => 120000, 'components' => [], 'reason' => 'Future fictional salary'];
$compensationRace = race([
    ['action' => 'compensation', 'actor' => $hr->id, 'target' => $employee->id, 'payload' => $compensationPayload],
    ['action' => 'compensation', 'actor' => $admin->id, 'target' => $employee->id, 'payload' => $compensationPayload],
]);
check(count(array_filter($compensationRace, fn ($r) => $r['result'] === 'ok')) === 1, 'Overlapping compensation race failed: '.json_encode($compensationRace));
check((int) DB::table('compensation_agreements')->where('employee_profile_id', $employee->id)->count() === 1, 'Overlapping compensation was saved');

$punchRace = race([
    ['action' => 'punch', 'actor' => $worker->id, 'target' => $employee->id, 'payload' => ['action' => 'check_in', 'key' => 'stress-punch']],
    ['action' => 'punch', 'actor' => $worker->id, 'target' => $employee->id, 'payload' => ['action' => 'check_in', 'key' => 'stress-punch']],
]);
check(count(array_filter($punchRace, fn ($r) => $r['result'] === 'ok')) === 2, 'Same-key attendance punch race failed: '.json_encode($punchRace));
check(AttendanceRecord::where('employee_profile_id', $employee->id)->where('idempotency_key', 'stress-punch')->count() === 1, 'Duplicate attendance punch saved');

$businessDay = CarbonImmutable::now('UTC')->subDay()->startOfDay();
$attendance = app(AttendanceService::class);
$correctionEmployee = EmployeeProfile::create(['company_id' => $hr->company_id, 'employee_number' => 'CORR-'.bin2hex(random_bytes(4)), 'name' => 'Fictional Correction Employee']);
app(AssignmentService::class)->assign($correctionEmployee, $department, $position, $location, CarbonImmutable::parse('2020-01-01'), null, null);
$original = $attendance->manual($hr, $correctionEmployee, 'check_in', $businessDay->addHours(9), 'Fictional original check-in', 'stress-original');
$correction = $attendance->proposeCorrection($hr, $original, 'check_in', $businessDay->addHours(10), 'Fictional corrected check-in', 'stress-correction');
$correctionRace = race([
    ['action' => 'attendance_decide', 'actor' => $admin->id, 'target' => $correction->id, 'payload' => ['decision' => 'approved', 'reason' => 'Fictional correction review']],
    ['action' => 'attendance_manual', 'actor' => $hr->id, 'target' => $correctionEmployee->id, 'payload' => ['action' => 'check_out', 'occurred_at' => $businessDay->addHours(9)->addMinutes(30)->toIso8601String(), 'reason' => 'Fictional manual close', 'key' => 'stress-close']],
]);
check(count(array_filter($correctionRace, fn ($r) => $r['result'] === 'ok')) === 1, 'Correction/manual sequence race did not serialize: '.json_encode($correctionRace));
check($correction->decision()->count() <= 1 && AttendanceRecord::where('idempotency_key', 'stress-close')->count() <= 1, 'Attendance race duplicated audit rows');

$template = app(ShiftScheduleService::class)->createTemplate($hr, $location, ['code' => 'STRESS-'.bin2hex(random_bytes(3)), 'name' => 'Fictional stress shift',
    'starts_at_local' => '09:00', 'ends_at_local' => '17:00', 'unpaid_break_minutes' => 60, 'grace_in_minutes' => 10, 'grace_out_minutes' => 5]);
$shiftStart = CarbonImmutable::parse((CarbonImmutable::now('Asia/Dhaka')->year + 1).'-03-01', 'Asia/Dhaka')->next('Monday');
$shiftEnd = $shiftStart->addDays(21);
$replacementStart = $shiftStart->addDays(7);
$shiftPayload = ['template_id' => $template->id, 'start' => $shiftStart->toDateString(), 'end' => $shiftEnd->toDateString(), 'weekdays' => [1, 2, 3, 4, 5], 'reason' => 'Fictional shift publication', 'key' => 'stress-shift'];
$shiftRace = race([
    ['action' => 'shift', 'actor' => $hr->id, 'target' => $employee->id, 'payload' => $shiftPayload],
    ['action' => 'shift', 'actor' => $hr->id, 'target' => $employee->id, 'payload' => $shiftPayload],
]);
check(count(array_filter($shiftRace, fn ($r) => $r['result'] === 'ok')) === 2, 'Same-key shift assignment race failed: '.json_encode($shiftRace));
check(ShiftAssignment::where('employee_profile_id', $employee->id)->where('idempotency_key', 'stress-shift')->count() === 1, 'Duplicate shift assignment saved');

$leaveType = LeaveType::create(['company_id' => $hr->company_id, 'code' => 'STRESS-'.bin2hex(random_bytes(3)), 'name' => 'Fictional stress leave', 'is_paid' => true, 'is_active' => true]);
LeavePolicy::create(['company_id' => $hr->company_id, 'leave_type_id' => $leaveType->id, 'location_id' => $location->id,
    'effective_from' => '2020-01-01', 'annual_units' => 40, 'allow_half_day' => true, 'allow_negative' => false,
    'approval_role' => 'hr', 'working_weekdays' => '1,2,3,4,5']);
$leave = app(LeaveService::class);
$leave->grant($hr, $employee, $leaveType, $shiftStart->year, 40, 'Fictional opening grant', 'stress-grant');
$shiftLeave = $leave->request($worker, $employee, $leaveType, $replacementStart->toDateString(), $replacementStart->addDay()->toDateString(), null, 'Fictional shift leave overlap', 'stress-shift-leave');
$expectedCharges = $shiftLeave->charges()->pluck('units', 'leave_year')->all();
$replacement = app(ShiftScheduleService::class)->createTemplate($hr, $location, ['code' => 'REPL-'.bin2hex(random_bytes(3)), 'name' => 'Fictional replacement shift',
    'starts_at_local' => '10:00', 'ends_at_local' => '18:00', 'unpaid_break_minutes' => 60, 'grace_in_minutes' => 10, 'grace_out_minutes' => 5]);
$shiftLeaveRace = race([
    ['action' => 'shift', 'actor' => $hr->id, 'target' => $employee->id, 'payload' => ['template_id' => $replacement->id, 'start' => $replacementStart->toDateString(), 'end' => $shiftEnd->toDateString(), 'weekdays' => [1, 2, 3, 4, 5], 'reason' => 'Fictional supersession', 'key' => 'stress-replacement', 'supersedes_id' => ShiftAssignment::where('idempotency_key', 'stress-shift')->sole()->id]],
    ['action' => 'leave_decide', 'actor' => $hr->id, 'target' => $shiftLeave->id, 'payload' => ['action' => 'approved', 'reason' => 'Fictional approval during shift change']],
]);
check(count(array_filter($shiftLeaveRace, fn ($r) => $r['result'] === 'ok')) === 2, 'Shift/leave race failed: '.json_encode($shiftLeaveRace));
check($shiftLeave->charges()->pluck('units', 'leave_year')->all() === $expectedCharges, 'Shift change altered approved leave charges');
check(LeaveBalanceEntry::where('leave_request_id', $shiftLeave->id)->where('kind', 'consumption')->count() === count($expectedCharges), 'Shift/leave race duplicated consumption');
$request = $leave->request($worker, $employee, $leaveType, $shiftStart->toDateString(), $shiftStart->addDay()->toDateString(), null, 'Fictional family visit', 'stress-leave');
$approval = ['action' => 'approved', 'reason' => 'Fictional coverage arranged'];
$leaveApprovalRace = race([
    ['action' => 'leave_decide', 'actor' => $hr->id, 'target' => $request->id, 'payload' => $approval],
    ['action' => 'leave_decide', 'actor' => $hr->id, 'target' => $request->id, 'payload' => $approval],
]);
check(count(array_filter($leaveApprovalRace, fn ($r) => $r['result'] === 'ok')) === 1, 'Leave approval race failed: '.json_encode($leaveApprovalRace));
check(LeaveBalanceEntry::where('leave_request_id', $request->id)->where('kind', 'consumption')->count() === $request->charges()->count(), 'Leave was consumed more than once');
$leaveCancellationRace = race([
    ['action' => 'leave_cancel', 'actor' => $hr->id, 'target' => $request->id, 'payload' => ['reason' => 'Fictional reversal']],
    ['action' => 'leave_cancel', 'actor' => $hr->id, 'target' => $request->id, 'payload' => ['reason' => 'Fictional reversal']],
]);
check(count(array_filter($leaveCancellationRace, fn ($r) => $r['result'] === 'ok')) === 1, 'Leave cancellation race failed: '.json_encode($leaveCancellationRace));
check(LeaveBalanceEntry::where('leave_request_id', $request->id)->where('kind', 'reversal')->count() === $request->charges()->count(), 'Leave was reversed more than once');

$packRace = race([
    ['action' => 'compliance_create', 'actor' => $hr->id, 'target' => 0, 'payload' => ['from' => '2026-07-01']],
    ['action' => 'compliance_create', 'actor' => $admin->id, 'target' => 0, 'payload' => ['from' => '2027-07-01']],
]);
check(count(array_filter($packRace, fn ($r) => $r['result'] === 'ok')) === 2, 'Pack version race failed: '.json_encode($packRace));
check(CompliancePack::where('company_id', $hr->company_id)->distinct()->count('version') === 2, 'Pack versions collided');
$pack = CompliancePack::where('company_id', $hr->company_id)->firstOrFail();
$activationRace = race([
    ['action' => 'compliance_activate', 'actor' => $hr->id, 'target' => $pack->id, 'payload' => []],
    ['action' => 'compliance_activate', 'actor' => $admin->id, 'target' => $pack->id, 'payload' => []],
]);
check(count(array_filter($activationRace, fn ($r) => $r['result'] === 'validation')) === 2, 'Unverified pack activation race failed: '.json_encode($activationRace));
check($pack->fresh()->status === 'draft' && $pack->events()->count() === 1, 'Unverified pack activation changed state');

$seconds = round(microtime(true) - $started, 3);
echo json_encode(['status' => 'passed', 'processes_per_race' => 2, 'approval_repetitions' => $approvalRounds,
    'payment_races' => 3, 'calculate_review_races' => 1, 'finalize_review_races' => 1, 'policy_races' => 1, 'compensation_races' => 1, 'attendance_punch_races' => 1,
    'attendance_correction_races' => 1, 'shift_races' => 1, 'shift_leave_races' => 1, 'leave_approval_races' => 1, 'leave_cancellation_races' => 1,
    'compliance_version_races' => 1, 'compliance_activation_denial_races' => 1, 'deadlocks_or_timeouts' => 0,
    'elapsed_seconds' => $seconds], JSON_THROW_ON_ERROR).PHP_EOL;
