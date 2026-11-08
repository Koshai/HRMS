<?php

namespace App\Http\Controllers;

use App\Compliance\CompliancePack;
use App\Compliance\ComplianceService;
use App\Compliance\ComplianceSource;
use App\Employees\EmployeeProfile;
use App\Payroll\PayrollRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ComplianceController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('payroll-manage');

        return view('compliance.index', ['sources' => ComplianceSource::orderBy('code')->get(),
            'packs' => CompliancePack::where('company_id', $request->user()->company_id)->orderByDesc('version')->get(),
            'employees' => EmployeeProfile::where('company_id', $request->user()->company_id)->orderBy('name')->get()]);
    }

    public function store(Request $request, ComplianceService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $data = $request->validate(['effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d']);
        $pack = $service->create($request->user(), $data['effective_from'], $data['effective_to'] ?? null);

        return redirect()->route('compliance.show', $pack)->with('status', __('compliance.created'));
    }

    public function applicability(Request $request, EmployeeProfile $employee, ComplianceService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        abort_unless($request->user()->company_id === $employee->company_id, 404);
        $data = $request->validate(['effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d',
            'worker_category' => 'required|string', 'establishment_regime' => 'required|string', 'reason' => 'required|string|max:1000']);
        $service->recordApplicability($request->user(), $employee, $data['effective_from'], $data['effective_to'] ?? null,
            $data['worker_category'], $data['establishment_regime'], $data['reason']);

        return back()->with('status', __('compliance.applicability_recorded'));
    }

    public function show(Request $request, CompliancePack $pack, ComplianceService $service): View
    {
        Gate::authorize('payroll-manage');
        $this->own($request, $pack);
        $runId = $request->validate(['run_id' => 'nullable|integer'])['run_id'] ?? null;
        $run = $runId === null ? null : PayrollRun::where('company_id', $pack->company_id)->whereKey($runId)->firstOrFail();

        return view('compliance.show', ['pack' => $pack->load(['rules.source', 'events', 'reviewEvidence']),
            'runs' => PayrollRun::where('company_id', $pack->company_id)->orderByDesc('period_start')->get(),
            'run' => $run, 'preview' => $run ? $service->preview($request->user(), $pack, $run) : null,
            'readiness' => $service->readiness($request->user(), $pack)]);
    }

    public function evidence(Request $request, CompliancePack $pack, ComplianceService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->own($request, $pack);
        $data = $request->validate(['kind' => 'required|in:official_source,legal_interpretation,applicability,worked_case,reviewer_credential',
            'reference' => 'required|string|max:500', 'sha256' => 'required|regex:/^[a-fA-F0-9]{64}$/',
            'rule_id' => 'nullable|integer', 'source_id' => 'nullable|integer']);
        $service->supplyEvidence($request->user(), $pack, $data['kind'], $data['reference'], $data['sha256'],
            $data['rule_id'] ?? null, $data['source_id'] ?? null);

        return back()->with('status', __('compliance.evidence_recorded'));
    }

    public function submit(Request $request, CompliancePack $pack, ComplianceService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->own($request, $pack);
        $service->submitForReview($request->user(), $pack);

        return back()->with('status', __('compliance.submitted'));
    }

    public function attest(Request $request, CompliancePack $pack, ComplianceService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->own($request, $pack);
        $data = $request->validate(['reviewer_name' => 'required|string|max:200', 'evidence_reference' => 'required|string|max:500']);
        $service->attest($request->user(), $pack, $data['reviewer_name'], $data['evidence_reference']);

        return back()->with('status', __('compliance.attested'));
    }

    public function activate(Request $request, CompliancePack $pack, ComplianceService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->own($request, $pack);
        $service->activate($request->user(), $pack);

        return back()->with('status', __('compliance.activated'));
    }

    private function own(Request $request, CompliancePack $pack): void
    {
        abort_unless($request->user()->company_id === $pack->company_id, 404);
    }
}
