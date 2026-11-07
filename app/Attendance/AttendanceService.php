<?php

namespace App\Attendance;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function punch(User $actor, EmployeeProfile $employee, string $action, string $key): AttendanceRecord
    {
        Gate::forUser($actor)->authorize('attendance-self-punch', $employee);
        $this->validateActionAndKey($action, $key);

        return DB::transaction(function () use ($actor, $employee, $action, $key) {
            $employee = $this->lockEmployee($employee);
            Gate::forUser($actor)->authorize('attendance-self-punch', $employee);
            if ($existing = $this->existing($employee, $key)) {
                $this->assertRetry($existing, $actor, $employee, $action, 'employee_self');

                return $existing;
            }

            $now = CarbonImmutable::now('UTC');

            return $this->append($actor, $employee, $action, 'employee_self', $now, $this->timezoneAt($employee, $now), $key);
        });
    }

    public function manual(User $actor, EmployeeProfile $employee, string $action, CarbonImmutable $occurredAt, string $reason, string $key): AttendanceRecord
    {
        Gate::forUser($actor)->authorize('attendance-manual-entry', $employee);
        $this->validateActionAndKey($action, $key);

        return DB::transaction(function () use ($actor, $employee, $action, $occurredAt, $reason, $key) {
            $employee = $this->lockEmployee($employee);
            Gate::forUser($actor)->authorize('attendance-manual-entry', $employee);
            $source = $this->manualSource($actor);
            if ($existing = $this->existing($employee, $key)) {
                $this->assertRetry($existing, $actor, $employee, $action, $source, $occurredAt, $reason);

                return $existing;
            }

            $this->validateReasonAndWindow($reason, $occurredAt);

            return $this->append($actor, $employee, $action, $source, $occurredAt, $this->timezoneAt($employee, $occurredAt), $key, null, $reason);
        });
    }

    public function proposeCorrection(User $actor, AttendanceRecord $original, string $action, CarbonImmutable $occurredAt, string $reason, string $key): AttendanceRecord
    {
        Gate::forUser($actor)->authorize('attendance-propose-correction', $original);
        $this->validateActionAndKey($action, $key);
        $employee = $original->employee;

        return DB::transaction(function () use ($actor, $employee, $original, $action, $occurredAt, $reason, $key) {
            $employee = $this->lockEmployee($employee);
            Gate::forUser($actor)->authorize('attendance-propose-correction', $original);
            $source = $this->manualSource($actor);
            if ($existing = $this->existing($employee, $key)) {
                $this->assertRetry($existing, $actor, $employee, $action, $source, $occurredAt, $reason, $original->id);

                return $existing;
            }
            $this->validateReasonAndWindow($reason, $occurredAt);
            if ($original->corrections()->whereHas('decision', fn ($query) => $query->where('decision', 'approved'))->exists()) {
                throw ValidationException::withMessages(['correction' => 'This record already has an approved correction.']);
            }

            return $this->append($actor, $employee, $action, $source, $occurredAt, $this->timezoneAt($employee, $occurredAt), $key, $original, $reason);
        });
    }

    public function decide(User $actor, AttendanceRecord $correction, string $decision, string $reason): AttendanceDecision
    {
        Gate::forUser($actor)->authorize('attendance-decide', $correction);
        if (! in_array($decision, ['approved', 'rejected'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['decision' => 'An approval or rejection and reason are required.']);
        }

        return DB::transaction(function () use ($actor, $correction, $decision, $reason) {
            $employee = $correction->employee;
            $employee = $this->lockEmployee($employee);
            $correction = AttendanceRecord::query()->where('company_id', $employee->company_id)->whereKey($correction->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('attendance-decide', $correction);
            if ($correction->decision()->exists()) {
                throw ValidationException::withMessages(['decision' => 'This correction has already been decided.']);
            }
            if ($decision === 'approved') {
                if ($correction->original->corrections()->whereHas('decision', fn ($query) => $query->where('decision', 'approved'))->exists()) {
                    throw ValidationException::withMessages(['decision' => 'The original record already has an approved correction.']);
                }
                $events = $this->effectiveEvents($employee)->reject(fn ($event) => $event->id === $correction->correction_of_id)->push($correction);
                $this->validateSequence($events->all());
            }

            return AttendanceDecision::query()->create([
                'company_id' => $employee->company_id,
                'correction_id' => $correction->id,
                'actor_user_id' => $actor->id,
                'decision' => $decision,
                'reason' => trim($reason),
                'decided_at' => CarbonImmutable::now('UTC'),
            ]);
        });
    }

    /** @return Collection<int, AttendanceRecord> */
    public function effectiveEvents(EmployeeProfile $employee): Collection
    {
        $originals = AttendanceRecord::query()->where('company_id', $employee->company_id)
            ->where('employee_profile_id', $employee->id)->whereNull('correction_of_id')
            ->with(['corrections.decision'])->get();

        $effective = $originals->map(fn (AttendanceRecord $record) => $record->corrections
            ->first(fn (AttendanceRecord $correction) => $correction->decision?->decision === 'approved') ?? $record)
            ->sortBy(fn (AttendanceRecord $record) => $this->instant($record)->getTimestamp())->values();

        $workDate = null;
        foreach ($effective as $event) {
            if ($event->action === 'check_in') {
                $workDate = $this->instant($event)->setTimezone($event->timezone)->toDateString();
            }
            $event->setAttribute('effective_business_date', $workDate ?? substr((string) $event->getRawOriginal('business_date'), 0, 10));
        }

        return $effective;
    }

    public function parseLocalTime(EmployeeProfile $employee, string $local, ?string $offset = null): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) {
            throw ValidationException::withMessages(['occurred_local' => 'Enter a valid local date and time.']);
        }
        $date = substr($local, 0, 10);
        foreach ($employee->assignments()->with('location')->orderByDesc('starts_on')->get() as $assignment) {
            if ($date < CarbonImmutable::parse($assignment->starts_on)->toDateString()
                || ($assignment->ends_on !== null && $date > CarbonImmutable::parse($assignment->ends_on)->toDateString())) {
                continue;
            }
            $timezone = $assignment->location->getAttribute('timezone');
            if (! is_string($timezone) || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw ValidationException::withMessages(['timezone' => 'The assignment location has an invalid time zone.']);
            }
            $wall = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $local, 'UTC');
            if ($wall === null || $wall->format('Y-m-d\TH:i') !== $local) {
                throw ValidationException::withMessages(['occurred_local' => 'Enter a valid local date and time.']);
            }
            $zone = new \DateTimeZone($timezone);
            $transitions = $zone->getTransitions($wall->getTimestamp() - 259200, $wall->getTimestamp() + 259200);
            $offsets = array_unique(array_map(fn (array $transition) => $transition['offset'], $transitions ?: []));
            $offsets[] = $zone->getOffset($wall);
            $candidates = [];
            foreach (array_unique($offsets) as $seconds) {
                $candidate = CarbonImmutable::createFromTimestamp($wall->getTimestamp() - $seconds, 'UTC')->setTimezone($timezone);
                if ($candidate->format('Y-m-d\TH:i') === $local && ($offset === null || $candidate->format('P') === $offset)) {
                    $candidates[] = $candidate->utc();
                }
            }
            if (count($candidates) === 1) {
                return $candidates[0];
            }
            if (count($candidates) > 1) {
                throw ValidationException::withMessages(['occurred_offset' => 'This local time occurs twice. Select its UTC offset.']);
            }
            throw ValidationException::withMessages(['occurred_local' => 'This local time or UTC offset does not exist in the assignment time zone.']);
        }

        throw ValidationException::withMessages(['assignment' => 'An effective location assignment is required for attendance.']);
    }

    public function canPunchNow(EmployeeProfile $employee): bool
    {
        try {
            $this->timezoneAt($employee, CarbonImmutable::now('UTC'));

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    private function append(User $actor, EmployeeProfile $employee, string $action, string $source, CarbonImmutable $occurredAt, string $timezone, string $key, ?AttendanceRecord $original = null, ?string $reason = null): AttendanceRecord
    {
        $events = $this->effectiveEvents($employee);
        $candidate = new AttendanceRecord(['action' => $action, 'occurred_at' => $occurredAt->utc()->format('Y-m-d H:i:s')]);
        if ($original === null) {
            $this->validateSequence($events->push($candidate)->all());
        }

        $businessDate = $occurredAt->setTimezone($timezone)->toDateString();
        if ($action === 'check_out') {
            $prior = $events->filter(fn ($event) => $event->action === 'check_in' && $this->instant($event)->lessThanOrEqualTo($occurredAt))->last();
            $businessDate = $prior ? $prior->getAttribute('effective_business_date') : $businessDate;
        }

        return AttendanceRecord::query()->create([
            'company_id' => $employee->company_id,
            'employee_profile_id' => $employee->id,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'source' => $source,
            'occurred_at' => $occurredAt->utc()->format('Y-m-d H:i:s'),
            'recorded_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'),
            'business_date' => $businessDate,
            'timezone' => $timezone,
            'correction_of_id' => $original?->id,
            'status' => $original ? 'pending' : 'recorded',
            'reason' => $reason,
            'idempotency_key' => $key,
        ]);
    }

    /** @param array<int, AttendanceRecord> $events */
    private function validateSequence(array $events): void
    {
        usort($events, fn (AttendanceRecord $a, AttendanceRecord $b) => $this->instant($a)->getTimestamp() <=> $this->instant($b)->getTimestamp());
        $expected = 'check_in';
        $lastInstant = null;
        foreach ($events as $event) {
            $instant = $this->instant($event)->getTimestamp();
            if ($event->action !== $expected || ($lastInstant !== null && $instant <= $lastInstant)) {
                throw ValidationException::withMessages(['action' => 'Attendance punches must alternate check-in and check-out in time order.']);
            }
            $expected = $expected === 'check_in' ? 'check_out' : 'check_in';
            $lastInstant = $instant;
        }
    }

    private function timezoneAt(EmployeeProfile $employee, CarbonImmutable $instant): string
    {
        foreach ($employee->assignments()->with('location')->orderByDesc('starts_on')->get() as $assignment) {
            $timezone = $assignment->location->getAttribute('timezone');
            if (! is_string($timezone) || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                throw ValidationException::withMessages(['timezone' => 'The assignment location has an invalid time zone.']);
            }
            $date = $instant->setTimezone($timezone)->toDateString();
            if ($date >= CarbonImmutable::parse($assignment->starts_on)->toDateString() && ($assignment->ends_on === null || $date <= CarbonImmutable::parse($assignment->ends_on)->toDateString())) {
                return $timezone;
            }
        }

        throw ValidationException::withMessages(['assignment' => 'An effective location assignment is required for attendance.']);
    }

    private function lockEmployee(EmployeeProfile $employee): EmployeeProfile
    {
        return EmployeeProfile::query()->where('company_id', $employee->company_id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
    }

    private function existing(EmployeeProfile $employee, string $key): ?AttendanceRecord
    {
        return AttendanceRecord::query()->where('company_id', $employee->company_id)->where('idempotency_key', $key)->first();
    }

    private function assertRetry(AttendanceRecord $existing, User $actor, EmployeeProfile $employee, string $action, string $source, ?CarbonImmutable $occurredAt = null, ?string $reason = null, ?int $originalId = null): void
    {
        if ($existing->actor_user_id !== $actor->id || $existing->employee_profile_id !== $employee->id || $existing->action !== $action
            || $existing->source !== $source || $existing->correction_of_id !== $originalId
            || ($occurredAt !== null && $this->instant($existing)->getTimestamp() !== $occurredAt->getTimestamp())
            || ($reason !== null && $existing->reason !== $reason)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This key was already used for a different attendance request.']);
        }
    }

    private function validateActionAndKey(string $action, string $key): void
    {
        if (! in_array($action, ['check_in', 'check_out'], true)) {
            throw ValidationException::withMessages(['action' => 'Unsupported attendance action.']);
        }
        if ($key === '' || strlen($key) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'An idempotency key of at most 128 characters is required.']);
        }
    }

    private function validateReasonAndWindow(string $reason, CarbonImmutable $occurredAt): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'A reason of at most 2000 characters is required.']);
        }
        $now = CarbonImmutable::now('UTC');
        if ($occurredAt->lessThan($now->subDays((int) config('hrms.attendance_manual_past_days', 90)))
            || $occurredAt->greaterThan($now->addMinutes((int) config('hrms.attendance_future_minutes', 5)))) {
            throw ValidationException::withMessages(['occurred_at' => 'The attendance time is outside the permitted entry window.']);
        }
    }

    private function manualSource(User $actor): string
    {
        return $actor->role === Role::Manager ? 'manager_manual' : 'hr_manual';
    }

    private function instant(AttendanceRecord $record): CarbonImmutable
    {
        return CarbonImmutable::parse($record->getAttribute('occurred_at'), 'UTC');
    }
}
