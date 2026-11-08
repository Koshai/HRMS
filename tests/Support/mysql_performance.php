<?php

declare(strict_types=1);

use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Http\Controllers\PayrollController;
use App\Leave\LeaveService;
use App\Leave\LeaveType;
use App\Models\User;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use App\Payroll\PayrollPolicy;
use App\Payroll\PayrollService;
use App\Scheduling\AttendanceSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || app()->environment('production')) {
    throw new RuntimeException('Performance smoke requires a disposable non-production MySQL database.');
}

$queries = 0;
DB::listen(function () use (&$queries): void {
    $queries++;
});
function measure(string $name, callable $task): array
{
    global $queries;
    $before = $queries;
    $started = microtime(true);
    $result = $task();

    return ['workload' => $name, 'milliseconds' => round((microtime(true) - $started) * 1000, 1), 'queries' => $queries - $before,
        'process_peak_bytes' => memory_get_peak_usage(true), 'result' => $result];
}

$hr = User::factory()->create(['role' => Role::HR]);
Auth::login($hr);
$service = app(PayrollService::class);
if (! PayrollPolicy::where('company_id', $hr->company_id)->whereDate('effective_from', '<=', '2026-07-01')->whereDate('effective_to', '>=', '2026-09-30')->exists()) {
    $service->policy($hr, ['effective_from' => '2026-01-01', 'effective_to' => '2026-12-31', 'currency' => 'BDT', 'proration_basis' => 'calendar_days', 'reason' => 'Fictional performance policy']);
}
$department = Department::firstOrCreate(['company_id' => $hr->company_id, 'name' => 'Performance Operations']);
$position = Position::firstOrCreate(['company_id' => $hr->company_id, 'name' => 'Performance Officer']);
$location = Location::firstOrCreate(['company_id' => $hr->company_id, 'name' => 'Performance Dhaka'], ['timezone' => 'Asia/Dhaka']);
$leaveType = LeaveType::create(['company_id' => $hr->company_id, 'code' => 'PERF-'.bin2hex(random_bytes(3)), 'name' => 'Fictional performance leave', 'is_paid' => true, 'is_active' => true]);
$employees = [];
$results = [];
foreach ([5 => '2026-07-01', 20 => '2026-08-01', 40 => '2026-09-01', 100 => '2026-06-01'] as $size => $month) {
    while (count($employees) < $size) {
        $user = User::factory()->create();
        $employee = EmployeeProfile::create(['company_id' => $hr->company_id, 'user_id' => $user->id, 'employee_number' => 'PERF-'.bin2hex(random_bytes(5)), 'name' => 'Fictional Performance Staff '.(count($employees) + 1), 'started_on' => '2026-01-01']);
        app(AssignmentService::class)->assign($employee, $department, $position, $location, CarbonImmutable::parse('2026-01-01'), null, null);
        $service->compensation($hr, $employee, ['effective_from' => '2026-01-01', 'currency' => 'BDT', 'monthly_base_minor' => 100000, 'components' => [], 'reason' => 'Fictional performance salary']);
        $employees[] = $employee;
    }
    $run = $service->createRun($hr, $month, 'regular', array_map(fn (EmployeeProfile $e) => $e->id, $employees));
    $monthDate = CarbonImmutable::parse($month);
    $results[$size] = [
        measure('team_month_summaries', function () use ($employees, $monthDate): int {
            $days = 0;
            foreach ($employees as $employee) {
                $days += count(app(AttendanceSummaryService::class)->month($employee, $monthDate));
            }

            return $days;
        }),
        measure('leave_balances', function () use ($employees, $leaveType): int {
            foreach ($employees as $employee) {
                app(LeaveService::class)->balance($employee, $leaveType, 2026);
            }

            return count($employees);
        }),
        measure('payroll_calculate', function () use ($service, $hr, $run): int {
            $service->calculate($hr, $run);

            return $run->entries()->count();
        }),
        measure('attendance_review_scan', fn (): int => count($service->unresolved($run))),
        measure('reconciliation_csv', function () use ($hr, $run): int {
            $request = Request::create('/admin/payroll/runs/'.$run->id.'/export');
            $request->setUserResolver(fn () => $hr);
            ob_start();
            app(PayrollController::class)->export($request, $run)->sendContent();
            $csv = ob_get_clean();

            return substr_count((string) $csv, "\n") - 1;
        }),
    ];
}
echo json_encode(['database' => 'MySQL', 'staff_sizes' => [5, 20, 40, 100], 'results' => $results], JSON_THROW_ON_ERROR).PHP_EOL;
