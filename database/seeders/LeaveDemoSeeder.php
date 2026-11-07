<?php

namespace Database\Seeders;

use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Leave\LeaveHoliday;
use App\Leave\LeavePolicy;
use App\Leave\LeaveService;
use App\Leave\LeaveType;
use App\Models\User;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LeaveDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Fictional leave demo data may only be seeded in a local environment.');
        }

        DB::transaction(function () {
            $hr = User::query()->where('name', 'Farhana Islam')->firstOrFail();
            $manager = EmployeeProfile::query()->where('company_id', $hr->company_id)->where('employee_number', 'BD-0003')->firstOrFail();
            $worker = EmployeeProfile::query()->where('company_id', $hr->company_id)->where('employee_number', 'BD-0004')->firstOrFail();
            $dhaka = Location::query()->where('company_id', $hr->company_id)->where('name', 'Dhaka Office')->firstOrFail();
            $newYork = Location::query()->firstOrCreate(['company_id' => $hr->company_id, 'name' => 'New York Demo Office'], ['timezone' => 'America/New_York']);
            $visitor = EmployeeProfile::query()->firstOrCreate(['company_id' => $hr->company_id, 'employee_number' => 'DEMO-0005'],
                ['name' => 'Maya Chen (fictional)', 'status' => 'active', 'started_on' => '2026-01-01']);
            if (! $visitor->assignments()->exists()) {
                $department = Department::query()->where('company_id', $hr->company_id)->firstOrFail();
                $position = Position::query()->where('company_id', $hr->company_id)->firstOrFail();
                app(AssignmentService::class)->assign($visitor, $department, $position, $newYork, CarbonImmutable::parse('2026-01-01'));
            }
            $annual = LeaveType::query()->firstOrCreate(['company_id' => $hr->company_id, 'code' => 'ANNUAL'],
                ['name' => 'Annual leave (demo)', 'is_paid' => true, 'is_active' => true]);
            $sick = LeaveType::query()->firstOrCreate(['company_id' => $hr->company_id, 'code' => 'SICK'],
                ['name' => 'Sick leave (demo)', 'is_paid' => true, 'is_active' => true]);
            foreach ([
                [$annual, $dhaka, 40, '7,1,2,3,4', 'manager'],
                [$sick, $dhaka, 20, '7,1,2,3,4', 'hr'],
                [$annual, $newYork, 30, '1,2,3,4,5', 'hr'],
            ] as [$type, $location, $units, $weekdays, $approval]) {
                if (! LeavePolicy::query()->where('company_id', $hr->company_id)->where('leave_type_id', $type->id)
                    ->where('location_id', $location->id)->whereDate('effective_from', '2026-01-01')->exists()) {
                    LeavePolicy::create(['company_id' => $hr->company_id, 'leave_type_id' => $type->id,
                        'location_id' => $location->id, 'effective_from' => '2026-01-01',
                        'annual_units' => $units, 'allow_half_day' => true, 'allow_negative' => false,
                        'approval_role' => $approval, 'working_weekdays' => $weekdays]);
                }
            }
            foreach ([$dhaka, $newYork] as $location) {
                if (! LeaveHoliday::query()->where('company_id', $hr->company_id)->where('location_id', $location->id)
                    ->whereDate('holiday_on', '2026-11-26')->exists()) {
                    LeaveHoliday::create(['company_id' => $hr->company_id, 'location_id' => $location->id,
                        'holiday_on' => '2026-11-26', 'name' => 'Fictional '.$location->getAttribute('name').' holiday']);
                }
            }
            $service = app(LeaveService::class);
            foreach ([$manager, $worker] as $employee) {
                $service->grant($hr, $employee, $annual, 2026, 40, 'Fictional 2026 opening allocation', 'demo-annual-'.$employee->id);
                $service->grant($hr, $employee, $sick, 2026, 20, 'Fictional 2026 opening allocation', 'demo-sick-'.$employee->id);
            }
            $service->grant($hr, $visitor, $annual, 2026, 30, 'Fictional 2026 opening allocation', 'demo-annual-'.$visitor->id);
            $managerUser = $manager->user;
            $workerUser = $worker->user;
            if ($managerUser && $workerUser) {
                $approved = $service->request($workerUser, $worker, $annual, '2026-11-09', '2026-11-10', null,
                    'Fictional family visit', 'demo-approved');
                if ($approved->status === 'pending') {
                    $service->decide($managerUser, $approved, 'approved', 'Fictional team coverage confirmed');
                }
                $service->request($workerUser, $worker, $annual, '2026-11-16', '2026-11-16', null,
                    'Fictional personal appointment', 'demo-pending');
                $rejected = $service->request($workerUser, $worker, $sick, '2026-11-18', '2026-11-18', null,
                    'Fictional example request', 'demo-rejected');
                if ($rejected->status === 'pending') {
                    $service->decide($hr, $rejected, 'rejected', 'Fictional demo rejection');
                }
            }
        });
    }
}
