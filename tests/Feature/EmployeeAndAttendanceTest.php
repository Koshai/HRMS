<?php

namespace Tests\Feature;

use App\Attendance\AttendanceRecord;
use App\Attendance\AttendanceService;
use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use App\Organization\Company;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeAndAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private function profile(User $user, string $number): EmployeeProfile
    {
        return EmployeeProfile::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'employee_number' => $number, 'name' => $user->name]);
    }

    public function test_directory_and_profile_respect_reporting_scope(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $report = User::factory()->create();
        $other = User::factory()->create();
        $managerProfile = $this->profile($manager, 'M001');
        $reportProfile = $this->profile($report, 'E001');
        $otherProfile = $this->profile($other, 'E002');
        $department = Department::create(['company_id' => $manager->company_id, 'name' => 'Operations']);
        $position = Position::create(['company_id' => $manager->company_id, 'name' => 'Officer']);
        $location = Location::create(['company_id' => $manager->company_id, 'name' => 'Dhaka']);
        app(AssignmentService::class)->assign($reportProfile, $department, $position, $location, CarbonImmutable::parse('2020-01-01'), null, $managerProfile);

        $this->actingAs($manager)->get(route('employees.index'))->assertOk()->assertSee('E001')->assertDontSee('E002');
        $this->get(route('employees.show', $reportProfile))->assertOk();
        $this->get(route('employees.show', $otherProfile))->assertForbidden();
        $this->actingAs($other)->get(route('employees.show', $reportProfile))->assertForbidden();
        $this->get(route('employees.show', $otherProfile))->assertOk();
    }

    public function test_assignments_cannot_overlap(): void
    {
        $user = User::factory()->create();
        $employee = $this->profile($user, 'E001');
        $department = Department::create(['company_id' => $user->company_id, 'name' => 'Operations']);
        $position = Position::create(['company_id' => $user->company_id, 'name' => 'Officer']);
        $location = Location::create(['company_id' => $user->company_id, 'name' => 'Dhaka']);
        $service = app(AssignmentService::class);
        $service->assign($employee, $department, $position, $location, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31'));
        $this->expectException(ValidationException::class);
        $service->assign($employee, $department, $position, $location, CarbonImmutable::parse('2026-01-31'));
    }

    public function test_company_boundary_blocks_profile_and_attendance_access(): void
    {
        $otherCompany = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $otherProfile = $this->profile($otherUser, 'E001');

        $this->actingAs($admin)->get(route('employees.show', $otherProfile))->assertNotFound();
        $this->expectException(AuthorizationException::class);
        app(AttendanceService::class)->record($admin, $otherProfile, 'check_in', CarbonImmutable::now('UTC'), 'other-company');
    }

    public function test_attendance_preserves_provenance_retries_and_correction(): void
    {
        $employeeUser = User::factory()->create();
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = $this->profile($employeeUser, 'E001');
        $service = app(AttendanceService::class);
        $instant = CarbonImmutable::parse('2026-11-07 18:30:00', 'UTC');
        $original = $service->record($employeeUser, $employee, 'check_in', $instant, 'entry-1');
        $this->assertSame('2026-11-08', $original->business_date->toDateString());
        $this->assertSame('employee_self', $original->source);
        $this->assertSame($original->id, $service->record($employeeUser, $employee, 'check_in', $instant, 'entry-1')->id);
        $correction = $service->record($hr, $employee, 'check_in', $instant->addMinutes(5), 'correction-1', $original, 'Clock was five minutes late');
        $this->assertSame('pending', $correction->status);
        $this->assertSame('hr_manual', $correction->source);
        $this->assertSame($original->id, $correction->correction_of_id);
        $this->assertSame('recorded', $original->fresh()->status);
        $this->assertSame(2, AttendanceRecord::count());
    }
}
