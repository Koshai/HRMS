<?php

namespace Tests\Feature;

use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Leave\LeaveBalanceEntry;
use App\Leave\LeaveDecision;
use App\Leave\LeaveHoliday;
use App\Leave\LeavePolicy;
use App\Leave\LeaveRequest;
use App\Leave\LeaveService;
use App\Leave\LeaveType;
use App\Models\User;
use App\Organization\Company;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function profile(User $user, string $number, ?EmployeeProfile $manager = null, string $locationName = 'Dhaka', string $timezone = 'Asia/Dhaka'): EmployeeProfile
    {
        $profile = EmployeeProfile::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'employee_number' => $number, 'name' => $user->name]);
        $department = Department::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Operations']);
        $position = Position::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Officer']);
        $location = Location::firstOrCreate(['company_id' => $user->company_id, 'name' => $locationName], ['timezone' => $timezone]);
        app(AssignmentService::class)->assign($profile, $department, $position, $location, CarbonImmutable::parse('2020-01-01'), null, $manager);

        return $profile;
    }

    private function setupLeave(User $hr, EmployeeProfile $employee, string $weekdays = '1,2,3,4,5', int $units = 40, ?string $policyEnd = null): LeaveType
    {
        $type = LeaveType::create(['company_id' => $hr->company_id, 'code' => 'ANNUAL', 'name' => 'Annual leave', 'is_paid' => true, 'is_active' => true]);
        LeavePolicy::create(['company_id' => $hr->company_id, 'leave_type_id' => $type->id,
            'location_id' => $employee->assignments()->first()->location_id, 'effective_from' => '2020-01-01', 'effective_to' => $policyEnd,
            'annual_units' => 40, 'allow_half_day' => true, 'allow_negative' => false,
            'approval_role' => 'manager', 'working_weekdays' => $weekdays]);
        app(LeaveService::class)->grant($hr, $employee, $type, 2026, $units, 'Opening allocation', 'opening-2026');

        return $type;
    }

    public function test_employee_request_reserves_approval_consumes_and_cancel_reverses_once(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $manager = User::factory()->create(['role' => Role::Manager]);
        $managerProfile = $this->profile($manager, 'M1');
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1', $managerProfile);
        $type = $this->setupLeave($hr, $employee);
        $service = app(LeaveService::class);
        $this->actingAs($worker)->get(route('leave.mine'))->assertOk()->assertSee('Annual leave');
        $payload = ['leave_type_id' => $type->id, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-10', 'idempotency_key' => 'request-1', 'reason' => 'Family visit'];
        $this->post(route('leave.store'), $payload)->assertRedirect();
        $this->post(route('leave.store'), $payload)->assertRedirect();
        $record = LeaveRequest::sole();
        $this->assertSame(4, $record->units);
        $this->assertSame(['granted' => 40, 'used' => 0, 'pending' => 4, 'available' => 36], $service->balance($employee, $type, 2026));
        $this->actingAs($manager)->get(route('leave.index'))->assertOk()->assertSee('Annual leave');
        $this->post(route('leave.decide', $record), ['action' => 'approved', 'reason' => 'Coverage arranged'])->assertRedirect();
        $this->assertSame(['granted' => 40, 'used' => 4, 'pending' => 0, 'available' => 36], $service->balance($employee, $type, 2026));
        $this->post(route('leave.decide', $record), ['action' => 'approved', 'reason' => 'Again'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('leave_balance_entries', 2);
        $this->actingAs($hr)->post(route('leave.cancel', $record), ['reason' => 'Trip postponed'])->assertRedirect();
        $this->post(route('leave.cancel', $record), ['reason' => 'Again'])->assertSessionHasErrors('action');
        $this->assertSame(['granted' => 40, 'used' => 0, 'pending' => 0, 'available' => 40], $service->balance($employee, $type, 2026));
        $this->assertDatabaseCount('leave_balance_entries', 3);
        $this->assertDatabaseCount('leave_decisions', 2);
        $this->expectException(\LogicException::class);
        LeaveDecision::first()->delete();
    }

    public function test_withdrawal_and_rejection_release_reservation_without_ledger_debit(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee);
        $service = app(LeaveService::class);
        $first = $service->request($worker, $employee, $type, '2026-11-09', '2026-11-09', null, null, 'one');
        $this->actingAs($worker)->post(route('leave.withdraw', $first), ['reason' => 'No longer needed'])->assertRedirect();
        $second = $service->request($worker, $employee, $type, '2026-11-09', '2026-11-09', null, null, 'two');
        $service->decide($hr, $second, 'rejected', 'Staffing');
        $this->assertSame(40, $service->balance($employee, $type, 2026)['available']);
        $this->assertDatabaseCount('leave_balance_entries', 1);
        $this->assertDatabaseCount('leave_decisions', 2);
    }

    public function test_overlap_half_day_holiday_and_non_bangladesh_workweek(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1', null, 'New York', 'America/New_York');
        $type = $this->setupLeave($hr, $employee, '2,3,4,5,6');
        $service = app(LeaveService::class);
        $this->expectValidation(fn () => $service->request($worker, $employee, $type, '2026-11-08', '2026-11-08', null, null, 'sun'));
        LeaveHoliday::create(['company_id' => $hr->company_id, 'location_id' => $employee->assignments()->first()->location_id,
            'holiday_on' => '2026-11-10', 'name' => 'Local holiday']);
        $this->expectValidation(fn () => $service->request($worker, $employee, $type, '2026-11-10', '2026-11-10', null, null, 'holiday'));
        $am = $service->request($worker, $employee, $type, '2026-11-11', '2026-11-11', 'am', null, 'am');
        $pm = $service->request($worker, $employee, $type, '2026-11-11', '2026-11-11', 'pm', null, 'pm');
        $this->assertSame(1, $am->units);
        $this->assertSame(1, $pm->units);
        $this->expectValidation(fn () => $service->request($worker, $employee, $type, '2026-11-11', '2026-11-11', 'am', null, 'duplicate'));
        $this->expectValidation(fn () => $service->request($worker, $employee, $type, '2026-11-11', '2026-11-12', null, null, 'overlap'));
        $this->assertSame(38, $service->balance($employee, $type, 2026)['available']);
    }

    public function test_cross_year_charges_and_policy_version_remains_referenced(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee);
        $service = app(LeaveService::class);
        $service->grant($hr, $employee, $type, 2027, 20, 'Opening allocation', 'opening-2027');
        $record = $service->request($worker, $employee, $type, '2026-12-31', '2027-01-01', null, null, 'cross');
        $this->assertSame(2, $record->charges()->count());
        $this->assertSame(38, $service->balance($employee, $type, 2026)['available']);
        $this->assertSame(18, $service->balance($employee, $type, 2027)['available']);
        $service->decide($hr, $record, 'approved', 'Covered');
        $this->assertSame(2, $record->charges()->count());
        $this->assertSame(2, LeaveBalanceEntry::where('kind', 'consumption')->count());
    }

    public function test_policy_conflict_and_location_scoping(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee);
        $service = app(LeaveService::class);
        $this->expectValidation(fn () => $service->createPolicy($hr, $type, $employee->assignments()->first()->location_id,
            ['effective_from' => '2020-01-01', 'effective_to' => '2026-12-31', 'annual_units' => 40,
                'allow_half_day' => true, 'allow_negative' => false, 'approval_role' => 'manager', 'working_weekdays' => [1, 2, 3, 4, 5]]));
        $other = Location::create(['company_id' => $hr->company_id, 'name' => 'Chattogram', 'timezone' => 'Asia/Dhaka']);
        $new = $service->createPolicy($hr, $type, $other->id, ['effective_from' => '2026-01-01', 'annual_units' => 30,
            'allow_half_day' => false, 'allow_negative' => false, 'approval_role' => 'hr', 'working_weekdays' => [7, 1, 2, 3, 4]]);
        $this->assertSame('1,2,3,4,7', $new->working_weekdays);
    }

    public function test_negative_authorization_and_company_isolation(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee);
        $service = app(LeaveService::class);
        $record = $service->request($worker, $employee, $type, '2026-11-09', '2026-11-09', null, null, 'one');
        $otherManager = User::factory()->create(['role' => Role::Manager]);
        $this->actingAs($otherManager)->get(route('leave.index'))->assertOk()->assertDontSee($employee->name);
        $this->post(route('leave.decide', $record), ['action' => 'approved', 'reason' => 'Unauthorized'])->assertForbidden();
        $this->actingAs($worker)->post(route('leave.decide', $record), ['action' => 'approved', 'reason' => 'Self'])->assertForbidden();
        $this->post(route('admin.leave.grants'), [])->assertForbidden();
        $this->expectAuthorization(fn () => $service->grant($worker, $employee, $type, 2026, 2, 'Unauthorized', 'x'));
        $company = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $otherHr = User::factory()->create(['company_id' => $company->id, 'role' => Role::HR]);
        $this->actingAs($otherHr)->get(route('leave.index'))->assertOk()->assertDontSee($employee->name);
        $this->post(route('leave.decide', $record), ['action' => 'approved', 'reason' => 'Other company'])->assertNotFound();
        $this->post(route('admin.leave.grants'), ['employee_profile_id' => $employee->id, 'leave_type_id' => $type->id,
            'leave_year' => 2026, 'units' => 2, 'kind' => 'grant', 'reason' => 'Other company', 'idempotency_key' => 'x'])->assertNotFound();
    }

    public function test_own_request_cannot_be_approved_after_role_change(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee);
        $record = app(LeaveService::class)->request($worker, $employee, $type, '2026-11-09', '2026-11-09', null, null, 'one');
        $worker->update(['role' => Role::HR]);
        $this->actingAs($worker)->post(route('leave.decide', $record), ['action' => 'approved', 'reason' => 'Self'])->assertForbidden();
    }

    public function test_unverified_inactive_and_unlinked_accounts_cannot_request(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee);
        $payload = ['leave_type_id' => $type->id, 'starts_on' => '2026-11-09', 'ends_on' => '2026-11-09', 'idempotency_key' => 'one'];
        $unlinked = User::factory()->create();
        $this->actingAs($unlinked)->get(route('leave.mine'))->assertOk()->assertSee(__('leave.unlinked'));
        $this->post(route('leave.store'), $payload)->assertNotFound();
        $employee->update(['status' => 'inactive']);
        $this->actingAs($worker)->post(route('leave.store'), $payload)->assertRedirect(route('login'));
        $employee->update(['status' => 'active']);
        $worker->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($worker->fresh())->post(route('leave.store'), $payload)->assertRedirect(route('verification.notice'));
    }

    public function test_sql_tenant_constraints_and_ledger_immutability(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee);
        $company = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $this->expectQueryFailure(fn () => LeaveBalanceEntry::create(['company_id' => $company->id, 'employee_profile_id' => $employee->id,
            'leave_type_id' => $type->id, 'actor_user_id' => $hr->id, 'leave_year' => 2026, 'units' => 2,
            'kind' => 'grant', 'source_key' => 'forged', 'reason' => 'Bad tenant']));
        $this->expectException(\LogicException::class);
        LeaveBalanceEntry::first()->delete();
    }

    public function test_grant_limit_adjustment_and_retry_integrity(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee, '1,2,3,4,5', 20);
        $service = app(LeaveService::class);
        $service->grant($hr, $employee, $type, 2026, 20, 'Second allocation', 'grant-2');
        $this->expectValidation(fn () => $service->grant($hr, $employee, $type, 2026, 2, 'Too much', 'grant-3'));
        $entry = $service->grant($hr, $employee, $type, 2026, 4, 'Approved exception', 'adjust-1', 'adjustment');
        $this->assertSame($entry->id, $service->grant($hr, $employee, $type, 2026, 4, 'Approved exception', 'adjust-1', 'adjustment')->id);
        $this->expectValidation(fn () => $service->grant($hr, $employee, $type, 2026, 8, 'Changed payload', 'adjust-1', 'adjustment'));
        $this->expectValidation(fn () => $service->grant($hr, $employee, $type, 2026, -46, 'Overdraft', 'adjust-2', 'adjustment'));
        $this->assertSame(44, $service->balance($employee, $type, 2026)['available']);
        $this->assertNotNull($entry->leave_policy_id);
    }

    public function test_hr_settings_forms_and_tampered_request_fields(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $this->actingAs($hr)->get(route('admin.leave.index'))->assertOk()->assertSee(__('leave.new_type'));
        $this->post(route('admin.leave.types'), ['code' => 'SICK', 'name' => 'Sick leave', 'is_paid' => 1])->assertRedirect();
        $type = LeaveType::sole();
        $this->post(route('admin.leave.policies'), ['leave_type_id' => $type->id, 'location_id' => $employee->assignments()->first()->location_id,
            'effective_from' => '2026-01-01', 'annual_units' => 20, 'allow_half_day' => 1, 'allow_negative' => 0,
            'approval_role' => 'hr', 'working_weekdays' => [1, 2, 3, 4, 5]])->assertRedirect();
        $this->post(route('admin.leave.grants'), ['employee_profile_id' => $employee->id, 'leave_type_id' => $type->id,
            'leave_year' => 2026, 'units' => 20, 'kind' => 'grant', 'reason' => 'Opening allocation', 'idempotency_key' => 'sick-1'])->assertRedirect();
        $this->actingAs($worker)->post(route('leave.store'), ['leave_type_id' => $type->id, 'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-09', 'idempotency_key' => 'tampered', 'company_id' => 999, 'employee_profile_id' => 999])->assertSessionHasErrors(['company_id', 'employee_profile_id']);
        $this->post(route('leave.store'), ['leave_type_id' => $type->id, 'starts_on' => '2026-11-09',
            'ends_on' => '2026-11-09', 'idempotency_key' => 'valid'])->assertRedirect();
        $this->assertDatabaseCount('leave_requests', 1);
    }

    public function test_policy_transition_combines_same_year_charges_and_requires_one_approval_route(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee, '1,2,3,4,5', 2, '2026-06-30');
        $secondPolicy = LeavePolicy::create(['company_id' => $hr->company_id, 'leave_type_id' => $type->id,
            'location_id' => $employee->assignments()->first()->location_id, 'effective_from' => '2026-07-01', 'effective_to' => '2026-07-02',
            'annual_units' => 40, 'allow_half_day' => true, 'allow_negative' => false,
            'approval_role' => 'manager', 'working_weekdays' => '1,2,3,4,5']);
        $service = app(LeaveService::class);
        $this->expectValidation(fn () => $service->request($worker, $employee, $type, '2026-06-30', '2026-07-01', null, null, 'too-many'));
        $service->grant($hr, $employee, $type, 2026, 2, 'Additional grant', 'extra');
        $record = $service->request($worker, $employee, $type, '2026-06-30', '2026-07-01', null, null, 'valid');
        $this->assertSame(2, $record->charges()->count());
        LeavePolicy::create(['company_id' => $hr->company_id, 'leave_type_id' => $type->id,
            'location_id' => $employee->assignments()->first()->location_id, 'effective_from' => '2026-07-03',
            'annual_units' => 40, 'allow_half_day' => true, 'allow_negative' => false,
            'approval_role' => 'hr', 'working_weekdays' => '1,2,3,4,5']);
        $this->expectValidation(fn () => $service->request($worker, $employee, $type, '2026-07-02', '2026-07-03', null, null, 'route-mismatch'));
        $this->expectException(\LogicException::class);
        $secondPolicy->delete();
    }

    public function test_new_effective_policy_closes_open_version_without_changing_existing_request_charge(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $type = $this->setupLeave($hr, $employee);
        $service = app(LeaveService::class);
        $request = $service->request($worker, $employee, $type, '2026-11-09', '2026-11-09', null, null, 'old-policy');
        $oldPolicyId = $request->charges()->first()->leave_policy_id;
        $new = $service->createPolicy($hr, $type, $employee->assignments()->first()->location_id,
            ['effective_from' => '2027-01-01', 'annual_units' => 30, 'allow_half_day' => false,
                'allow_negative' => false, 'approval_role' => 'hr', 'working_weekdays' => [1, 2, 3, 4, 5]]);
        $this->assertSame('2026-12-31', LeavePolicy::findOrFail($oldPolicyId)->effective_to->toDateString());
        $this->assertSame($oldPolicyId, $request->charges()->first()->leave_policy_id);
        $this->assertNotSame($oldPolicyId, $new->id);
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a validation error.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    private function expectAuthorization(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected authorization failure.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function expectQueryFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected database constraint failure.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
