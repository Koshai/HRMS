<?php

namespace App\Scheduling;

use App\Attendance\AttendanceRecord;
use App\Attendance\AttendanceService;
use App\Employees\EmployeeProfile;
use App\Leave\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AttendanceSummaryService
{
    public function __construct(
        private readonly ShiftScheduleService $schedules,
        private readonly AttendanceService $attendance,
    ) {}

    public function daily(EmployeeProfile $employee, string $date, ?CarbonImmutable $asOf = null): DailyAttendanceSummary
    {
        $events = $this->attendance->effectiveEvents($employee);
        $leave = LeaveRequest::query()->where('company_id', $employee->company_id)->where('employee_profile_id', $employee->id)
            ->where('status', 'approved')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->get();

        return $this->evaluate($employee, $date, $asOf ?? CarbonImmutable::now('UTC'), $events, $leave);
    }

    /** @return list<DailyAttendanceSummary> */
    public function month(EmployeeProfile $employee, CarbonImmutable $month, ?CarbonImmutable $asOf = null): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $events = $this->attendance->effectiveEvents($employee);
        $leave = LeaveRequest::query()->where('company_id', $employee->company_id)->where('employee_profile_id', $employee->id)
            ->where('status', 'approved')->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())->get();
        $days = [];
        $now = $asOf ?? CarbonImmutable::now('UTC');
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[] = $this->evaluate($employee, $day->toDateString(), $now, $events, $leave);
        }

        return $days;
    }

    /** @param Collection<int, AttendanceRecord> $allEvents
     * @param  Collection<int, LeaveRequest>  $allLeave
     */
    private function evaluate(EmployeeProfile $employee, string $date, CarbonImmutable $now, Collection $allEvents, Collection $allLeave): DailyAttendanceSummary
    {
        $schedule = $this->schedules->resolve($employee, $date);
        $events = $allEvents->filter(fn ($event) => $event->getAttribute('effective_business_date') === $date)
            ->sortBy(fn ($event) => $this->instant($event)->getTimestamp())->values();
        $leave = $allLeave->filter(fn ($request) => CarbonImmutable::parse($request->starts_on)->toDateString() <= $date
            && CarbonImmutable::parse($request->ends_on)->toDateString() >= $date);
        $fullLeave = $leave->contains(fn ($request) => $request->partial === null)
            || ($leave->contains(fn ($request) => $request->partial === 'am') && $leave->contains(fn ($request) => $request->partial === 'pm'));
        $partialLeave = $leave->isNotEmpty() && ! $fullLeave;
        $firstIn = $events->first(fn ($event) => $event->action === 'check_in');
        $lastOut = $events->last(fn ($event) => $event->action === 'check_out');
        $open = $events->last()?->action === 'check_in';
        $worked = $this->workedMinutes($events);

        if ($schedule->status !== 'scheduled') {
            $status = match ($schedule->status) {
                'rest_day' => $events->isEmpty() ? 'rest_day' : 'rest_day_work',
                'unscheduled', 'no_assignment' => $events->isEmpty() ? $schedule->status : 'unscheduled_work',
                default => $schedule->status,
            };

            return new DailyAttendanceSummary($schedule, $status, $worked, 0, 0, $fullLeave, $partialLeave);
        }

        if ($events->isEmpty()) {
            $status = $fullLeave ? 'excused_leave' : ($partialLeave ? 'partial_leave_review'
                : ($now->greaterThan($schedule->endsAt->addMinutes($schedule->template->grace_out_minutes))
                    ? 'no_show_candidate' : 'scheduled'));

            return new DailyAttendanceSummary($schedule, $status, 0, 0, 0, $fullLeave, $partialLeave);
        }

        $late = $firstIn ? max(0, (int) floor(($this->instant($firstIn)->getTimestamp()
            - $schedule->startsAt->getTimestamp()) / 60) - $schedule->template->grace_in_minutes) : 0;
        $early = $lastOut ? max(0, (int) floor(($schedule->endsAt->getTimestamp()
            - $this->instant($lastOut)->getTimestamp()) / 60) - $schedule->template->grace_out_minutes) : 0;
        $actual = max(0, $worked - ($open ? 0 : $schedule->template->unpaid_break_minutes));
        $status = $fullLeave ? 'leave_with_punch' : ($partialLeave ? 'partial_leave_review'
            : ($open ? ($now->greaterThan($schedule->endsAt) ? 'missing_punch' : 'in_progress')
                : ($late > 0 && $early > 0 ? 'late_and_early' : ($late > 0 ? 'late' : ($early > 0 ? 'early_departure' : 'completed')))));

        return new DailyAttendanceSummary($schedule, $status, $actual, $late, $early, $fullLeave, $partialLeave);
    }

    /** @param Collection<int, AttendanceRecord> $events */
    private function workedMinutes(Collection $events): int
    {
        $start = null;
        $total = 0;
        foreach ($events as $event) {
            if ($event->action === 'check_in') {
                $start = $this->instant($event);
            } elseif ($start !== null) {
                $total += max(0, (int) floor(($this->instant($event)->getTimestamp() - $start->getTimestamp()) / 60));
                $start = null;
            }
        }

        return $total;
    }

    private function instant(AttendanceRecord $event): CarbonImmutable
    {
        return CarbonImmutable::parse($event->getRawOriginal('occurred_at'), 'UTC');
    }
}
