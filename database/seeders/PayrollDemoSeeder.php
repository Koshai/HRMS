<?php

namespace Database\Seeders;

use App\Employees\EmployeeProfile;
use App\Models\User;
use App\Payroll\CompensationAgreement;
use App\Payroll\PayrollPolicy;
use App\Payroll\PayrollService;
use Illuminate\Database\Seeder;
use RuntimeException;

class PayrollDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Fictional payroll demo data may only be seeded in a local environment.');
        }

        $hr = User::query()->where('name', 'Farhana Islam')->firstOrFail();
        $employee = EmployeeProfile::query()->where('company_id', $hr->company_id)->where('employee_number', 'BD-0004')->firstOrFail();
        $service = app(PayrollService::class);

        if (! PayrollPolicy::query()->where('company_id', $hr->company_id)->exists()) {
            $service->policy($hr, ['effective_from' => '2026-01-01', 'effective_to' => null, 'currency' => 'BDT', 'proration_basis' => 'calendar_days', 'reason' => 'Fictional demonstration only; legal review required']);
        }
        if (! CompensationAgreement::query()->where('company_id', $hr->company_id)->where('employee_profile_id', $employee->id)->exists()) {
            $service->compensation($hr, $employee, ['effective_from' => '2026-01-01', 'effective_to' => null, 'currency' => 'BDT',
                'monthly_base_minor' => 3500000, 'components' => [['name' => 'Fictional transport allowance', 'side' => 'earning', 'amount_minor' => 250000]],
                'reason' => 'Fictional demonstration salary, not a real wage or legal rate']);
        }
        $run = $service->createRun($hr, '2026-10-01', 'regular', [$employee->id]);
        if ($run->status === 'draft') {
            $service->calculate($run);
        }
    }
}
