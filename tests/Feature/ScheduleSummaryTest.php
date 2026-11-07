<?php

namespace Tests\Feature;

use App\Attendance\AttendanceDecision;
use App\Attendance\AttendanceRecord;
use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Leave\LeaveBalanceEntry;
use App\Leave\LeavePolicy;
use App\Leave\LeaveRequest;
use App\Leave\LeaveRequestCharge;
use App\Leave\LeaveService;
use App\Leave\LeaveType;
use App\Models\User;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use App\Scheduling\AttendanceSummaryService;
use App\Scheduling\ShiftScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScheduleSummaryTest extends TestCase
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

    private function employee(User $worker, string $timezone = 'Asia/Dhaka'): EmployeeProfile
    {
        $employee = EmployeeProfile::create(['company_id' => $worker->company_id, 'user_id' => $worker->id,
            'employee_number' => 'E1', 'name' => $worker->name]);
        $department = Department::firstOrCreate(['company_id' => $worker->company_id, 'name' => 'Operations']);
        $position = Position::firstOrCreate(['company_id' => $worker->company_id, 'name' => 'Officer']);
        $location = Location::firstOrCreate(['company_id' => $worker->company_id, 'name' => $timezone], ['timezone' => $timezone]);
        app(AssignmentService::class)->assign($employee, $department, $position, $location, CarbonImmutable::parse('2020-01-01'));

        return $employee;
    }

    private function schedule(User $hr, EmployeeProfile $employee, string $start = '09:00', string $end = '17:00', array $weekdays = [1, 2, 3, 4, 5]): void
    {
        $service = app(ShiftScheduleService::class);
        $shift = $service->createTemplate($hr, $employee->assignments()->first()->location, [
            'code' => 'PRIMARY', 'name' => 'Primary shift', 'starts_at_local' => $start, 'ends_at_local' => $end,
            'unpaid_break_minutes' => 30, 'grace_in_minutes' => 10, 'grace_out_minutes' => 5,
        ]);
        $service->assign($hr, $employee, $shift, '2026-11-09', null, $weekdays, 'Published test duty', 'schedule-1');
    }

    private function event(User $actor, EmployeeProfile $employee, string $action, string $utc, string $businessDate, ?int $correctionOf = null): AttendanceRecord
    {
        return AttendanceRecord::create(['company_id' => $employee->company_id, 'employee_profile_id' => $employee->id,
            'actor_user_id' => $actor->id, 'action' => $action, 'source' => 'hr_manual', 'occurred_at' => $utc,
            'recorded_at' => $utc, 'business_date' => $businessDate,
            'timezone' => $employee->assignments()->first()->location->timezone, 'correction_of_id' => $correctionOf,
            'status' => $correctionOf ? 'pending' : 'recorded', 'reason' => $correctionOf ? 'Correction' : 'Test fixture',
            'idempotency_key' => (string) Str::uuid()]);
    }

    private function leave(User $worker, EmployeeProfile $employee, string $date, string $status, ?string $partial = null): LeaveRequest
    {
        $type = LeaveType::query()->firstOrCreate(['company_id' => $worker->company_id, 'code' => 'ANNUAL'],
            ['name' => 'Annual', 'is_paid' => true, 'is_active' => true]);

        return LeaveRequest::create(['company_id' => $worker->company_id, 'employee_profile_id' => $employee->id,
            'leave_type_id' => $type->id, 'location_id' => $employee->assignments()->first()->location_id,
            'requester_user_id' => $worker->id, 'starts_on' => $date, 'ends_on' => $date,
            'partial' => $partial, 'units' => $partial ? 1 : 2, 'status' => $status,
            'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_future_duty_is_not_no_show_and_only_approved_full_leave_excuses(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->employee($worker);
        $this->schedule($hr, $employee);
        $summary = app(AttendanceSummaryService::class);
        $this->assertSame('scheduled', $summary->daily($employee, '2026-11-09')->status);
        $this->assertSame('no_show_candidate', $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-09 12:00:00 UTC'))->status);
        $pending = $this->leave($worker, $employee, '2026-11-09', 'pending');
        $this->assertSame('no_show_candidate', $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-09 12:00:00 UTC'))->status);
        $pending->update(['status' => 'rejected']);
        $this->assertSame('no_show_candidate', $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-09 12:00:00 UTC'))->status);
        $pending->update(['status' => 'withdrawn']);
        $this->assertSame('no_show_candidate', $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-09 12:00:00 UTC'))->status);
        $pending->update(['status' => 'cancelled']);
        $this->assertSame('no_show_candidate', $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-09 12:00:00 UTC'))->status);
        $this->leave($worker, $employee, '2026-11-09', 'approved');
        $this->assertSame('excused_leave', $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-09 12:00:00 UTC'))->status);
    }

    public function test_overnight_elapsed_work_grace_late_and_early_flags(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->employee($worker);
        $this->schedule($hr, $employee, '22:00', '06:00');
        $this->event($hr, $employee, 'check_in', '2026-11-09 16:08:00', '2026-11-09');
        $this->event($hr, $employee, 'check_out', '2026-11-09 23:56:00', '2026-11-09');
        $summary = app(AttendanceSummaryService::class)->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-10 01:00:00 UTC'));
        $this->assertSame('completed', $summary->status);
        $this->assertSame(438, $summary->actualMinutes);
        $this->assertSame(450, $summary->schedule->scheduledMinutes);
        $this->assertSame(0, $summary->lateMinutes);
        $this->assertSame(0, $summary->earlyMinutes);
        $original = AttendanceRecord::query()->where('action', 'check_in')->firstOrFail();
        $correction = $this->event($hr, $employee, 'check_in', '2026-11-09 16:15:00', '2026-11-09', $original->id);
        AttendanceDecision::create(['company_id' => $hr->company_id, 'correction_id' => $correction->id,
            'actor_user_id' => $worker->id, 'decision' => 'approved', 'reason' => 'Test correction',
            'decided_at' => '2026-11-10 01:00:00']);
        $corrected = app(AttendanceSummaryService::class)->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-10 01:00:00 UTC'));
        $this->assertSame('late', $corrected->status);
        $this->assertSame(5, $corrected->lateMinutes);
        $this->assertSame(431, $corrected->actualMinutes);
        $this->assertSame('2026-11-09 16:08:00', $original->getRawOriginal('occurred_at'));
    }

    public function test_missing_checkout_rest_day_work_and_half_day_leave_are_distinct(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->employee($worker);
        $this->schedule($hr, $employee);
        $this->event($hr, $employee, 'check_in', '2026-11-09 03:00:00', '2026-11-09');
        $summary = app(AttendanceSummaryService::class);
        $this->assertSame('in_progress', $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-09 05:00:00 UTC'))->status);
        $this->assertSame('missing_punch', $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-09 13:00:00 UTC'))->status);
        $this->assertSame('rest_day', $summary->daily($employee, '2026-11-15')->status);
        $this->event($hr, $employee, 'check_out', '2026-11-09 11:00:00', '2026-11-09');
        $this->event($hr, $employee, 'check_in', '2026-11-15 03:00:00', '2026-11-15');
        $this->event($hr, $employee, 'check_out', '2026-11-15 04:00:00', '2026-11-15');
        $this->assertSame('rest_day_work', $summary->daily($employee, '2026-11-15')->status);
        $this->leave($worker, $employee, '2026-11-10', 'approved', 'am');
        $this->assertSame('partial_leave_review', $summary->daily($employee, '2026-11-10', CarbonImmutable::parse('2026-11-10 12:00:00 UTC'))->status);
    }

    public function test_late_and_early_flags_are_independent_and_unscheduled_work_is_not_absence(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->employee($worker);
        $this->schedule($hr, $employee);
        $summary = app(AttendanceSummaryService::class);
        $this->event($hr, $employee, 'check_in', '2026-11-09 03:15:00', '2026-11-09');
        $this->event($hr, $employee, 'check_out', '2026-11-09 10:45:00', '2026-11-09');
        $day = $summary->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-10 12:00:00 UTC'));
        $this->assertSame('late_and_early', $day->status);
        $this->assertSame(5, $day->lateMinutes);
        $this->assertSame(10, $day->earlyMinutes);
        $this->event($hr, $employee, 'check_in', '2026-11-16 03:00:00', '2026-11-16');
        $this->event($hr, $employee, 'check_out', '2026-11-16 11:00:00', '2026-11-16');
        $this->assertSame('completed', $summary->daily($employee, '2026-11-16')->status);
        $this->assertSame('unscheduled', $summary->daily($employee, '2026-11-08')->status);
        $this->event($hr, $employee, 'check_in', '2026-11-08 03:00:00', '2026-11-08');
        $this->event($hr, $employee, 'check_out', '2026-11-08 04:00:00', '2026-11-08');
        $this->assertSame('unscheduled_work', $summary->daily($employee, '2026-11-08')->status);
    }

    public function test_rescheduling_does_not_rewrite_attendance_or_approved_leave_charges(): void
    {
        $hr = User::factory()->create(['role' => Role::HR]);
        $worker = User::factory()->create();
        $employee = $this->employee($worker);
        $service = app(ShiftScheduleService::class);
        $location = $employee->assignments()->first()->location;
        $old = $service->createTemplate($hr, $location, ['code' => 'OLD', 'name' => 'Old', 'starts_at_local' => '09:00',
            'ends_at_local' => '17:00', 'unpaid_break_minutes' => 0, 'grace_in_minutes' => 0, 'grace_out_minutes' => 0]);
        $first = $service->assign($hr, $employee, $old, '2026-11-09', null, [1, 2, 3, 4, 5], 'Initial', 'initial');
        $type = LeaveType::create(['company_id' => $hr->company_id, 'code' => 'ANNUAL', 'name' => 'Annual', 'is_active' => true]);
        LeavePolicy::create(['company_id' => $hr->company_id, 'leave_type_id' => $type->id, 'location_id' => $location->id,
            'effective_from' => '2026-01-01', 'annual_units' => 20, 'allow_half_day' => true,
            'allow_negative' => false, 'approval_role' => 'hr', 'working_weekdays' => '1,2,3,4,5']);
        $leaveService = app(LeaveService::class);
        $leaveService->grant($hr, $employee, $type, 2026, 20, 'Opening', 'opening');
        $leave = $leaveService->request($worker, $employee, $type, '2026-11-16', '2026-11-16', null, null, 'leave');
        $leaveService->decide($hr, $leave, 'approved', 'Approved');
        $this->event($hr, $employee, 'check_in', '2026-11-09 03:00:00', '2026-11-09');
        $this->event($hr, $employee, 'check_out', '2026-11-09 11:00:00', '2026-11-09');
        $charge = LeaveRequestCharge::sole();
        $entryIds = LeaveBalanceEntry::orderBy('id')->pluck('id')->all();
        $recordIds = AttendanceRecord::orderBy('id')->pluck('id')->all();
        $new = $service->createTemplate($hr, $location, ['code' => 'NEW', 'name' => 'New', 'starts_at_local' => '10:00',
            'ends_at_local' => '18:00', 'unpaid_break_minutes' => 0, 'grace_in_minutes' => 0, 'grace_out_minutes' => 0]);
        $service->assign($hr, $employee, $new, '2026-11-16', null, [1, 2, 3, 4, 5], 'Future change', 'replacement', $first->id);
        $this->assertSame($charge->units, $charge->fresh()->units);
        $this->assertSame($entryIds, LeaveBalanceEntry::orderBy('id')->pluck('id')->all());
        $this->assertSame($recordIds, AttendanceRecord::orderBy('id')->pluck('id')->all());
        $this->assertSame('excused_leave', app(AttendanceSummaryService::class)->daily($employee, '2026-11-16', CarbonImmutable::parse('2026-11-17 12:00:00 UTC'))->status);
        $this->assertSame('completed', app(AttendanceSummaryService::class)->daily($employee, '2026-11-09', CarbonImmutable::parse('2026-11-10 12:00:00 UTC'))->status);
    }
}
