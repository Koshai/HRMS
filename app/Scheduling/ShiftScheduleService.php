<?php

namespace App\Scheduling;

use App\Employees\EmployeeProfile;
use App\Employees\EmploymentAssignment;
use App\Models\User;
use App\Organization\Location;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShiftScheduleService
{
    /** @param array<string, mixed> $data */
    public function createTemplate(User $actor, Location $location, array $data): ShiftTemplate
    {
        Gate::forUser($actor)->authorize('schedule-manage');
        abort_unless($actor->company_id === $location->getAttribute('company_id'), 404);
        $this->timezone($location);
        $start = $this->clock($data['starts_at_local'] ?? '');
        $end = $this->clock($data['ends_at_local'] ?? '');
        $duration = ($end - $start + 1440) % 1440;
        if ($duration === 0 || $duration < 30 || $duration > 960 || (int) $data['unpaid_break_minutes'] >= $duration
            || (int) $data['grace_in_minutes'] > 120 || (int) $data['grace_out_minutes'] > 120) {
            $this->fail('ends_at_local', 'schedule.invalid_duration');
        }
        if (in_array($location->getAttribute('is_active'), [false, 0, '0'], true)) {
            $this->fail('location_id', 'schedule.inactive_location');
        }

        return DB::transaction(function () use ($actor, $location, $data, $end, $start) {
            Gate::forUser($actor)->authorize('schedule-manage');
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();
            if (ShiftTemplate::query()->where('company_id', $actor->company_id)->where('location_id', $location->id)->where('code', $data['code'])->exists()) {
                $this->fail('code', 'schedule.duplicate_code');
            }

            return ShiftTemplate::create(['company_id' => $actor->company_id, 'location_id' => $location->id,
                'code' => $data['code'], 'name' => $data['name'], 'starts_at_local' => $data['starts_at_local'],
                'ends_at_local' => $data['ends_at_local'], 'ends_next_day' => $end < $start,
                'unpaid_break_minutes' => (int) $data['unpaid_break_minutes'],
                'grace_in_minutes' => (int) $data['grace_in_minutes'], 'grace_out_minutes' => (int) $data['grace_out_minutes'],
                'is_active' => true, 'created_by_user_id' => $actor->id]);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateTemplate(User $actor, ShiftTemplate $template, array $data): ShiftTemplate
    {
        Gate::forUser($actor)->authorize('schedule-manage');
        abort_unless($actor->company_id === $template->company_id, 404);
        $start = $this->clock($data['starts_at_local'] ?? '');
        $end = $this->clock($data['ends_at_local'] ?? '');
        $duration = ($end - $start + 1440) % 1440;
        if ($duration < 30 || $duration > 960 || (int) $data['unpaid_break_minutes'] >= $duration
            || (int) $data['grace_in_minutes'] > 120 || (int) $data['grace_out_minutes'] > 120) {
            $this->fail('ends_at_local', 'schedule.invalid_duration');
        }

        return DB::transaction(function () use ($actor, $template, $data, $end, $start) {
            Gate::forUser($actor)->authorize('schedule-manage');
            $current = ShiftTemplate::query()->where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($template->id);
            $changes = ['name' => $data['name'], 'starts_at_local' => $data['starts_at_local'],
                'ends_at_local' => $data['ends_at_local'], 'ends_next_day' => $end < $start,
                'unpaid_break_minutes' => (int) $data['unpaid_break_minutes'],
                'grace_in_minutes' => (int) $data['grace_in_minutes'], 'grace_out_minutes' => (int) $data['grace_out_minutes'],
                'is_active' => (bool) $data['is_active']];
            if ($current->assignments()->exists()) {
                foreach (['starts_at_local', 'ends_at_local', 'ends_next_day', 'unpaid_break_minutes', 'grace_in_minutes', 'grace_out_minutes'] as $field) {
                    $old = (string) $current->getAttribute($field);
                    $new = (string) $changes[$field];
                    if (in_array($field, ['starts_at_local', 'ends_at_local'], true)) {
                        $old = substr($old, 0, 5);
                    }
                    if ($old !== $new) {
                        $this->fail($field, 'schedule.assigned_template_frozen');
                    }
                }
            }
            $current->update($changes);

            return $current;
        });
    }

    /** @param list<int> $weekdays */
    public function assign(User $actor, EmployeeProfile $employee, ShiftTemplate $template, string $start, ?string $end, array $weekdays, string $reason, string $key, ?int $supersedesId = null): ShiftAssignment
    {
        Gate::forUser($actor)->authorize('schedule-manage');
        abort_unless($actor->company_id === $employee->company_id && $actor->company_id === $template->company_id, 404);
        $days = array_values(array_unique(array_map('intval', $weekdays)));
        sort($days);
        if ($days === [] || array_diff($days, range(1, 7)) !== [] || trim($reason) === '' || $key === '' || strlen($key) > 128) {
            $this->fail('weekdays', 'schedule.invalid_assignment');
        }
        if ($end !== null && $end < $start) {
            $this->fail('ends_on', 'schedule.invalid_assignment');
        }

        return DB::transaction(function () use ($actor, $employee, $template, $start, $end, $days, $reason, $key, $supersedesId) {
            $employee = EmployeeProfile::query()->where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($employee->id);
            Gate::forUser($actor)->authorize('schedule-manage');
            $existing = ShiftAssignment::query()->where('company_id', $actor->company_id)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->employee_profile_id !== $employee->id || $existing->shift_template_id !== $template->id
                    || CarbonImmutable::parse($existing->starts_on)->toDateString() !== $start
                    || ($existing->ends_on === null ? null : CarbonImmutable::parse($existing->ends_on)->toDateString()) !== $end
                    || $existing->weekdays !== implode(',', $days) || $existing->reason !== $reason
                    || $existing->supersedes_shift_assignment_id !== $supersedesId) {
                    $this->fail('idempotency_key', 'schedule.retry_conflict');
                }

                return $existing;
            }
            if (! $template->is_active || in_array($template->location?->getAttribute('is_active'), [false, 0, '0'], true)) {
                $this->fail('shift_template_id', 'schedule.inactive_template');
            }
            $today = CarbonImmutable::now($this->timezone($template->location))->toDateString();
            if ($start < $today) {
                $this->fail('starts_on', 'schedule.no_retroactive');
            }
            $employment = $employee->assignments()->whereDate('starts_on', '<=', $start)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $end ?? $start))
                ->where('location_id', $template->location_id)->first();
            if (! $employment || ($end === null && $employment->ends_on !== null)) {
                $this->fail('starts_on', 'schedule.employment_required');
            }
            $prior = null;
            if ($supersedesId !== null) {
                $prior = ShiftAssignment::query()->where('company_id', $actor->company_id)->where('employee_profile_id', $employee->id)
                    ->lockForUpdate()->findOrFail($supersedesId);
                $priorEnd = $prior->ends_on === null ? null : CarbonImmutable::parse($prior->ends_on)->toDateString();
                if (CarbonImmutable::parse($prior->starts_on)->toDateString() >= $start || ($priorEnd !== null && $priorEnd < $start)) {
                    $this->fail('supersedes_shift_assignment_id', 'schedule.invalid_supersession');
                }
                $newEnd = CarbonImmutable::parse($start)->subDay()->toDateString();
                $prior->update(['ends_on' => $newEnd]);
                ShiftAssignmentRevision::create(['company_id' => $actor->company_id, 'shift_assignment_id' => $prior->id,
                    'actor_user_id' => $actor->id, 'previous_ends_on' => $priorEnd, 'new_ends_on' => $newEnd, 'reason' => $reason]);
            }
            $conflicts = ShiftAssignment::query()->where('company_id', $actor->company_id)->where('employee_profile_id', $employee->id)
                ->whereDate('starts_on', '<=', $end ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))->get();
            foreach ($conflicts as $conflict) {
                if (array_intersect($days, array_map('intval', explode(',', $conflict->weekdays))) !== []) {
                    $this->fail('weekdays', 'schedule.overlap');
                }
            }

            return ShiftAssignment::create(['company_id' => $actor->company_id, 'employee_profile_id' => $employee->id,
                'shift_template_id' => $template->id, 'supersedes_shift_assignment_id' => $supersedesId,
                'actor_user_id' => $actor->id, 'starts_on' => $start, 'ends_on' => $end, 'weekdays' => implode(',', $days),
                'reason' => $reason, 'idempotency_key' => $key]);
        });
    }

    public function resolve(EmployeeProfile $employee, string $date): ScheduleResolution
    {
        return $this->range($employee, $date, $date)[$date];
    }

    /** @return array<string, ScheduleResolution> */
    public function range(EmployeeProfile $employee, string $start, string $end): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $start);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $end);
        if (! $first || $first->toDateString() !== $start || ! $last || $last->toDateString() !== $end || $last->lessThan($first)) {
            $this->fail('date', 'schedule.invalid_date');
        }
        $employment = $employee->assignments()->where('company_id', $employee->company_id)
            ->whereDate('starts_on', '<=', $end)->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))
            ->with('location')->orderBy('id')->get();
        $shifts = ShiftAssignment::query()->where('company_id', $employee->company_id)->where('employee_profile_id', $employee->id)
            ->whereDate('starts_on', '<=', $end)->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $start))
            ->with('template.location')->orderBy('id')->get();
        $results = [];
        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            $currentEmployment = $employment->first(fn (EmploymentAssignment $row) => $row->starts_on->toDateString() <= $date
                && ($row->ends_on === null || $row->ends_on->toDateString() >= $date));
            $currentShifts = $shifts->filter(fn (ShiftAssignment $row) => $row->starts_on->toDateString() <= $date
                && ($row->ends_on === null || $row->ends_on->toDateString() >= $date));
            $results[$date] = $this->resolveLoaded($day, $currentEmployment, $currentShifts);
        }

        return $results;
    }

    /** @param Collection<int, ShiftAssignment> $assignments */
    private function resolveLoaded(CarbonImmutable $day, ?EmploymentAssignment $employment, Collection $assignments): ScheduleResolution
    {
        $date = $day->toDateString();
        if (! $employment) {
            return new ScheduleResolution('no_assignment', $date);
        }
        if ($assignments->isEmpty()) {
            return new ScheduleResolution('unscheduled', $date, timezone: $this->timezone($employment->location));
        }
        $assignment = $assignments->first(fn ($row) => in_array($day->dayOfWeekIso, array_map('intval', explode(',', $row->weekdays)), true));
        if (! $assignment) {
            return new ScheduleResolution('rest_day', $date, timezone: $this->timezone($employment->location));
        }
        $template = $assignment->template;
        if ($template->location_id !== $employment->location_id) {
            return new ScheduleResolution('location_conflict', $date, $template, timezone: $this->timezone($employment->location));
        }
        $timezone = $this->timezone($template->location);
        $start = $this->wallInstant($date.'T'.substr($template->starts_at_local, 0, 5), $timezone, false);
        $endDate = $template->ends_next_day ? $day->addDay()->toDateString() : $date;
        $end = $this->wallInstant($endDate.'T'.substr($template->ends_at_local, 0, 5), $timezone, true);
        if (! $start || ! $end || $end->lessThanOrEqualTo($start)) {
            return new ScheduleResolution('invalid_time', $date, $template, timezone: $timezone);
        }
        $minutes = (int) floor(($end->getTimestamp() - $start->getTimestamp()) / 60) - $template->unpaid_break_minutes;

        return new ScheduleResolution('scheduled', $date, $template, $start, $end, $timezone, max(0, $minutes));
    }

    private function wallInstant(string $local, string $timezone, bool $latest): ?CarbonImmutable
    {
        $wall = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $local, 'UTC');
        if (! $wall || $wall->format('Y-m-d\TH:i') !== $local) {
            return null;
        }
        $zone = new DateTimeZone($timezone);
        $transitions = $zone->getTransitions($wall->getTimestamp() - 259200, $wall->getTimestamp() + 259200);
        $offsets = array_unique(array_map(fn (array $transition) => $transition['offset'], $transitions ?: []));
        $offsets[] = $zone->getOffset($wall);
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $instant = CarbonImmutable::createFromTimestamp($wall->getTimestamp() - $offset, 'UTC');
            if ($instant->setTimezone($timezone)->format('Y-m-d\TH:i') === $local) {
                $matches[] = $instant;
            }
        }
        usort($matches, fn ($a, $b) => $a->getTimestamp() <=> $b->getTimestamp());

        return $latest ? (end($matches) ?: null) : ($matches[0] ?? null);
    }

    private function clock(string $value): int
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $matches)) {
            $this->fail('starts_at_local', 'schedule.invalid_clock');
        }

        return (int) $matches[1] * 60 + (int) $matches[2];
    }

    private function timezone(Location $location): string
    {
        $timezone = $location->getAttribute('timezone');
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->fail('location_id', 'schedule.invalid_timezone');
        }

        return $timezone;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
