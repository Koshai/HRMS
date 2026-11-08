<?php

namespace App\Http\Controllers;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Payroll\CompensationAgreement;
use App\Payroll\PayrollAttendanceRecommendation;
use App\Payroll\PayrollAttendanceReview;
use App\Payroll\PayrollEntry;
use App\Payroll\PayrollPolicy;
use App\Payroll\PayrollRun;
use App\Payroll\PayrollService;
use App\Scheduling\AttendanceSummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('payroll-manage');
        $company = $request->user()->company_id;

        return view('payroll.index', [
            'policies' => PayrollPolicy::where('company_id', $company)->orderByDesc('effective_from')->get(),
            'runs' => PayrollRun::where('company_id', $company)->orderByDesc('period_start')->get(),
            'employees' => EmployeeProfile::where('company_id', $company)->orderBy('name')->get(),
        ]);
    }

    public function policy(Request $request, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $data = $request->validate(['effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'currency' => 'required|in:BDT', 'proration_basis' => 'required|in:calendar_days,scheduled_days', 'reason' => 'required|string|max:2000']);
        $service->policy($request->user(), $data);

        return back()->with('status', 'Payroll policy saved. Legal review remains required.');
    }

    public function compensation(Request $request, EmployeeProfile $employee, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->employee($request, $employee);
        $request->merge(['components' => array_values(array_filter($request->input('components', []), fn ($component) => filled($component['name'] ?? null)))]);
        $data = $request->validate(['effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'currency' => 'required|in:BDT',
            'monthly_base_minor' => 'required|integer|min:0|max:100000000000', 'components' => 'array|max:20',
            'components.*.name' => 'required|string|max:80', 'components.*.side' => 'required|in:earning,deduction',
            'components.*.amount_minor' => 'required|integer|min:0|max:100000000000', 'reason' => 'required|string|max:2000']);
        $data['components'] = $data['components'] ?? [];
        $service->compensation($request->user(), $employee, $data);

        return back()->with('status', 'Compensation version saved.');
    }

    public function employeeCompensation(Request $request, EmployeeProfile $employee): View
    {
        Gate::authorize('payroll-manage');
        $this->employee($request, $employee);

        return view('payroll.compensation', ['employee' => $employee, 'agreements' => CompensationAgreement::where('company_id', $employee->company_id)->where('employee_profile_id', $employee->id)->orderByDesc('effective_from')->get()]);
    }

    public function createRun(Request $request, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $data = $request->validate(['month' => 'required|date_format:Y-m', 'kind' => 'required|in:regular,supplemental', 'employee_ids' => 'required|array|min:1', 'employee_ids.*' => 'required|integer', 'reason' => 'required_if:kind,supplemental|nullable|string|max:2000']);
        $run = $service->createRun($request->user(), $data['month'].'-01', $data['kind'], array_values(array_map('intval', $data['employee_ids'])), $data['reason'] ?? null);

        return redirect()->route('payroll.show', $run);
    }

    public function show(Request $request, PayrollRun $run, PayrollService $service): View
    {
        Gate::authorize('payroll-manage');
        $this->run($request, $run);

        return view('payroll.show', ['run' => $run, 'entries' => $run->entries()->get(), 'unresolved' => $service->unresolved($run),
            'reviews' => PayrollAttendanceReview::where('run_id', $run->id)->get(),
            'recommendations' => PayrollAttendanceRecommendation::where('company_id', $run->company_id)->whereIn('employee_profile_id', $run->selected_employee_ids)->whereBetween('business_date', [$run->period_start->toDateString(), $run->period_start->endOfMonth()->toDateString()])->get(),
            'employees' => EmployeeProfile::where('company_id', $run->company_id)->orderBy('name')->get()]);
    }

    public function team(Request $request, AttendanceSummaryService $attendance): View
    {
        if ($request->user()->role === Role::Employee) {
            abort(403);
        }
        $month = $request->validate(['month' => 'nullable|date_format:Y-m'])['month'] ?? now(config('hrms.display_timezone'))->format('Y-m');
        $employees = EmployeeProfile::where('company_id', $request->user()->company_id)->get()->filter(fn ($employee) => Gate::allows('schedule-view', $employee) && $employee->user_id !== $request->user()->id);
        $summaries = [];
        foreach ($employees as $employee) {
            foreach ($attendance->month($employee, CarbonImmutable::parse($month.'-01')) as $day) {
                if (in_array($day->status, ['no_show_candidate', 'missing_punch', 'partial_leave_review', 'location_conflict', 'invalid_time', 'unscheduled_work', 'rest_day_work', 'leave_with_punch'], true)) {
                    $summaries[] = ['employee' => $employee, 'day' => $day];
                }
            }
        }

        return view('payroll.team', ['month' => $month, 'summaries' => $summaries]);
    }

    public function recommend(Request $request, EmployeeProfile $employee, AttendanceSummaryService $attendance): RedirectResponse
    {
        Gate::authorize('schedule-view', $employee);
        $this->employee($request, $employee);
        if ($employee->user_id === $request->user()->id) {
            abort(403);
        }
        $data = $request->validate(['business_date' => 'required|date|before_or_equal:today', 'recommendation' => 'required|string|max:2000']);
        $summary = $attendance->daily($employee, $data['business_date']);
        PayrollAttendanceRecommendation::firstOrCreate(['company_id' => $employee->company_id, 'employee_profile_id' => $employee->id, 'business_date' => $data['business_date'], 'actor_user_id' => $request->user()->id], ['observed_status' => $summary->status, 'recommendation' => $data['recommendation']]);

        return back()->with('status', 'Attendance facts sent to payroll reviewers.');
    }

    public function review(Request $request, PayrollRun $run, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->run($request, $run);
        $data = $request->validate(['employee_profile_id' => 'required|integer', 'business_date' => 'required|date', 'decision' => 'required|in:neutral,overtime,unpaid',
            'amount_minor' => 'required|integer|min:0|max:100000000000', 'reason' => 'required|string|max:2000']);
        $employee = EmployeeProfile::query()->where('company_id', $run->company_id)->whereKey($data['employee_profile_id'])->firstOrFail();
        $service->review($request->user(), $run, $employee, $data['business_date'], $data['decision'], $data['amount_minor'], $data['reason']);

        return back()->with('status', 'Attendance financial decision recorded.');
    }

    public function adjustment(Request $request, PayrollRun $run, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->run($request, $run);
        $data = $request->validate(['employee_profile_id' => 'required|integer', 'kind' => 'required|in:bonus,deduction,manual_tax,advance_recovery,correction_credit,correction_debit',
            'amount_minor' => 'required|integer|min:1|max:100000000000', 'idempotency_key' => 'required|string|max:128', 'reason' => 'required|string|max:2000']);
        $employee = EmployeeProfile::query()->where('company_id', $run->company_id)->whereKey($data['employee_profile_id'])->firstOrFail();
        unset($data['employee_profile_id']);
        $service->adjustment($request->user(), $run, $employee, $data);

        return back()->with('status', 'One-time adjustment recorded.');
    }

    public function calculate(Request $request, PayrollRun $run, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->run($request, $run);
        $service->calculate($request->user(), $run);

        return back()->with('status', 'Draft calculated. Review amounts before approval.');
    }

    public function finalize(Request $request, PayrollRun $run, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->run($request, $run);
        $service->finalize($request->user(), $run);

        return back()->with('status', 'Pay run approved and finalized.');
    }

    public function release(Request $request, PayrollRun $run, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        $this->run($request, $run);
        $service->release($request->user(), $run);

        return back()->with('status', 'Payslips released.');
    }

    public function payment(Request $request, PayrollEntry $entry, PayrollService $service): RedirectResponse
    {
        Gate::authorize('payroll-manage');
        if ($entry->company_id !== $request->user()->company_id) {
            abort(404);
        }
        $data = $request->validate(['amount_minor' => 'required|integer|min:1|max:100000000000', 'paid_on' => 'required|date', 'reference' => 'required|string|max:128', 'idempotency_key' => 'required|string|max:128']);
        $service->payment($request->user(), $entry, $data);

        return back()->with('status', 'Manual payment recorded.');
    }

    public function mine(Request $request): View
    {
        $employee = $request->user()->employeeProfile;
        $entries = $employee ? PayrollEntry::query()->where('company_id', $request->user()->company_id)->where('employee_profile_id', $employee->id)
            ->whereHas('run', fn ($q) => $q->whereNotNull('released_at'))->latest()->get() : collect();

        return view('payroll.mine', ['entries' => $entries]);
    }

    public function payslip(Request $request, PayrollEntry $entry): View
    {
        if ($entry->company_id !== $request->user()->company_id) {
            abort(404);
        }
        $run = $entry->run;
        if ($run->released_at === null) {
            abort(404);
        }
        if (! Gate::allows('payroll-manage') && $entry->employee_profile_id !== $request->user()->employeeProfile?->id) {
            abort(404);
        }

        return view('payroll.payslip', ['entry' => $entry, 'run' => $run]);
    }

    public function export(Request $request, PayrollRun $run): StreamedResponse
    {
        Gate::authorize('payroll-manage');
        $this->run($request, $run);

        return response()->streamDownload(function () use ($run) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Employee number', 'Name', 'Currency', 'Gross minor', 'Deductions minor', 'Net minor', 'Paid minor', 'Outstanding minor']);
            foreach ($run->entries as $entry) {
                $s = $entry->snapshot;
                $paid = $entry->payments()->sum('amount_minor');
                fputcsv($out, [$this->safeCsv((string) $s['employee_number']), $this->safeCsv((string) $s['name']), $s['currency'], $entry->gross_minor, $entry->deductions_minor, $entry->net_minor, $paid, $entry->net_minor - $paid]);
            }
            fclose($out);
        }, 'payroll-'.$run->run_key.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function run(Request $request, PayrollRun $run): void
    {
        if ($run->company_id !== $request->user()->company_id) {
            abort(404);
        }
    }

    private function employee(Request $request, EmployeeProfile $employee): void
    {
        if ($employee->company_id !== $request->user()->company_id) {
            abort(404);
        }
    }

    private function safeCsv(string $text): string
    {
        return preg_match('/^[=+@\-\t\r]/u', $text) ? "'".$text : $text;
    }
}
