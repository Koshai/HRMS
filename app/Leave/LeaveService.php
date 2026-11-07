<?php

namespace App\Leave;

use App\Employees\EmployeeProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    /** @param array<string, mixed> $data */
    public function createPolicy(User $actor, LeaveType $type, int $locationId, array $data): LeavePolicy
    {
        Gate::forUser($actor)->authorize('leave-configure');
        abort_unless($type->company_id === $actor->company_id, 404);

        return DB::transaction(function () use ($actor, $type, $locationId, $data) {
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();
            abort_unless(DB::table('locations')->where('company_id', $actor->company_id)->where('id', $locationId)->exists(), 404);
            $from = $data['effective_from'];
            $to = $data['effective_to'] ?? null;
            if ($to !== null && $to < $from) {
                $this->fail('effective_to', 'leave.invalid_range');
            }
            $priorOpen = LeavePolicy::query()->where('company_id', $actor->company_id)->where('leave_type_id', $type->id)
                ->where('location_id', $locationId)->whereNull('effective_to')->whereDate('effective_from', '<', $from)
                ->lockForUpdate()->first();
            if ($priorOpen) {
                $priorOpen->update(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
            }
            $conflict = LeavePolicy::query()->where('company_id', $actor->company_id)->where('leave_type_id', $type->id)
                ->where('location_id', $locationId)->whereDate('effective_from', '<=', $to ?? '9999-12-31')
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))->exists();
            if ($conflict) {
                $this->fail('effective_from', 'leave.policy_overlap');
            }
            $days = array_values(array_unique(array_map('intval', $data['working_weekdays'])));
            sort($days);
            if ($days === [] || count(array_diff($days, range(1, 7))) > 0) {
                $this->fail('working_weekdays', 'leave.invalid_weekdays');
            }

            return LeavePolicy::create([
                'company_id' => $actor->company_id, 'leave_type_id' => $type->id, 'location_id' => $locationId,
                'effective_from' => $from, 'effective_to' => $to, 'annual_units' => (int) $data['annual_units'],
                'allow_half_day' => (bool) ($data['allow_half_day'] ?? false), 'allow_negative' => (bool) ($data['allow_negative'] ?? false),
                'approval_role' => $data['approval_role'], 'working_weekdays' => implode(',', $days),
            ]);
        });
    }

    /** @return array{granted: int, used: int, pending: int, available: int} */
    public function balance(EmployeeProfile $employee, LeaveType $type, int $year): array
    {
        abort_unless($employee->company_id === $type->company_id, 404);
        $entries = LeaveBalanceEntry::query()->where('company_id', $employee->company_id)->where('employee_profile_id', $employee->id)
            ->where('leave_type_id', $type->id)->where('leave_year', $year);
        $granted = (clone $entries)->where('units', '>', 0)->where('kind', '!=', 'reversal')->sum('units');
        $used = -(clone $entries)->where('kind', 'consumption')->sum('units');
        $restored = (clone $entries)->where('kind', 'reversal')->sum('units');
        $pending = (int) DB::table('leave_request_charges as charges')->join('leave_requests as requests', function ($join) {
            $join->on('requests.id', '=', 'charges.leave_request_id')->on('requests.company_id', '=', 'charges.company_id');
        })->where('charges.company_id', $employee->company_id)->where('requests.employee_profile_id', $employee->id)
            ->where('requests.leave_type_id', $type->id)->where('charges.leave_year', $year)->where('requests.status', 'pending')->sum('charges.units');
        $net = (int) (clone $entries)->sum('units');

        return ['granted' => (int) $granted, 'used' => (int) $used - (int) $restored, 'pending' => $pending, 'available' => $net - $pending];
    }

    public function grant(User $actor, EmployeeProfile $employee, LeaveType $type, int $year, int $units, string $reason, string $key, string $kind = 'grant'): LeaveBalanceEntry
    {
        Gate::forUser($actor)->authorize('leave-configure');
        abort_unless($actor->company_id === $employee->company_id && $type->company_id === $actor->company_id, 404);
        if ($units === 0 || trim($reason) === '' || ! in_array($kind, ['grant', 'adjustment'], true)) {
            $this->fail('units', 'leave.invalid_adjustment');
        }
        if ($kind === 'grant' && $units < 0) {
            $this->fail('units', 'leave.invalid_adjustment');
        }

        return DB::transaction(function () use ($actor, $employee, $type, $year, $units, $reason, $key, $kind) {
            Gate::forUser($actor)->authorize('leave-configure');
            EmployeeProfile::query()->where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($employee->id);
            $source = $kind.':'.$key;
            $existing = LeaveBalanceEntry::query()->where('company_id', $actor->company_id)->where('source_key', $source)->first();
            if ($existing) {
                if ($existing->employee_profile_id !== $employee->id || $existing->leave_type_id !== $type->id || $existing->leave_year !== $year || $existing->units !== $units || $existing->reason !== $reason) {
                    $this->fail('idempotency_key', 'leave.retry_conflict');
                }

                return $existing;
            }
            if ($kind === 'adjustment' && $units < 0 && $this->balance($employee, $type, $year)['available'] + $units < 0) {
                $this->fail('units', 'leave.insufficient');
            }
            $yearStart = $year.'-01-01';
            $yearEnd = $year.'-12-31';
            $assignment = $employee->assignments()->whereDate('starts_on', '<=', $yearEnd)
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $yearStart))
                ->orderBy('starts_on')->first();
            if (! $assignment) {
                $this->fail('leave_year', 'leave.assignment_required');
            }
            $policyDate = max($yearStart, CarbonImmutable::parse($assignment->starts_on)->toDateString());
            $policy = LeavePolicy::query()->where('company_id', $actor->company_id)->where('leave_type_id', $type->id)
                ->where('location_id', $assignment->location_id)->whereDate('effective_from', '<=', $policyDate)
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $policyDate))->first();
            if (! $policy) {
                $this->fail('leave_year', 'leave.policy_required');
            }
            $grants = (int) LeaveBalanceEntry::query()->where('company_id', $actor->company_id)->where('employee_profile_id', $employee->id)
                ->where('leave_type_id', $type->id)->where('leave_year', $year)->where('kind', 'grant')->sum('units');
            if ($kind === 'grant' && $grants + $units > $policy->annual_units) {
                $this->fail('units', 'leave.grant_limit');
            }

            return LeaveBalanceEntry::create(['company_id' => $actor->company_id, 'employee_profile_id' => $employee->id,
                'leave_type_id' => $type->id, 'leave_policy_id' => $policy->id, 'actor_user_id' => $actor->id, 'leave_year' => $year,
                'units' => $units, 'kind' => $kind, 'source_key' => $source, 'reason' => $reason]);
        });
    }

    public function request(User $actor, EmployeeProfile $employee, LeaveType $type, string $start, string $end, ?string $partial, ?string $reason, string $key): LeaveRequest
    {
        Gate::forUser($actor)->authorize('leave-request', $employee);
        abort_unless($type->company_id === $actor->company_id, 404);
        if (! $type->is_active) {
            $this->fail('leave_type_id', 'leave.inactive_type');
        }
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);
        if ($to->lt($from) || $from->diffInDays($to) > 366 || ($partial !== null && ($start !== $end || ! in_array($partial, ['am', 'pm'], true)))) {
            $this->fail('ends_on', 'leave.invalid_range');
        }

        return DB::transaction(function () use ($actor, $employee, $type, $from, $to, $partial, $reason, $key, $start, $end) {
            Gate::forUser($actor)->authorize('leave-request', $employee);
            EmployeeProfile::query()->where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($employee->id);
            $existing = LeaveRequest::query()->where('company_id', $actor->company_id)->where('requester_user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->employee_profile_id !== $employee->id || $existing->leave_type_id !== $type->id || CarbonImmutable::parse($existing->starts_on)->toDateString() !== $start || CarbonImmutable::parse($existing->ends_on)->toDateString() !== $end || $existing->partial !== $partial || $existing->reason !== $reason) {
                    $this->fail('idempotency_key', 'leave.retry_conflict');
                }

                return $existing;
            }
            $assignment = $employee->assignments()->whereDate('starts_on', '<=', $start)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $end))->first();
            if (! $assignment) {
                $this->fail('starts_on', 'leave.assignment_required');
            }
            $overlap = LeaveRequest::query()->where('company_id', $actor->company_id)->where('employee_profile_id', $employee->id)
                ->whereIn('status', ['pending', 'approved'])->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)
                ->get()->contains(fn ($other) => ! ($start === $end && CarbonImmutable::parse($other->starts_on)->toDateString() === $start
                    && CarbonImmutable::parse($other->ends_on)->toDateString() === $end && $partial !== null && $other->partial !== null && $partial !== $other->partial));
            if ($overlap) {
                $this->fail('starts_on', 'leave.overlap');
            }
            $charges = [];
            $day = $from;
            while ($day->lte($to)) {
                $date = $day->toDateString();
                $policy = LeavePolicy::query()->where('company_id', $actor->company_id)->where('leave_type_id', $type->id)
                    ->where('location_id', $assignment->location_id)->whereDate('effective_from', '<=', $date)
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->first();
                if (! $policy) {
                    $this->fail('starts_on', 'leave.policy_required');
                }
                if ($partial !== null && ! $policy->allow_half_day) {
                    $this->fail('partial', 'leave.half_day_disabled');
                }
                $weekdays = array_map('intval', explode(',', $policy->working_weekdays));
                $holiday = LeaveHoliday::query()->where('company_id', $actor->company_id)->where('location_id', $assignment->location_id)->whereDate('holiday_on', $date)->exists();
                if (in_array($day->dayOfWeekIso, $weekdays, true) && ! $holiday) {
                    $index = $day->year.':'.$policy->id;
                    $charges[$index] ??= ['year' => $day->year, 'policy' => $policy, 'units' => 0];
                    $charges[$index]['units'] += $partial === null ? 2 : 1;
                }
                $day = $day->addDay();
            }
            if ($charges === []) {
                $this->fail('starts_on', 'leave.no_workdays');
            }
            $byYear = [];
            $approvalRoute = null;
            foreach ($charges as $charge) {
                $approvalRoute ??= $charge['policy']->approval_role;
                if ($approvalRoute !== $charge['policy']->approval_role) {
                    $this->fail('starts_on', 'leave.approval_route_mismatch');
                }
                $year = $charge['year'];
                $byYear[$year] ??= ['units' => 0, 'allow_negative' => true];
                $byYear[$year]['units'] += $charge['units'];
                $byYear[$year]['allow_negative'] = $byYear[$year]['allow_negative'] && $charge['policy']->allow_negative;
            }
            foreach ($byYear as $year => $charge) {
                if (! $charge['allow_negative'] && $this->balance($employee, $type, $year)['available'] < $charge['units']) {
                    $this->fail('starts_on', 'leave.insufficient');
                }
            }
            $request = LeaveRequest::create(['company_id' => $actor->company_id, 'employee_profile_id' => $employee->id,
                'leave_type_id' => $type->id, 'location_id' => $assignment->location_id, 'requester_user_id' => $actor->id,
                'starts_on' => $start, 'ends_on' => $end, 'partial' => $partial, 'units' => array_sum(array_column($charges, 'units')),
                'status' => 'pending', 'reason' => $reason, 'idempotency_key' => $key]);
            foreach ($charges as $charge) {
                LeaveRequestCharge::create(['company_id' => $actor->company_id, 'leave_request_id' => $request->id,
                    'leave_policy_id' => $charge['policy']->id, 'leave_year' => $charge['year'], 'units' => $charge['units']]);
            }

            return $request;
        });
    }

    public function decide(User $actor, LeaveRequest $request, string $action, string $reason): LeaveRequest
    {
        return $this->transition($actor, $request, $action, $reason);
    }

    public function withdraw(User $actor, LeaveRequest $request, string $reason): LeaveRequest
    {
        return $this->transition($actor, $request, 'withdrawn', $reason);
    }

    public function cancel(User $actor, LeaveRequest $request, string $reason): LeaveRequest
    {
        return $this->transition($actor, $request, 'cancelled', $reason);
    }

    private function transition(User $actor, LeaveRequest $request, string $action, string $reason): LeaveRequest
    {
        if (trim($reason) === '') {
            $this->fail('reason', 'leave.reason_required');
        }
        if (! in_array($action, ['approved', 'rejected', 'withdrawn', 'cancelled'], true)) {
            $this->fail('action', 'leave.invalid_action');
        }

        return DB::transaction(function () use ($actor, $request, $action, $reason) {
            EmployeeProfile::query()->where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($request->employee_profile_id);
            $current = LeaveRequest::query()->where('company_id', $actor->company_id)->with(['employee', 'charges.policy'])->findOrFail($request->id);
            if ($action === 'withdrawn') {
                Gate::forUser($actor)->authorize('leave-request', $current->employee);
            } elseif ($action === 'cancelled') {
                Gate::forUser($actor)->authorize('leave-cancel', $current);
            } else {
                Gate::forUser($actor)->authorize('leave-decide', $current);
            }
            if (($action === 'cancelled' ? 'approved' : 'pending') !== $current->status) {
                $this->fail('action', 'leave.invalid_state');
            }
            if ($action === 'approved') {
                foreach ($current->charges as $charge) {
                    $balance = $this->balance($current->employee, $current->type, $charge->leave_year);
                    if (! $charge->policy->allow_negative && $balance['available'] < 0) {
                        $this->fail('action', 'leave.insufficient');
                    }
                    LeaveBalanceEntry::create(['company_id' => $actor->company_id, 'employee_profile_id' => $current->employee_profile_id,
                        'leave_type_id' => $current->leave_type_id, 'leave_policy_id' => $charge->leave_policy_id, 'leave_request_id' => $current->id, 'actor_user_id' => $actor->id,
                        'leave_year' => $charge->leave_year, 'units' => -$charge->units, 'kind' => 'consumption',
                        'source_key' => 'approve:'.$charge->id, 'reason' => $reason]);
                }
            } elseif ($action === 'cancelled') {
                foreach ($current->charges as $charge) {
                    LeaveBalanceEntry::create(['company_id' => $actor->company_id, 'employee_profile_id' => $current->employee_profile_id,
                        'leave_type_id' => $current->leave_type_id, 'leave_policy_id' => $charge->leave_policy_id, 'leave_request_id' => $current->id, 'actor_user_id' => $actor->id,
                        'leave_year' => $charge->leave_year, 'units' => $charge->units, 'kind' => 'reversal',
                        'source_key' => 'cancel:'.$charge->id, 'reason' => $reason]);
                }
            }
            LeaveDecision::create(['company_id' => $actor->company_id, 'leave_request_id' => $current->id,
                'actor_user_id' => $actor->id, 'action' => $action, 'reason' => $reason]);
            $current->update(['status' => $action]);

            return $current;
        });
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __($message)]);
    }
}
