<?php

namespace App\Payroll;

use App\Employees\EmployeeProfile;
use App\Leave\LeaveRequest;
use App\Models\User;
use App\Scheduling\AttendanceSummaryService;
use App\Scheduling\ShiftScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function __construct(private readonly AttendanceSummaryService $attendance) {}

    /** @param array<string, mixed> $data */
    public function policy(User $actor, array $data): PayrollPolicy
    {
        $this->authorized($actor, $actor->company_id);

        return DB::transaction(function () use ($actor, $data) {
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();
            $this->checkDates(PayrollPolicy::query()->where('company_id', $actor->company_id), $data['effective_from'], $data['effective_to'] ?? null);

            return PayrollPolicy::create($data + ['company_id' => $actor->company_id, 'actor_user_id' => $actor->id, 'requires_legal_review' => true]);
        });
    }

    /** @param array<string, mixed> $data */
    public function compensation(User $actor, EmployeeProfile $employee, array $data): CompensationAgreement
    {
        $this->authorized($actor, $employee->company_id);

        return DB::transaction(function () use ($actor, $employee, $data) {
            if ($employee->company_id !== $actor->company_id) {
                abort(404);
            }
            EmployeeProfile::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->checkDates(CompensationAgreement::query()->where('company_id', $actor->company_id)->where('employee_profile_id', $employee->id), $data['effective_from'], $data['effective_to'] ?? null);

            return CompensationAgreement::create($data + ['company_id' => $actor->company_id, 'employee_profile_id' => $employee->id, 'actor_user_id' => $actor->id]);
        });
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    private function checkDates(Builder $query, string $from, ?string $to): void
    {
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'End must be after start.']);
        }
        if ($query->where('effective_from', '<=', $to ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'Effective dates overlap an existing version.']);
        }
    }

    /** @param list<int> $employeeIds */
    public function createRun(User $actor, string $month, string $kind, array $employeeIds, ?string $reason = null): PayrollRun
    {
        $this->authorized($actor, $actor->company_id);
        $date = CarbonImmutable::parse($month)->startOfMonth();

        return DB::transaction(function () use ($actor, $date, $kind, $reason, $employeeIds) {
            $selected = EmployeeProfile::where('company_id', $actor->company_id)->whereIn('id', $employeeIds)->pluck('id')->all();
            if (count($selected) !== count(array_unique($employeeIds)) || $selected === []) {
                throw ValidationException::withMessages(['employees' => 'Select employees from your company.']);
            }
            $policy = PayrollPolicy::query()->where('company_id', $actor->company_id)->whereDate('effective_from', '<=', $date->toDateString())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->endOfMonth()->toDateString()))->sole();
            $key = $kind === 'regular' ? 'regular-'.$date->format('Y-m') : 'supplemental-'.$date->format('Y-m').'-'.bin2hex(random_bytes(8));

            return PayrollRun::firstOrCreate(['company_id' => $actor->company_id, 'run_key' => $key], [
                'period_start' => $date->toDateString(), 'kind' => $kind, 'status' => 'draft', 'policy_id' => $policy->id,
                'prepared_by_user_id' => $actor->id, 'reason' => $reason, 'selected_employee_ids' => $selected,
            ]);
        });
    }

    public function review(User $actor, PayrollRun $run, EmployeeProfile $employee, string $date, string $decision, int $amount, string $reason): PayrollAttendanceReview
    {
        $this->authorized($actor, $run->company_id);

        return DB::transaction(function () use ($actor, $run, $employee, $date, $decision, $amount, $reason) {
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->draft($run);
            if ($employee->company_id !== $run->company_id || ! in_array($employee->id, $run->selected_employee_ids, true) || $date < $run->period_start->toDateString() || $date > $run->period_start->endOfMonth()->toDateString()) {
                abort(404);
            }
            $summary = $this->attendance->daily($employee, $date);
            if (! in_array($decision, ['neutral', 'overtime', 'unpaid'], true) || ($decision === 'neutral' && $amount !== 0) || ($decision !== 'neutral' && $amount <= 0)) {
                throw ValidationException::withMessages(['decision' => 'A financial decision needs a positive minor-unit amount.']);
            }
            $review = PayrollAttendanceReview::query()->where('company_id', $run->company_id)->where('run_id', $run->id)
                ->where('employee_profile_id', $employee->id)->whereDate('business_date', $date)->first();
            if (! $review) {
                $review = PayrollAttendanceReview::create(['company_id' => $run->company_id, 'run_id' => $run->id, 'employee_profile_id' => $employee->id,
                    'business_date' => $date, 'source_status' => $summary->status, 'decision' => $decision, 'amount_minor' => $amount,
                    'actor_user_id' => $actor->id, 'reason' => $reason]);
            }
            if (! $review->wasRecentlyCreated && ($review->source_status !== $summary->status || $review->decision !== $decision
                || (int) $review->amount_minor !== $amount || $review->reason !== $reason || (int) $review->actor_user_id !== $actor->id)) {
                throw ValidationException::withMessages(['business_date' => 'This date already has a different financial review. Use a reviewed correction.']);
            }
            if ($review->wasRecentlyCreated) {
                $run->update(['calculated_at' => null]);
            }

            return $review;
        });
    }

    /** @param array<string, mixed> $data */
    public function adjustment(User $actor, PayrollRun $run, EmployeeProfile $employee, array $data): PayrollAdjustment
    {
        $this->authorized($actor, $run->company_id);

        return DB::transaction(function () use ($actor, $run, $employee, $data) {
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->draft($run);
            if ($employee->company_id !== $run->company_id || ! in_array($employee->id, $run->selected_employee_ids, true)) {
                abort(404);
            }
            $adjustment = PayrollAdjustment::firstOrCreate(['company_id' => $run->company_id, 'run_id' => $run->id, 'idempotency_key' => $data['idempotency_key']], $data + [
                'employee_profile_id' => $employee->id, 'actor_user_id' => $actor->id,
            ]);
            if (! $adjustment->wasRecentlyCreated && ((int) $adjustment->employee_profile_id !== $employee->id || $adjustment->kind !== $data['kind']
                || (int) $adjustment->amount_minor !== (int) $data['amount_minor'] || $adjustment->reason !== $data['reason']
                || (int) $adjustment->actor_user_id !== $actor->id)) {
                throw ValidationException::withMessages(['idempotency_key' => 'This adjustment key was used for different details.']);
            }
            if ($adjustment->wasRecentlyCreated) {
                $run->update(['calculated_at' => null]);
            }

            return $adjustment;
        });
    }

    public function calculate(User $actor, PayrollRun $run): void
    {
        $this->authorized($actor, $run->company_id);
        DB::transaction(function () use ($run) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->draft($run);
            $policy = PayrollPolicy::findOrFail($run->policy_id);
            $employees = EmployeeProfile::query()->where('company_id', $run->company_id)->whereIn('id', $run->selected_employee_ids)->get();
            $run->entries()->delete();
            foreach ($employees as $employee) {
                $lines = $this->lines($run, $policy, $employee);
                if ($lines === []) {
                    continue;
                }
                $gross = array_sum(array_column(array_filter($lines, fn ($l) => $l['side'] === 'earning'), 'amount_minor'));
                $deductions = array_sum(array_column(array_filter($lines, fn ($l) => $l['side'] === 'deduction'), 'amount_minor'));
                if ($deductions > $gross) {
                    throw ValidationException::withMessages(['amount' => "Deductions exceed earnings for {$employee->employee_number}."]);
                }
                PayrollEntry::updateOrCreate(['company_id' => $run->company_id, 'run_id' => $run->id, 'employee_profile_id' => $employee->id], [
                    'gross_minor' => $gross, 'deductions_minor' => $deductions, 'net_minor' => $gross - $deductions,
                    'snapshot' => ['employee_number' => $employee->employee_number, 'name' => $employee->name, 'currency' => $policy->currency,
                        'period' => $run->period_start->format('Y-m'), 'policy_id' => $policy->id, 'proration_basis' => $policy->proration_basis, 'lines' => $lines],
                ]);
            }
            $run->update(['calculated_at' => now()]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function lines(PayrollRun $run, PayrollPolicy $policy, EmployeeProfile $employee): array
    {
        $lines = [];
        $month = $run->period_start;
        $days = $month->daysInMonth;
        if ($run->kind === 'regular') {
            $agreements = CompensationAgreement::where('company_id', $run->company_id)->where('employee_profile_id', $employee->id)->get();
            if ($policy->proration_basis === 'scheduled_days') {
                $days = 0;
                for ($d = $month; $d->lte($month->endOfMonth()); $d = $d->addDay()) {
                    if (app(ShiftScheduleService::class)->resolve($employee, $d->toDateString())->status === 'scheduled') {
                        $days++;
                    }
                }
                if ($days === 0) {
                    throw ValidationException::withMessages(['policy' => "No scheduled days for {$employee->employee_number}."]);
                }
            }
            $eligible = 0;
            for ($d = $month; $d->lte($month->endOfMonth()); $d = $d->addDay()) {
                $date = $d->toDateString();
                if ($employee->started_on && $date < $employee->started_on->toDateString()) {
                    continue;
                }
                $assigned = $employee->assignments()->whereDate('starts_on', '<=', $date)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $date))->exists();
                if (! $assigned) {
                    continue;
                }
                if ($policy->proration_basis === 'scheduled_days' && app(ShiftScheduleService::class)->resolve($employee, $date)->status !== 'scheduled') {
                    continue;
                }
                $eligible++;
                $agreement = $agreements->first(fn ($a) => $a->effective_from->toDateString() <= $date && ($a->effective_to === null || $a->effective_to->toDateString() >= $date));
                if (! $agreement || $agreement->currency !== $policy->currency) {
                    throw ValidationException::withMessages(['compensation' => "Missing matching compensation for {$employee->employee_number} on {$date}."]);
                }
                $source = 'agreement:'.$agreement->id;
                $this->accrue($lines, 'Monthly base', 'earning', (int) $agreement->monthly_base_minor, $source, $days);
                foreach ($agreement->components as $component) {
                    $this->accrue($lines, $component['name'], $component['side'], (int) $component['amount_minor'], $source, $days);
                }
            }
            if ($eligible === 0) {
                return [];
            }
        }
        foreach (PayrollAttendanceReview::where('company_id', $run->company_id)->where('run_id', $run->id)->where('employee_profile_id', $employee->id)->get() as $review) {
            if ($review->decision !== 'neutral') {
                $lines[] = ['name' => $review->decision === 'overtime' ? 'Approved overtime' : 'Approved unpaid time', 'side' => $review->decision === 'overtime' ? 'earning' : 'deduction', 'amount_minor' => (int) $review->amount_minor, 'source' => 'attendance-review:'.$review->id, 'reason' => $review->reason];
            }
        }
        foreach (PayrollAdjustment::where('company_id', $run->company_id)->where('run_id', $run->id)->where('employee_profile_id', $employee->id)->get() as $adjustment) {
            $lines[] = ['name' => ucfirst(str_replace('_', ' ', $adjustment->kind)), 'side' => in_array($adjustment->kind, ['bonus', 'correction_credit'], true) ? 'earning' : 'deduction', 'amount_minor' => (int) $adjustment->amount_minor, 'source' => 'adjustment:'.$adjustment->id, 'reason' => $adjustment->reason];
        }

        return array_values($lines);
    }

    /** @param array<string, array<string, mixed>> $lines */
    private function accrue(array &$lines, string $name, string $side, int $monthly, string $source, int $days): void
    {
        $key = $source.'|'.$name.'|'.$side;
        if (! isset($lines[$key])) {
            $lines[$key] = ['name' => $name, 'side' => $side, 'amount_minor' => 0, 'source' => $source, 'monthly_minor' => $monthly, 'eligible_days' => 0];
        }
        $lines[$key]['eligible_days']++;
        $lines[$key]['amount_minor'] = intdiv($monthly * $lines[$key]['eligible_days'] + intdiv($days, 2), $days);
    }

    /** @return list<array{employee: EmployeeProfile, date: string, status: string}> */
    public function unresolved(PayrollRun $run): array
    {
        if ($run->kind !== 'regular') {
            return [];
        }
        $flags = ['no_show_candidate', 'missing_punch', 'partial_leave_review', 'location_conflict', 'invalid_time', 'unscheduled_work', 'rest_day_work', 'leave_with_punch', 'in_progress'];
        $result = [];
        foreach (EmployeeProfile::query()->where('company_id', $run->company_id)->whereIn('id', $run->selected_employee_ids)->get() as $employee) {
            $reviews = PayrollAttendanceReview::where('run_id', $run->id)->where('employee_profile_id', $employee->id)->get()->keyBy(fn ($review) => $review->business_date->toDateString());
            foreach ($this->attendance->month($employee, CarbonImmutable::parse($run->period_start)) as $summary) {
                $date = $summary->schedule->date;
                if ($date > now(config('hrms.display_timezone'))->toDateString()) {
                    continue;
                }
                $leave = LeaveRequest::where('company_id', $run->company_id)->where('employee_profile_id', $employee->id)->where('status', 'approved')->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->whereHas('type', fn ($q) => $q->where('is_paid', false))->exists();
                $status = $leave ? 'unpaid_leave' : ($summary->schedule->scheduledMinutes > 0 && $summary->actualMinutes > $summary->schedule->scheduledMinutes ? 'overtime_candidate' : $summary->status);
                if ((in_array($summary->status, $flags, true) || $leave || $status === 'overtime_candidate') && (! $reviews->has($date) || $reviews[$date]->source_status !== $summary->status)) {
                    $result[] = ['employee' => $employee, 'date' => $date, 'status' => $status];
                }
            }
        }

        return $result;
    }

    public function finalize(User $actor, PayrollRun $run): void
    {
        $this->authorized($actor, $run->company_id);
        DB::transaction(function () use ($actor, $run) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->draft($run);
            if ($run->prepared_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['approval' => 'A second authorized person must approve.']);
            }
            if ($run->period_start->endOfMonth()->toDateString() >= now(config('hrms.display_timezone'))->toDateString()) {
                throw ValidationException::withMessages(['approval' => 'Wait until the payroll month has ended.']);
            }
            if ($run->calculated_at === null || $run->entries()->count() !== count($run->selected_employee_ids) || $this->unresolved($run) !== []) {
                throw ValidationException::withMessages(['approval' => 'Calculate entries and resolve attendance exceptions first.']);
            }
            $policy = PayrollPolicy::query()->findOrFail($run->policy_id);
            foreach ($run->entries as $entry) {
                $employee = EmployeeProfile::query()->whereKey($entry->employee_profile_id)->firstOrFail();
                if ($this->normalizedLines($entry->snapshot['lines']) !== $this->normalizedLines($this->lines($run, $policy, $employee))) {
                    throw ValidationException::withMessages(['approval' => 'Payroll inputs changed. Recalculate and review the draft.']);
                }
            }
            $run->update(['status' => 'finalized', 'approved_by_user_id' => $actor->id, 'approved_at' => now()]);
        });
    }

    public function release(User $actor, PayrollRun $run): void
    {
        $this->authorized($actor, $run->company_id);
        DB::transaction(function () use ($run) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'finalized') {
                throw ValidationException::withMessages(['release' => 'Approve the run first.']);
            }
            if ($run->released_at === null) {
                $run->update(['released_at' => now()]);
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function payment(User $actor, PayrollEntry $entry, array $data): PayrollPayment
    {
        $this->authorized($actor, $entry->company_id);

        return DB::transaction(function () use ($actor, $entry, $data) {
            $entry = PayrollEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $run = PayrollRun::findOrFail($entry->run_id);
            if ($run->status !== 'finalized') {
                throw ValidationException::withMessages(['payment' => 'Approve the run first.']);
            }
            $existing = PayrollPayment::where('company_id', $entry->company_id)->where('entry_id', $entry->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->amount_minor !== (int) $data['amount_minor'] || $existing->paid_on->toDateString() !== $data['paid_on']
                    || $existing->reference !== $data['reference'] || (int) $existing->actor_user_id !== $actor->id) {
                    throw ValidationException::withMessages(['idempotency_key' => 'This payment key was used for different details.']);
                }

                return $existing;
            }
            if ($entry->payments()->where('reference', $data['reference'])->exists()) {
                throw ValidationException::withMessages(['reference' => 'Payment reference already recorded for this payslip.']);
            }
            if ($entry->payments()->sum('amount_minor') + $data['amount_minor'] > $entry->net_minor) {
                throw ValidationException::withMessages(['amount_minor' => 'Payment exceeds net pay.']);
            }

            return PayrollPayment::create($data + ['company_id' => $entry->company_id, 'entry_id' => $entry->id, 'actor_user_id' => $actor->id]);
        });
    }

    private function draft(PayrollRun $run): void
    {
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['run' => 'Finalized payroll is immutable. Create a supplemental run.']);
        }
    }

    private function authorized(User $actor, int $companyId): void
    {
        Gate::forUser($actor)->authorize('payroll-manage');
        abort_unless($actor->company_id === $companyId, 404);
    }

    /**
     * MySQL JSON stores object keys in its own order; line order and typed values remain significant.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function normalizedLines(array $lines): array
    {
        foreach ($lines as &$line) {
            ksort($line);
        }

        return $lines;
    }
}
