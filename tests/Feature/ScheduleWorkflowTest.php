<?php

namespace Tests\Feature;

use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use App\Scheduling\ShiftAssignment;
use App\Scheduling\ShiftAssignmentRevision;
use App\Scheduling\ShiftScheduleService;
use App\Scheduling\ShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ScheduleWorkflowTest extends TestCase
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
        $employee = EmployeeProfile::create(['company_id' => $user->company_id, 'user_id' => $user->id,
            'employee_number' => $number, 'name' => $user->name]);
        $department = Department::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Operations']);
        $position = Position::firstOrCreate(['company_id' => $user->company_id, 'name' => 'Officer']);
        $location = Location::firstOrCreate(['company_id' => $user->company_id, 'name' => $locationName], ['timezone' => $timezone]);
        app(AssignmentService::class)->assign($employee, $department, $position, $location, CarbonImmutable::parse('2020-01-01'), null, $manager);

        return $employee;
    }

    private function template(User $hr, Location $location, string $code = 'NIGHT', string $start = '22:00', string $end = '06:00', int $break = 30): ShiftTemplate
    {
        return app(ShiftScheduleService::class)->createTemplate($hr, $location, ['code' => $code, 'name' => $code,
            'starts_at_local' => $start, 'ends_at_local' => $end, 'unpaid_break_minutes' => $break,
            'grace_in_minutes' => 10, 'grace_out_minutes' => 5]);
    }

    public function test_hr_assigns_dhaka_overnight_shift_and_resolver_marks_rest_day(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $service = app(ShiftScheduleService::class);
        $this->assertSame('unscheduled', $service->resolve($employee, '2026-11-09')->status);
        $shift = $this->template($hr, $employee->assignments()->first()->location);
        $assignment = $service->assign($hr, $employee, $shift, '2026-11-09', null, [1, 2, 3, 4, 5], 'Publish duty', 'assignment-1');
        $this->assertSame($assignment->id, $service->assign($hr, $employee, $shift, '2026-11-09', null, [1, 2, 3, 4, 5], 'Publish duty', 'assignment-1')->id);
        $duty = $service->resolve($employee, '2026-11-09');
        $this->assertSame('scheduled', $duty->status);
        $this->assertSame('2026-11-09 16:00', $duty->startsAt->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 00:00', $duty->endsAt->format('Y-m-d H:i'));
        $this->assertSame(450, $duty->scheduledMinutes);
        $this->assertSame('rest_day', $service->resolve($employee, '2026-11-15')->status);
    }

    public function test_overlap_retry_and_future_supersession_keep_audit(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = $this->profile(User::factory()->create(), 'E1');
        $service = app(ShiftScheduleService::class);
        $location = $employee->assignments()->first()->location;
        $night = $this->template($hr, $location);
        $day = $this->template($hr, $location, 'DAY', '09:00', '17:00', 60);
        $first = $service->assign($hr, $employee, $night, '2026-11-09', null, [1, 2, 3, 4, 5], 'Initial', 'initial');
        $this->expectValidation(fn () => $service->assign($hr, $employee, $day, '2026-11-10', null, [2], 'Overlap', 'overlap'));
        $this->expectValidation(fn () => $service->assign($hr, $employee, $day, '2026-11-09', null, [1], 'Changed retry', 'initial'));
        $second = $service->assign($hr, $employee, $day, '2026-11-16', null, [1, 2, 3, 4, 5], 'New pattern', 'replacement', $first->id);
        $this->assertSame('2026-11-15', $first->fresh()->ends_on->toDateString());
        $this->assertSame($second->id, $service->resolve($employee, '2026-11-16')->template->assignments()->first()->id);
        $this->assertDatabaseCount('shift_assignment_revisions', 1);
        $this->assertSame('New pattern', ShiftAssignmentRevision::sole()->reason);
        $this->expectException(\LogicException::class);
        $night->update(['starts_at_local' => '21:00']);
    }

    public function test_different_weekday_patterns_can_coexist_and_dst_is_deterministic(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = $this->profile(User::factory()->create(), 'E1', null, 'New York', 'America/New_York');
        $service = app(ShiftScheduleService::class);
        $location = $employee->assignments()->first()->location;
        $spring = $this->template($hr, $location, 'SPRING', '01:00', '04:00', 0);
        $fall = $this->template($hr, $location, 'FALL', '01:30', '02:30', 0);
        $service->assign($hr, $employee, $spring, '2027-03-14', '2027-03-14', [7], 'DST spring', 'spring');
        $service->assign($hr, $employee, $fall, '2027-11-07', '2027-11-07', [7], 'DST fall', 'fall');
        $this->assertSame(120, $service->resolve($employee, '2027-03-14')->scheduledMinutes);
        $this->assertSame(120, $service->resolve($employee, '2027-11-07')->scheduledMinutes);
        $gap = $this->template($hr, $location, 'GAP', '02:30', '04:00', 0);
        $service->assign($hr, $employee, $gap, '2028-03-12', '2028-03-12', [7], 'DST gap', 'gap');
        $this->assertSame('invalid_time', $service->resolve($employee, '2028-03-12')->status);
    }

    public function test_service_and_database_reject_foreign_company_references(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $otherCompany = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $otherHr = User::factory()->create(['role' => Role::HR, 'company_id' => $otherCompany]);
        $otherEmployee = $this->profile(User::factory()->create(['company_id' => $otherCompany]), 'E2');
        $shift = $this->template($hr, $employee->assignments()->first()->location);
        $service = app(ShiftScheduleService::class);
        $this->expectAuthorization(fn () => $service->createTemplate($worker, $employee->assignments()->first()->location,
            ['code' => 'DENIED', 'name' => 'Denied', 'starts_at_local' => '09:00', 'ends_at_local' => '17:00',
                'unpaid_break_minutes' => 0, 'grace_in_minutes' => 0, 'grace_out_minutes' => 0]));
        $this->expectAuthorization(fn () => $service->assign($worker, $employee, $shift, '2026-11-09', null, [1], 'Unauthorized', 'worker'));
        $this->expectNotFound(fn () => $service->assign($otherHr, $employee, $shift, '2026-11-09', null, [1], 'Unauthorized', 'x'));
        $this->expectNotFound(fn () => $service->assign($hr, $otherEmployee, $shift, '2026-11-09', null, [1], 'Foreign employee', 'y'));
        $this->expectQueryFailure(fn () => ShiftAssignment::create(['company_id' => $otherCompany,
            'employee_profile_id' => $otherEmployee->id, 'shift_template_id' => $shift->id, 'actor_user_id' => $otherHr->id,
            'starts_on' => '2026-11-09', 'weekdays' => '1', 'idempotency_key' => 'forged', 'reason' => 'Foreign shift']));
    }

    public function test_hr_can_publish_through_portal_and_employee_sees_own_schedule(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1');
        $this->actingAs($hr)->get(route('admin.schedule.index'))->assertOk()->assertSee(__('schedule.new_template'));
        $this->post(route('admin.schedule.templates'), ['location_id' => $employee->assignments()->first()->location_id,
            'code' => 'NIGHT', 'name' => 'Night duty', 'starts_at_local' => '22:00', 'ends_at_local' => '06:00',
            'unpaid_break_minutes' => 30, 'grace_in_minutes' => 10, 'grace_out_minutes' => 5])->assertRedirect();
        $shift = ShiftTemplate::sole();
        $this->post(route('admin.schedule.assignments'), ['employee_profile_id' => $employee->id,
            'shift_template_id' => $shift->id, 'starts_on' => '2026-11-09', 'weekdays' => [1, 2, 3, 4, 5],
            'reason' => 'Published roster', 'idempotency_key' => 'publish-1'])->assertRedirect();
        $this->actingAs($worker)->get(route('schedule.mine', ['month' => '2026-11']))->assertOk()
            ->assertSee('Night duty')->assertSee('Asia/Dhaka')->assertSee(__('schedule.overnight'));
        $this->get(route('admin.schedule.index'))->assertForbidden();
        $this->post(route('admin.schedule.assignments'), ['employee_profile_id' => $employee->id])->assertForbidden();
        $this->post(route('admin.schedule.templates'), ['location_id' => $employee->assignments()->first()->location_id])->assertForbidden();
        $this->actingAs($hr)->put(route('admin.schedule.templates.update', $shift), [
            'name' => 'Night duty v1', 'starts_at_local' => '21:00', 'ends_at_local' => '06:00',
            'unpaid_break_minutes' => 30, 'grace_in_minutes' => 10, 'grace_out_minutes' => 5, 'is_active' => 1,
        ])->assertSessionHasErrors('starts_at_local');
    }

    public function test_manager_scope_and_unverified_inactive_unlinked_access(): void
    {
        $manager = User::factory()->create(['role' => Role::Manager]);
        $managerProfile = $this->profile($manager, 'M1');
        $worker = User::factory()->create();
        $employee = $this->profile($worker, 'E1', $managerProfile);
        $other = $this->profile(User::factory()->create(), 'E2');
        $this->actingAs($manager)->get(route('schedule.index'))->assertOk()->assertSee($employee->name)->assertDontSee($other->name);
        $this->get(route('schedule.show', $other))->assertForbidden();
        $this->get(route('schedule.show', $employee))->assertOk();
        $this->get(route('admin.schedule.index'))->assertForbidden();
        $employee->assignments()->first()->update(['manager_employee_profile_id' => null]);
        $this->get(route('schedule.show', $employee))->assertForbidden();
        $unlinked = User::factory()->create();
        $this->actingAs($unlinked)->get(route('schedule.mine'))->assertOk()->assertSee(__('schedule.unlinked'));
        $this->actingAs($worker)->get(route('schedule.mine'))->assertOk();
        $employee->update(['status' => 'inactive']);
        $this->get(route('schedule.mine'))->assertRedirect(route('login'));
        $employee->update(['status' => 'active']);
        $worker->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($worker->fresh())->get(route('schedule.mine'))->assertRedirect(route('verification.notice'));
    }

    public function test_portal_rejects_foreign_ids_and_tampered_company(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = $this->profile(User::factory()->create(), 'E1');
        $foreignCompany = DB::table('companies')->insertGetId(['public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $foreignEmployee = $this->profile(User::factory()->create(['company_id' => $foreignCompany]), 'E2');
        $foreignLocation = $foreignEmployee->assignments()->first()->location;
        $this->actingAs($hr)->post(route('admin.schedule.templates'), ['location_id' => $foreignLocation->id,
            'code' => 'FOREIGN', 'name' => 'Foreign', 'starts_at_local' => '09:00', 'ends_at_local' => '17:00',
            'unpaid_break_minutes' => 0, 'grace_in_minutes' => 0, 'grace_out_minutes' => 0])->assertNotFound();
        $shift = $this->template($hr, $employee->assignments()->first()->location);
        $this->post(route('admin.schedule.assignments'), ['employee_profile_id' => $foreignEmployee->id,
            'shift_template_id' => $shift->id, 'starts_on' => '2026-11-09', 'weekdays' => [1],
            'reason' => 'Wrong company', 'idempotency_key' => 'foreign'])->assertNotFound();
        $this->post(route('admin.schedule.assignments'), ['employee_profile_id' => $employee->id,
            'shift_template_id' => $shift->id, 'starts_on' => '2026-11-09', 'weekdays' => [1],
            'reason' => 'Tampered', 'idempotency_key' => 'tampered', 'company_id' => $foreignCompany])->assertSessionHasErrors('company_id');
    }

    public function test_location_transfer_exposes_schedule_conflict_and_preserves_prior_duty(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $employee = $this->profile(User::factory()->create(), 'E1');
        $employment = $employee->assignments()->first();
        $shift = $this->template($hr, $employment->location);
        $service = app(ShiftScheduleService::class);
        $service->assign($hr, $employee, $shift, '2026-11-09', null, [1, 2, 3, 4, 5], 'Initial', 'initial');
        $this->assertSame('scheduled', $service->resolve($employee, '2026-11-09')->status);
        $newLocation = Location::create(['company_id' => $hr->company_id, 'name' => 'Chattogram', 'timezone' => 'Asia/Dhaka', 'is_active' => true]);
        app(AssignmentService::class)->transfer($employee, $employment->department, $employment->position,
            $newLocation, CarbonImmutable::parse('2026-11-16'), $hr);
        $this->assertSame('scheduled', $service->resolve($employee, '2026-11-09')->status);
        $this->assertSame('location_conflict', $service->resolve($employee, '2026-11-16')->status);
        $this->assertDatabaseCount('shift_assignments', 1);
    }

    private function expectValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected validation failure.');
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

    private function expectNotFound(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected 404.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    private function expectQueryFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected SQL constraint failure.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
