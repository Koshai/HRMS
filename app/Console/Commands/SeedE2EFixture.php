<?php

namespace App\Console\Commands;

use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Leave\LeavePolicy;
use App\Leave\LeaveService;
use App\Leave\LeaveType;
use App\Models\User;
use App\Organization\Company;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SeedE2EFixture extends Command
{
    protected $signature = 'hrms:e2e-fixture';

    protected $description = 'Seed a new, isolated fictional E2E database';

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $isolated = config('database.default') === 'mysql' && $database === 'hrms_e2e_test'
            || config('database.default') === 'sqlite' && preg_match('~/\.hrms-e2e/run-[A-Za-z0-9]+/hrms-e2e-test\.sqlite$~', str_replace('\\', '/', $database));
        $password = config('e2e.password');
        if (! app()->environment('testing') || ! $isolated || ! is_string($password) || strlen($password) < 16
            || config('mail.default') !== 'log' || config('mail.mailers.log.channel') !== 'e2e_mail'
            || config('compliance.allow_statutory_activation') !== false) {
            $this->error('E2E fixture denied: isolated testing DB, captured mail and disabled statutory activation are required.');

            return self::FAILURE;
        }
        if (User::exists() || Company::count() !== 1 || Company::first()?->id !== Company::INSTALLATION_ID) {
            $this->error('E2E fixture denied: the database must contain only the installation company.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($password) {
            $foreign = new Company;
            $foreign->forceFill(['public_id' => (string) Str::uuid()])->save();
            foreach ([
                ['admin', Role::Admin, 1], ['hr', Role::HR, 1], ['manager', Role::Manager, 1],
                ['employee', Role::Employee, 1], ['foreign-hr', Role::HR, $foreign->id],
            ] as [$name, $role, $companyId]) {
                $user = new User(['name' => 'Fictional '.ucfirst($name), 'email' => $name.'@hrms-e2e.example.invalid', 'password' => $password]);
                $user->company_id = $companyId;
                $user->role = $role;
                $user->email_verified_at = Carbon::now();
                $user->save();
                EmployeeProfile::create(['company_id' => $companyId, 'user_id' => $user->id,
                    'employee_number' => 'E2E-'.strtoupper($name), 'name' => $user->name]);
            }
            $manager = EmployeeProfile::where('employee_number', 'E2E-MANAGER')->sole();
            $employee = EmployeeProfile::where('employee_number', 'E2E-EMPLOYEE')->sole();
            $hr = User::where('email', 'hr@hrms-e2e.example.invalid')->sole();
            $department = Department::create(['company_id' => 1, 'name' => 'Fictional Operations']);
            $position = Position::create(['company_id' => 1, 'name' => 'Fictional Officer']);
            $location = Location::create(['company_id' => 1, 'name' => 'Fictional Dhaka', 'timezone' => 'Asia/Dhaka']);
            app(AssignmentService::class)->assign($manager, $department, $position, $location, CarbonImmutable::parse('2020-01-01'));
            app(AssignmentService::class)->assign($employee, $department, $position, $location, CarbonImmutable::parse('2020-01-01'), null, $manager);
            $type = LeaveType::create(['company_id' => 1, 'code' => 'E2E-ANNUAL', 'name' => 'Fictional Annual Leave', 'is_paid' => true, 'is_active' => true]);
            LeavePolicy::create(['company_id' => 1, 'leave_type_id' => $type->id, 'location_id' => $location->id,
                'effective_from' => '2020-01-01', 'annual_units' => 40, 'allow_half_day' => true, 'allow_negative' => false,
                'approval_role' => 'manager', 'working_weekdays' => '1,2,3,4,5']);
            app(LeaveService::class)->grant($hr, $employee, $type, (int) now()->year, 40, 'Fictional E2E opening allocation', 'e2e-opening');
            app(LeaveService::class)->grant($hr, $employee, $type, (int) now()->year + 1, 40, 'Fictional E2E next-year allocation', 'e2e-next-year');
            EmployeeProfile::create(['company_id' => 1, 'employee_number' => 'E2E-PENDING', 'name' => 'Fictional Pending Joiner']);
        });

        if (! Hash::check($password, User::where('email', 'hr@hrms-e2e.example.invalid')->sole()->password)) {
            throw new \RuntimeException('E2E fixture password was not stored correctly.');
        }

        $this->info('Fictional E2E fixtures created for two companies.');

        return self::SUCCESS;
    }
}
