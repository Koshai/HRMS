<?php

namespace App\Compliance;

use App\Employees\EmployeeProfile;
use App\Models\User;
use App\Payroll\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ComplianceService
{
    private const CANDIDATES = [
        'BD-WHT-EMPLOYMENT' => 'BD-WHT-2026-AMEND',
        'BD-TAXABLE-EARNINGS' => 'BD-FINANCE-2026',
        'BD-OVERTIME-ELIGIBILITY' => 'BD-LABOUR-2026',
        'BD-WAGE-BENEFITS' => 'BD-LABOUR-2006',
    ];

    public function create(User $actor, string $from, ?string $to): CompliancePack
    {
        $this->authorize($actor, $actor->company_id);
        $start = $this->date($from);
        $end = $to === null ? null : $this->date($to);
        if ($end !== null && $end < $start) {
            throw ValidationException::withMessages(['effective_to' => __('compliance.invalid_window')]);
        }

        return DB::transaction(function () use ($actor, $start, $end) {
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();
            $version = (int) CompliancePack::where('company_id', $actor->company_id)->max('version') + 1;
            $pack = CompliancePack::create(['company_id' => $actor->company_id, 'version' => $version,
                'effective_from' => $start, 'effective_to' => $end, 'status' => 'draft', 'created_by_user_id' => $actor->id]);
            $sources = ComplianceSource::whereIn('code', array_values(self::CANDIDATES))->pluck('id', 'code');
            foreach (self::CANDIDATES as $code => $sourceCode) {
                ComplianceRule::create(['company_id' => $actor->company_id, 'pack_id' => $pack->id, 'source_id' => $sources[$sourceCode],
                    'code' => $code, 'applicability' => 'unresolved', 'review_status' => 'needs_clarification']);
            }
            $this->event($pack, $actor, 'created', ['version' => $version, 'effective_from' => $start, 'effective_to' => $end]);

            return $pack;
        });
    }

    public function submitForReview(User $actor, CompliancePack $pack): void
    {
        $this->authorize($actor, $pack->company_id);
        DB::transaction(function () use ($actor, $pack) {
            $pack = $this->locked($actor, $pack);
            if ($pack->status !== 'draft') {
                throw ValidationException::withMessages(['status' => __('compliance.immutable')]);
            }
            $pack->update(['status' => 'ready_for_legal_review']);
            $this->event($pack, $actor, 'submitted_for_review', ['unresolved_rules' => $pack->rules()->where('review_status', '!=', 'legally_verified')->count()]);
        });
    }

    public function recordApplicability(User $actor, EmployeeProfile $employee, string $from, ?string $to, string $category, string $regime, string $reason): ComplianceApplicability
    {
        $this->authorize($actor, $employee->company_id);
        $start = $this->date($from);
        $end = $to === null ? null : $this->date($to);
        if (($end !== null && $end < $start) || ! in_array($category, ['unresolved', 'statutory_worker_candidate', 'managerial_candidate', 'other_candidate'], true)
            || ! in_array($regime, ['unresolved', 'general_candidate', 'epz_candidate', 'other_candidate'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['applicability' => __('compliance.invalid_applicability')]);
        }

        return DB::transaction(function () use ($actor, $employee, $start, $end, $category, $regime, $reason) {
            EmployeeProfile::where('company_id', $actor->company_id)->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if (ComplianceApplicability::where('company_id', $actor->company_id)->where('employee_profile_id', $employee->id)
                ->where('effective_from', '<=', $end ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start))->exists()) {
                throw ValidationException::withMessages(['effective_from' => __('compliance.overlap')]);
            }

            return ComplianceApplicability::create(['company_id' => $actor->company_id, 'employee_profile_id' => $employee->id,
                'effective_from' => $start, 'effective_to' => $end, 'worker_category' => $category, 'establishment_regime' => $regime,
                'review_status' => 'provisional', 'reason' => trim($reason), 'actor_user_id' => $actor->id]);
        });
    }

    public function attest(User $actor, CompliancePack $pack, string $reviewerName, string $evidenceReference): void
    {
        $this->authorize($actor, $pack->company_id);
        DB::transaction(function () use ($actor, $pack, $reviewerName, $evidenceReference) {
            $pack = $this->locked($actor, $pack);
            if ($pack->status !== 'ready_for_legal_review' || $pack->created_by_user_id === $actor->id
                || trim($reviewerName) === '' || trim($evidenceReference) === '' || $pack->rules()->where('review_status', '!=', 'legally_verified')->exists()) {
                throw ValidationException::withMessages(['review' => __('compliance.review_blocked')]);
            }
            $pack->update(['status' => 'reviewed', 'reviewed_by_user_id' => $actor->id, 'reviewer_name' => trim($reviewerName),
                'evidence_reference' => trim($evidenceReference), 'reviewed_at' => now()]);
            $this->event($pack, $actor, 'attested', ['reviewer_name' => trim($reviewerName), 'evidence_reference' => trim($evidenceReference)]);
        });
    }

    public function activate(User $actor, CompliancePack $pack): void
    {
        $this->authorize($actor, $pack->company_id);
        DB::transaction(function () use ($actor, $pack) {
            $pack = $this->locked($actor, $pack);
            if (! config('compliance.allow_statutory_activation') || $pack->status !== 'reviewed'
                || $pack->reviewed_by_user_id === $actor->id || $pack->rules()->where('review_status', '!=', 'legally_verified')->exists()) {
                throw ValidationException::withMessages(['activation' => __('compliance.activation_blocked')]);
            }
            DB::table('companies')->where('id', $actor->company_id)->lockForUpdate()->first();
            if (CompliancePack::where('company_id', $actor->company_id)->where('status', 'active')->where('effective_from', '<=', $pack->effective_to ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $pack->effective_from))->exists()) {
                throw ValidationException::withMessages(['effective_from' => __('compliance.overlap')]);
            }
            $pack->update(['status' => 'active', 'activated_by_user_id' => $actor->id, 'activated_at' => now()]);
            $this->event($pack, $actor, 'activated', []);
        });
    }

    /** @return array{status: string, period: string, baseline_gross_minor: int, baseline_deductions_minor: int, baseline_net_minor: int, estimated_statutory_minor: null, employee_count: int, warnings: list<string>} */
    public function preview(User $actor, CompliancePack $pack, PayrollRun $run): array
    {
        $this->authorize($actor, $pack->company_id);
        abort_unless($pack->company_id === $run->company_id, 404);
        $date = $run->period_start->toDateString();
        $warnings = [];
        if ($date < $pack->effective_from->toDateString() || ($pack->effective_to !== null && $date > $pack->effective_to->toDateString())) {
            $warnings[] = __('compliance.outside_window');
        }
        foreach ($pack->rules()->with('source')->get() as $rule) {
            $warnings[] = $rule->code.': '.__('compliance.rule_pending').' ['.$rule->source->code.']';
        }
        $entries = $run->entries()->where('company_id', $actor->company_id)->get();
        foreach ($entries as $entry) {
            $status = ComplianceApplicability::where('company_id', $actor->company_id)->where('employee_profile_id', $entry->employee_profile_id)
                ->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->value('review_status');
            $warnings[] = 'Employee #'.$entry->employee_profile_id.': '.($status ?? 'applicability_missing');
        }

        return ['status' => 'needs_review', 'period' => $run->period_start->format('Y-m'),
            'baseline_gross_minor' => (int) $entries->sum('gross_minor'), 'baseline_deductions_minor' => (int) $entries->sum('deductions_minor'),
            'baseline_net_minor' => (int) $entries->sum('net_minor'), 'estimated_statutory_minor' => null,
            'employee_count' => $entries->count(), 'warnings' => $warnings];
    }

    private function date(string $value): string
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        if (! $parsed || $parsed->toDateString() !== $value) {
            throw ValidationException::withMessages(['effective_from' => __('compliance.invalid_window')]);
        }

        return $value;
    }

    private function authorize(User $actor, int $companyId): void
    {
        Gate::forUser($actor)->authorize('payroll-manage');
        abort_unless($actor->company_id === $companyId, 404);
    }

    private function locked(User $actor, CompliancePack $pack): CompliancePack
    {
        return CompliancePack::where('company_id', $actor->company_id)->lockForUpdate()->findOrFail($pack->id);
    }

    /** @param array<string, mixed> $details */
    private function event(CompliancePack $pack, User $actor, string $action, array $details): void
    {
        ComplianceEvent::create(['company_id' => $pack->company_id, 'pack_id' => $pack->id, 'actor_user_id' => $actor->id,
            'action' => $action, 'details' => $details]);
    }
}
