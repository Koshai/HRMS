<?php

namespace Tests\Feature;

use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use App\Organization\Company;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_manages_organization_and_inactive_references_are_rejected(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $this->actingAs($hr)->post(route('admin.organization.store', 'departments'), ['name' => 'People', 'is_active' => 1])->assertRedirect();
        $department = Department::sole();
        $this->put(route('admin.organization.update', ['departments', $department->id]), ['name' => 'People Services', 'is_active' => 0])->assertRedirect();
        $this->assertDatabaseHas('departments', ['id' => $department->id, 'is_active' => 0]);
        $this->post(route('admin.organization.store', 'departments'), ['name' => 'People Services', 'is_active' => 1])->assertSessionHasErrors('name');
        $this->post(route('admin.organization.store', 'locations'), ['name' => 'Dhaka', 'timezone' => 'Not/AZone', 'is_active' => 1])->assertSessionHasErrors('timezone');
        $this->get(route('admin.organization.index', 'departments'))->assertOk()->assertSee('People Services');
    }

    public function test_admin_creates_edits_and_invites_employee_without_password(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->actingAs($admin)->post(route('admin.employees.store'), ['name' => 'Demo Worker', 'employee_number' => 'E-100', 'status' => 'active', 'started_on' => '2026-01-01'])->assertRedirect();
        $profile = EmployeeProfile::sole();
        $this->assertNull($profile->user_id);
        $this->post(route('admin.employees.store'), ['name' => 'Duplicate', 'employee_number' => 'E-100', 'status' => 'active'])->assertSessionHasErrors('employee_number');
        $this->put(route('admin.employees.update', $profile), ['name' => 'Updated Worker', 'employee_number' => 'E-100', 'status' => 'active', 'started_on' => '2026-01-01'])->assertRedirect();
        $this->post(route('admin.employees.provision', $profile), ['email' => 'WORKER@EXAMPLE.COM', 'role' => 'employee'])->assertRedirect();
        $linked = $profile->fresh()->user;
        $this->assertSame('worker@example.com', $linked->email);
        $this->assertFalse($linked->hasVerifiedEmail());
        $this->assertNotSame('worker@example.com', $linked->password);
        $this->post(route('admin.employees.provision', $profile), ['email' => 'other@example.com', 'role' => 'employee'])->assertSessionHasErrors('email');
    }

    public function test_transfer_preserves_history_and_changes_manager_scope(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $managerA = User::factory()->create(['role' => Role::Manager]);
        $managerB = User::factory()->create(['role' => Role::Manager]);
        $worker = User::factory()->create();
        $a = EmployeeProfile::create(['company_id' => 1, 'user_id' => $managerA->id, 'employee_number' => 'M-1', 'name' => 'Manager A']);
        $b = EmployeeProfile::create(['company_id' => 1, 'user_id' => $managerB->id, 'employee_number' => 'M-2', 'name' => 'Manager B']);
        $employee = EmployeeProfile::create(['company_id' => 1, 'user_id' => $worker->id, 'employee_number' => 'E-1', 'name' => 'Worker']);
        $department = Department::create(['company_id' => 1, 'name' => 'Operations']);
        $position = Position::create(['company_id' => 1, 'name' => 'Officer']);
        $location = Location::create(['company_id' => 1, 'name' => 'Dhaka', 'timezone' => 'Asia/Dhaka']);
        $payload = ['department_id' => $department->id, 'position_id' => $position->id, 'location_id' => $location->id];
        $this->actingAs($hr)->post(route('admin.employees.assign', $employee), $payload + ['manager_employee_profile_id' => $a->id, 'starts_on' => '2020-01-01'])->assertRedirect();
        $this->actingAs($managerA)->get(route('employees.show', $employee))->assertOk();
        $this->actingAs($hr)->post(route('admin.employees.assign', $employee), $payload + ['manager_employee_profile_id' => $b->id, 'starts_on' => now()->subDay()->toDateString()])->assertRedirect();
        $this->assertSame(2, $employee->assignments()->count());
        $this->assertNotNull($employee->assignments()->oldest('starts_on')->first()->ends_on);
        $this->actingAs($managerA)->get(route('employees.show', $employee))->assertForbidden();
        $this->actingAs($managerB)->get(route('employees.show', $employee))->assertOk();
    }

    public function test_admin_routes_deny_other_roles_guests_unverified_and_cross_company(): void
    {
        $this->get(route('admin.employees.create'))->assertRedirect(route('login'));
        $employee = User::factory()->create();
        $manager = User::factory()->create(['role' => Role::Manager]);
        $unverified = User::factory()->unverified()->create(['role' => Role::HR]);
        foreach ([$employee, $manager] as $actor) {
            $this->actingAs($actor)->get(route('admin.employees.create'))->assertForbidden();
            $this->post(route('admin.organization.store', 'departments'), ['name' => 'Blocked', 'is_active' => 1])->assertForbidden();
        }
        $this->actingAs($unverified)->get(route('admin.employees.create'))->assertRedirect(route('verification.notice'));

        $otherCompany = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $other = EmployeeProfile::create(['company_id' => $otherCompany->id, 'employee_number' => 'E-1', 'name' => 'Other']);
        $hr = User::factory()->create(['role' => Role::HR]);
        $this->actingAs($hr)->get(route('admin.employees.edit', $other))->assertNotFound();
        $this->put(route('admin.employees.update', $other), ['name' => 'Changed', 'employee_number' => 'E-1', 'status' => 'active'])->assertNotFound();
        $this->post(route('admin.employees.provision', $other), ['email' => 'other@example.com', 'role' => 'employee'])->assertNotFound();
        $this->assertDatabaseHas('employee_profiles', ['id' => $other->id, 'name' => 'Other']);
    }

    public function test_cross_company_and_inactive_assignment_references_are_rejected(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = EmployeeProfile::create(['company_id' => 1, 'employee_number' => 'E-1', 'name' => 'Worker']);
        $department = Department::create(['company_id' => 1, 'name' => 'Active']);
        $position = Position::create(['company_id' => 1, 'name' => 'Officer']);
        $location = Location::create(['company_id' => 1, 'name' => 'Dhaka']);
        $otherCompany = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $foreign = Department::create(['company_id' => $otherCompany->id, 'name' => 'Foreign']);
        $payload = ['position_id' => $position->id, 'location_id' => $location->id, 'starts_on' => '2026-01-01'];
        $this->actingAs($hr)->post(route('admin.employees.assign', $employee), $payload + ['department_id' => $foreign->id])->assertSessionHasErrors('department_id');
        $department->update(['is_active' => false]);
        $this->post(route('admin.employees.assign', $employee), $payload + ['department_id' => $department->id])->assertSessionHasErrors('department_id');
        $this->put(route('admin.organization.update', ['departments', $foreign->id]), ['name' => 'Changed', 'is_active' => 1])->assertNotFound();
        $this->assertDatabaseCount('employment_assignments', 0);
    }

    public function test_hr_cannot_invite_an_admin_and_forms_render_in_bangla(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = EmployeeProfile::create(['company_id' => 1, 'employee_number' => 'E-1', 'name' => 'Worker']);
        app()->setLocale('bn');
        $this->actingAs($hr)->get(route('admin.organization.index', 'departments'))->assertOk()->assertSee('বিভাগসমূহ');
        $this->get(route('admin.employees.create'))->assertOk()->assertSee('নতুন কর্মী');
        $this->get(route('admin.employees.assignment', $employee))->assertOk()->assertSee('দায়িত্ব বা বদলি');
        $this->post(route('admin.employees.provision', $employee), ['email' => 'worker@example.com', 'role' => 'admin'])->assertForbidden();
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_reporting_loop_and_invalid_transfer_date_preserve_existing_history(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $a = EmployeeProfile::create(['company_id' => 1, 'employee_number' => 'A', 'name' => 'A']);
        $b = EmployeeProfile::create(['company_id' => 1, 'employee_number' => 'B', 'name' => 'B']);
        $department = Department::create(['company_id' => 1, 'name' => 'Operations']);
        $position = Position::create(['company_id' => 1, 'name' => 'Officer']);
        $location = Location::create(['company_id' => 1, 'name' => 'Dhaka']);
        $payload = ['department_id' => $department->id, 'position_id' => $position->id, 'location_id' => $location->id];
        $this->actingAs($hr)->post(route('admin.employees.assign', $b), $payload + ['manager_employee_profile_id' => $a->id, 'starts_on' => '2020-01-01'])->assertRedirect();
        $this->post(route('admin.employees.assign', $a), $payload + ['manager_employee_profile_id' => $b->id, 'starts_on' => '2020-01-01'])->assertSessionHasErrors('manager');
        $this->post(route('admin.employees.assign', $b), $payload + ['starts_on' => '2020-01-01'])->assertSessionHasErrors('starts_on');
        $this->assertDatabaseCount('employment_assignments', 1);
        $this->assertNull($b->assignments()->first()->ends_on);
    }

    public function test_inactive_employee_cannot_sign_in_or_use_existing_session(): void
    {
        $password = Str::password(24);
        $user = User::factory()->create(['password' => $password]);
        $profile = EmployeeProfile::create(['company_id' => 1, 'user_id' => $user->id, 'employee_number' => 'E-1', 'name' => 'Worker', 'status' => 'inactive']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $password])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $profile->update(['status' => 'active']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $password])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_assignment_correction_is_audited_and_scoped(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = EmployeeProfile::create(['company_id' => 1, 'employee_number' => 'E-1', 'name' => 'Worker']);
        $old = Department::create(['company_id' => 1, 'name' => 'Old']);
        $new = Department::create(['company_id' => 1, 'name' => 'New']);
        $position = Position::create(['company_id' => 1, 'name' => 'Officer']);
        $location = Location::create(['company_id' => 1, 'name' => 'Dhaka']);
        $assignment = app(AssignmentService::class)->assign($employee, $old, $position, $location, CarbonImmutable::parse('2020-01-01'));
        $payload = ['department_id' => $new->id, 'position_id' => $position->id, 'location_id' => $location->id, 'starts_on' => '2020-01-01'];
        $this->actingAs($hr)->get(route('admin.employees.assignment.edit', [$employee, $assignment]))->assertOk();
        $this->put(route('admin.employees.assignment.update', [$employee, $assignment]), $payload)->assertRedirect();
        $this->assertSame($new->id, $assignment->fresh()->department_id);
        $this->assertDatabaseCount('assignment_revisions', 1);
        $revision = DB::table('assignment_revisions')->sole();
        $this->assertSame($hr->id, $revision->actor_user_id);
        $this->assertSame($old->id, json_decode($revision->before_values, true)['department_id']);
        $this->assertSame($new->id, json_decode($revision->after_values, true)['department_id']);

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->put(route('admin.employees.assignment.update', [$employee, $assignment]), $payload)->assertForbidden();
        $otherCompany = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id, 'role' => Role::HR]);
        $this->actingAs($otherUser)->get(route('admin.employees.assignment.edit', [$employee, $assignment]))->assertNotFound();
    }

    public function test_hr_cannot_disable_admin_and_admin_cannot_disable_self(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $hr = User::factory()->create(['role' => Role::HR]);
        $profile = EmployeeProfile::create(['company_id' => 1, 'user_id' => $admin->id, 'employee_number' => 'A-1', 'name' => 'Admin']);
        $payload = ['name' => 'Admin', 'employee_number' => 'A-1', 'status' => 'inactive'];
        $this->actingAs($hr)->put(route('admin.employees.update', $profile), $payload)->assertForbidden();
        $this->actingAs($admin)->put(route('admin.employees.update', $profile), $payload)->assertSessionHasErrors('status');
        $this->assertSame('active', $profile->fresh()->status);
    }
}
