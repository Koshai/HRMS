<?php

namespace Database\Seeders;

use App\Employees\EmployeeProfile;
use App\Models\User;
use App\Scheduling\ShiftScheduleService;
use App\Scheduling\ShiftTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShiftDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Fictional shift demo data may only be seeded in a local environment.');
        }

        DB::transaction(function () {
            $hr = User::query()->where('name', 'Farhana Islam')->firstOrFail();
            $manager = EmployeeProfile::query()->where('company_id', $hr->company_id)->where('employee_number', 'BD-0003')->firstOrFail();
            $worker = EmployeeProfile::query()->where('company_id', $hr->company_id)->where('employee_number', 'BD-0004')->firstOrFail();
            $visitor = EmployeeProfile::query()->where('company_id', $hr->company_id)->where('employee_number', 'DEMO-0005')->firstOrFail();
            $service = app(ShiftScheduleService::class);
            foreach ([
                [$manager, 'DEMO-DAY-M', 'Manager day duty', '10:00', '18:00', [7, 1, 2, 3, 4]],
                [$worker, 'DEMO-DAY-E', 'Employee day duty', '09:00', '17:00', [7, 1, 2, 3, 4]],
                [$visitor, 'DEMO-NY', 'New York day duty', '09:00', '17:00', [1, 2, 3, 4, 5]],
            ] as [$employee, $code, $name, $start, $end, $weekdays]) {
                $location = $employee->assignments()->firstOrFail()->location;
                $template = ShiftTemplate::query()->where('company_id', $hr->company_id)->where('location_id', $location->id)
                    ->where('code', $code)->first();
                if (! $template) {
                    $template = $service->createTemplate($hr, $location, ['code' => $code, 'name' => $name,
                        'starts_at_local' => $start, 'ends_at_local' => $end, 'unpaid_break_minutes' => 30,
                        'grace_in_minutes' => 10, 'grace_out_minutes' => 5]);
                }
                $service->assign($hr, $employee, $template, '2026-11-08', null, $weekdays,
                    'Fictional demonstration schedule', 'demo-shift-'.$employee->id);
            }
        });
    }
}
