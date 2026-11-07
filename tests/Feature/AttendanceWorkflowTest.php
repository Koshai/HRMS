<?php

namespace Tests\Feature;

use App\Attendance\AttendanceDecision;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function employee(?User $user = null, string $timezone = 'Asia/Dhaka', string $number = 'E-1'): EmployeeProfile
    {
        $user ??= User::factory()->create();
        $profile = EmployeeProfile::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'employee_number' => $number, 'name' => $user->name]);
        $department = Department::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Operations']);
        $position = Position::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Officer']);
        $location = Location::firstOrCreate(['company_id' => $user->company_id, 'name' => $timezone], ['timezone' => $timezone]);
        app(AssignmentService::class)->assign($profile, $department, $position, $location, CarbonImmutable::parse('2020-01-01'));

        return $profile->fresh();
    }

    public function test_employee_punch_uses_server_time_and_retries_without_duplicate(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 18:30:00 UTC');
        $user = User::factory()->create();
        $profile = $this->employee($user);
        $this->actingAs($user)->get(route('attendance.mine'))->assertOk()->assertSee(__('attendance.check_in'));
        $this->post(route('attendance.punch'), ['action' => 'check_out', 'idempotency_key' => 'out-too-early'])->assertSessionHasErrors('action');
        $this->post(route('attendance.punch'), ['action' => 'check_in', 'idempotency_key' => 'spoof', 'occurred_at' => '2020-01-01'])->assertSessionHasErrors('occurred_at');
        $this->post(route('attendance.punch'), ['action' => 'check_in', 'idempotency_key' => 'in-1'])->assertRedirect();
        $first = AttendanceRecord::sole();
        $this->assertSame('2026-11-07 18:30:00', $first->getRawOriginal('occurred_at'));
        $this->assertSame('2026-11-08', $first->business_date->toDateString());
        CarbonImmutable::setTestNow('2026-11-07 18:31:00 UTC');
        $this->post(route('attendance.punch'), ['action' => 'check_in', 'idempotency_key' => 'in-1'])->assertRedirect();
        $this->post(route('attendance.punch'), ['action' => 'check_out', 'idempotency_key' => 'in-1'])->assertSessionHasErrors('idempotency_key');
        $this->post(route('attendance.punch'), ['action' => 'check_in', 'idempotency_key' => 'in-2'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('attendance_records', 1);
        CarbonImmutable::setTestNow('2026-11-07 20:00:00 UTC');
        $this->post(route('attendance.punch'), ['action' => 'check_out', 'idempotency_key' => 'out-1'])->assertRedirect();
        $this->assertDatabaseCount('attendance_records', 2);
        $this->assertSame('2026-11-08', AttendanceRecord::latest('id')->first()->business_date->toDateString());
        $this->assertSame($profile->id, AttendanceRecord::first()->employee_profile_id);
    }

    public function test_dhaka_overnight_and_dst_location_dates(): void
    {
        $user = User::factory()->create();
        $profile = $this->employee($user);
        $service = app(AttendanceService::class);
        CarbonImmutable::setTestNow('2026-11-07 17:50:00 UTC');
        $in = $service->punch($user, $profile, 'check_in', 'night-in');
        CarbonImmutable::setTestNow('2026-11-07 18:10:00 UTC');
        $out = $service->punch($user, $profile, 'check_out', 'night-out');
        $this->assertSame('2026-11-07', $in->business_date->toDateString());
        $this->assertSame('2026-11-07', $out->business_date->toDateString());

        $nyUser = User::factory()->create();
        $ny = $this->employee($nyUser, 'America/New_York', 'E-2');
        CarbonImmutable::setTestNow('2026-03-08 06:30:00 UTC');
        $nyIn = $service->punch($nyUser, $ny, 'check_in', 'ny-in');
        CarbonImmutable::setTestNow('2026-03-08 07:30:00 UTC');
        $nyOut = $service->punch($nyUser, $ny, 'check_out', 'ny-out');
        $this->assertSame('2026-03-08', $nyIn->business_date->toDateString());
        $this->assertSame('America/New_York', $nyIn->timezone);
        $this->assertSame('2026-03-08', $nyOut->business_date->toDateString());
        try {
            $service->parseLocalTime($ny, '2026-03-08T02:30');
            $this->fail('The DST gap should be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        try {
            $service->parseLocalTime($ny, '2026-11-01T01:30');
            $this->fail('An ambiguous hour needs an offset.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $firstRepeated = $service->parseLocalTime($ny, '2026-11-01T01:30', '-04:00');
        $secondRepeated = $service->parseLocalTime($ny, '2026-11-01T01:30', '-05:00');
        $this->assertSame(3600, $secondRepeated->getTimestamp() - $firstRepeated->getTimestamp());
    }

    public function test_hr_manual_entry_requires_reason_assignment_and_valid_sequence(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $profile = $this->employee($worker);
        $this->actingAs($hr)->post(route('attendance.manual', $profile), ['action' => 'check_in', 'occurred_local' => '2026-11-07T17:00', 'idempotency_key' => 'missing-reason'])->assertSessionHasErrors('reason');
        $this->post(route('attendance.manual', $profile), ['action' => 'check_in', 'occurred_local' => '2026-07-01T09:00', 'reason' => 'Backfill', 'idempotency_key' => 'old'])->assertSessionHasErrors('occurred_at');
        $this->post(route('attendance.manual', $profile), ['action' => 'check_in', 'occurred_local' => '2026-11-07T17:00', 'reason' => 'Terminal outage', 'idempotency_key' => 'manual-in'])->assertRedirect();
        $this->assertSame('hr_manual', AttendanceRecord::sole()->source);
        $this->post(route('attendance.manual', $profile), ['action' => 'check_out', 'occurred_local' => '2026-11-07T18:00', 'reason' => 'Terminal outage', 'idempotency_key' => 'manual-out'])->assertRedirect();
        $this->assertDatabaseCount('attendance_records', 2);
    }

    public function test_corrections_are_approved_or_rejected_without_mutating_original(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $worker = User::factory()->create();
        $profile = $this->employee($worker);
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $service = app(AttendanceService::class);
        $original = $service->manual($hr, $profile, 'check_in', CarbonImmutable::parse('2026-11-07 09:00 UTC'), 'Terminal outage', 'original');
        $proposal = $service->proposeCorrection($hr, $original, 'check_in', CarbonImmutable::parse('2026-11-07 09:05 UTC'), 'Five minutes late', 'proposal');
        $this->actingAs($hr)->post(route('attendance.decide', [$profile, $proposal]), ['decision' => 'approved', 'reason' => 'Checked'])->assertForbidden();
        $this->actingAs($admin)->post(route('attendance.decide', [$profile, $proposal]), ['decision' => 'rejected', 'reason' => 'Insufficient evidence'])->assertRedirect();
        $this->assertSame($original->id, $service->effectiveEvents($profile)->sole()->id);
        $this->assertSame('recorded', $original->fresh()->status);
        $this->post(route('attendance.decide', [$profile, $proposal]), ['decision' => 'approved', 'reason' => 'Retry'])->assertSessionHasErrors('decision');
        $second = $service->proposeCorrection($hr, $original, 'check_in', CarbonImmutable::parse('2026-11-07 09:10 UTC'), 'Verified', 'proposal-2');
        $this->post(route('attendance.decide', [$profile, $second]), ['decision' => 'approved', 'reason' => 'Verified log'])->assertRedirect();
        $this->assertSame($second->id, $service->effectiveEvents($profile)->sole()->id);
        $this->assertSame(2, AttendanceDecision::count());
        $this->assertSame('recorded', $original->fresh()->status);
    }

    public function test_manager_scope_and_cross_company_boundaries_apply_to_all_actions(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $manager = User::factory()->create(['role' => Role::Manager]);
        $worker = User::factory()->create();
        $outsider = User::factory()->create();
        $managerProfile = $this->employee($manager, number: 'M-1');
        $profile = $this->employee($worker, number: 'E-1');
        $other = $this->employee($outsider, number: 'E-2');
        $profile->assignments()->first()->update(['manager_employee_profile_id' => $managerProfile->id]);
        $service = app(AttendanceService::class);
        $original = $service->punch($worker, $profile, 'check_in', 'worker-in');
        $this->actingAs($manager)->get(route('attendance.index'))->assertOk()->assertSee($profile->employee_number)->assertDontSee($other->employee_number);
        $this->get(route('attendance.show', $profile))->assertOk();
        $this->get(route('attendance.show', $other))->assertForbidden();
        $this->post(route('attendance.manual', $other), ['action' => 'check_in', 'occurred_local' => '2026-11-07T17:00', 'reason' => 'Manual', 'idempotency_key' => 'm1'])->assertForbidden();
        $this->post(route('attendance.decide', [$profile, $original]), ['decision' => 'approved', 'reason' => 'No'])->assertNotFound();
        $otherCompany = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $foreignUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $foreign = $this->employee($foreignUser, number: 'F-1');
        $this->get(route('attendance.show', $foreign))->assertNotFound();
        $this->post(route('attendance.manual', $foreign), ['action' => 'check_in', 'occurred_local' => '2026-11-07T17:00', 'reason' => 'Manual', 'idempotency_key' => 'm2'])->assertNotFound();
    }

    public function test_inactive_unlinked_guest_and_unverified_users_cannot_punch(): void
    {
        $this->post(route('attendance.punch'), ['action' => 'check_in', 'idempotency_key' => 'guest'])->assertRedirect(route('login'));
        $unlinked = User::factory()->create();
        $this->actingAs($unlinked)->get(route('attendance.mine'))->assertOk()->assertSee(__('attendance.unlinked'));
        $this->post(route('attendance.punch'), ['action' => 'check_in', 'idempotency_key' => 'unlinked'])->assertNotFound();
        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get(route('attendance.mine'))->assertRedirect(route('verification.notice'));
        $worker = User::factory()->create();
        $profile = $this->employee($worker);
        $profile->update(['status' => 'inactive']);
        $this->actingAs($worker)->post(route('attendance.punch'), ['action' => 'check_in', 'idempotency_key' => 'inactive'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_bangla_history_and_company_summary_render(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $profile = $this->employee($worker);
        app(AttendanceService::class)->punch($worker, $profile, 'check_in', 'in');
        app()->setLocale('bn');
        $this->actingAs($worker)->get(route('attendance.mine'))->assertOk()->assertSee('আমার উপস্থিতি');
        $this->actingAs($admin)->get(route('attendance.index'))->assertOk()->assertSee($profile->name)->assertSee('চেক ইন');
        $this->get(route('attendance.show', $profile))->assertOk()->assertSee('উপস্থিতির ইতিহাস');
    }

    public function test_manual_retry_after_window_and_conflicting_key(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $hr = User::factory()->create(['role' => Role::HR]);
        $profile = $this->employee();
        $service = app(AttendanceService::class);
        $at = CarbonImmutable::parse('2026-11-07 09:00 UTC');
        $record = $service->manual($hr, $profile, 'check_in', $at, 'Terminal outage', 'manual-retry');
        CarbonImmutable::setTestNow('2027-03-01 12:00:00 UTC');
        $this->assertSame($record->id, $service->manual($hr, $profile, 'check_in', $at, 'Terminal outage', 'manual-retry')->id);
        $this->expectException(ValidationException::class);
        $service->manual($hr, $profile, 'check_out', $at, 'Different request', 'manual-retry');
    }

    public function test_invalid_correction_cannot_be_approved_and_other_staff_cannot_self_approve(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $profile = $this->employee();
        $service = app(AttendanceService::class);
        $in = $service->manual($hr, $profile, 'check_in', CarbonImmutable::parse('2026-11-07 09:00 UTC'), 'Outage', 'i');
        $out = $service->manual($hr, $profile, 'check_out', CarbonImmutable::parse('2026-11-07 10:00 UTC'), 'Outage', 'o');
        $proposal = $service->proposeCorrection($hr, $out, 'check_out', CarbonImmutable::parse('2026-11-07 08:00 UTC'), 'Wrong time', 'bad-correction');
        $this->actingAs($hr)->post(route('attendance.decide', [$profile, $proposal]), ['decision' => 'approved', 'reason' => 'Self'])->assertForbidden();
        $this->actingAs($admin)->post(route('attendance.decide', [$profile, $proposal]), ['decision' => 'approved', 'reason' => 'Review'])->assertSessionHasErrors('action');
        $this->assertDatabaseCount('attendance_decisions', 0);
        $this->assertSame([$in->id, $out->id], $service->effectiveEvents($profile)->pluck('id')->all());
    }

    public function test_manager_attendance_scope_changes_with_transfer(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $hr = User::factory()->create(['role' => Role::HR]);
        $managerA = User::factory()->create(['role' => Role::Manager]);
        $managerB = User::factory()->create(['role' => Role::Manager]);
        $worker = User::factory()->create();
        $a = $this->employee($managerA, number: 'M-1');
        $b = $this->employee($managerB, number: 'M-2');
        $profile = $this->employee($worker, number: 'E-1');
        $current = $profile->assignments()->first();
        $current->update(['manager_employee_profile_id' => $a->id]);
        $this->actingAs($managerA)->get(route('attendance.show', $profile))->assertOk();
        app(AssignmentService::class)->transfer($profile, $current->department, $current->position, $current->location, CarbonImmutable::parse('2026-11-06'), $hr, $b);
        $this->get(route('attendance.show', $profile))->assertForbidden();
        $this->actingAs($managerB)->get(route('attendance.show', $profile))->assertOk();
    }

    public function test_missing_assignment_and_cross_company_service_calls_are_denied(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $worker = User::factory()->create();
        $profile = EmployeeProfile::create(['company_id' => 1, 'user_id' => $worker->id, 'employee_number' => 'E-1', 'name' => 'Worker']);
        $this->actingAs($worker)->post(route('attendance.punch'), ['action' => 'check_in', 'idempotency_key' => 'no-assignment'])->assertSessionHasErrors('assignment');
        $otherCompany = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $foreign = User::factory()->create(['company_id' => $otherCompany->id, 'role' => Role::HR]);
        $this->expectException(AuthorizationException::class);
        app(AttendanceService::class)->manual($foreign, $profile, 'check_in', CarbonImmutable::now('UTC'), 'No access', 'foreign');
    }

    public function test_manager_can_enter_and_propose_for_current_direct_report_but_cannot_decide(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $manager = User::factory()->create(['role' => Role::Manager]);
        $worker = User::factory()->create();
        $managerProfile = $this->employee($manager, number: 'M-1');
        $profile = $this->employee($worker, number: 'E-1');
        $profile->assignments()->first()->update(['manager_employee_profile_id' => $managerProfile->id]);
        $this->actingAs($manager)->post(route('attendance.manual', $profile), ['action' => 'check_in', 'occurred_local' => '2026-11-07T17:00', 'reason' => 'Device unavailable', 'idempotency_key' => 'manager-entry'])->assertRedirect();
        $original = AttendanceRecord::sole();
        $this->assertSame('manager_manual', $original->source);
        $this->post(route('attendance.correct', [$profile, $original]), ['action' => 'check_in', 'occurred_local' => '2026-11-07T17:05', 'reason' => 'Verified log', 'idempotency_key' => 'manager-correction'])->assertRedirect();
        $proposal = AttendanceRecord::latest('id')->first();
        $this->assertSame('pending', $proposal->status);
        $this->post(route('attendance.decide', [$profile, $proposal]), ['decision' => 'approved', 'reason' => 'No'])->assertForbidden();
    }

    public function test_approved_cross_midnight_correction_changes_effective_work_date_only(): void
    {
        CarbonImmutable::setTestNow('2026-11-08 12:00:00 UTC');
        $hr = User::factory()->create(['role' => Role::HR]);
        $admin = User::factory()->create(['role' => Role::Admin]);
        $worker = User::factory()->create();
        $profile = $this->employee($worker);
        $service = app(AttendanceService::class);
        $original = $service->manual($hr, $profile, 'check_in', CarbonImmutable::parse('2026-11-07 18:10 UTC'), 'Backfill', 'original');
        $checkout = $service->manual($hr, $profile, 'check_out', CarbonImmutable::parse('2026-11-07 19:00 UTC'), 'Backfill', 'checkout');
        $this->assertSame('2026-11-08', $checkout->business_date->toDateString());
        $proposal = $service->proposeCorrection($hr, $original, 'check_in', CarbonImmutable::parse('2026-11-07 17:50 UTC'), 'Corrected clock', 'move-day');
        $service->decide($admin, $proposal, 'approved', 'Verified device log');
        $effective = $service->effectiveEvents($profile);
        $this->assertSame('2026-11-07', $effective->first()->getAttribute('effective_business_date'));
        $this->assertSame('2026-11-07', $effective->last()->getAttribute('effective_business_date'));
        $this->assertSame('2026-11-08', $checkout->fresh()->business_date->toDateString());
        $this->actingAs($admin)->get(route('attendance.show', [$profile, 'from' => '2026-11-07', 'to' => '2026-11-07']))->assertOk()->assertSee('Corrected clock');
    }

    public function test_employee_subject_cannot_approve_their_own_correction(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $admin = User::factory()->create(['role' => Role::Admin]);
        $hr = User::factory()->create(['role' => Role::HR]);
        $profile = $this->employee($admin);
        $service = app(AttendanceService::class);
        $original = $service->punch($admin, $profile, 'check_in', 'self-in');
        $proposal = $service->proposeCorrection($hr, $original, 'check_in', CarbonImmutable::parse('2026-11-07 11:55 UTC'), 'Clock adjustment', 'self-proposal');
        $this->actingAs($admin)->post(route('attendance.decide', [$profile, $proposal]), ['decision' => 'approved', 'reason' => 'Mine'])->assertForbidden();
        $this->assertDatabaseCount('attendance_decisions', 0);
    }

    public function test_database_rejects_duplicate_company_idempotency_key(): void
    {
        CarbonImmutable::setTestNow('2026-11-07 12:00:00 UTC');
        $worker = User::factory()->create();
        $profile = $this->employee($worker);
        $record = app(AttendanceService::class)->punch($worker, $profile, 'check_in', 'same-key');
        $copy = $record->replicate();
        $this->expectException(QueryException::class);
        $copy->save();
    }
}
