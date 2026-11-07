<?php

namespace App\Http\Controllers;

use App\Attendance\AttendanceRecord;
use App\Attendance\AttendanceService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function mine(Request $request, AttendanceService $service): View
    {
        $employee = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->where('user_id', $request->user()->id)->first();
        if ($employee === null) {
            return view('attendance.unlinked');
        }

        return $this->history($request, $service, $employee, true);
    }

    public function punch(Request $request, AttendanceService $service): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['check_in', 'check_out'])],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'occurred_at' => ['prohibited'],
            'occurred_local' => ['prohibited'],
        ]);
        $employee = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->where('user_id', $request->user()->id)->firstOrFail();
        $service->punch($request->user(), $employee, $data['action'], $data['idempotency_key']);

        return redirect()->route('attendance.mine')->with('status', __('attendance.saved'));
    }

    public function index(Request $request, AttendanceService $service): View|RedirectResponse
    {
        $actor = $request->user();
        if (! in_array($actor->role, [Role::Admin, Role::HR, Role::Manager], true)) {
            return redirect()->route('attendance.mine');
        }
        [$from, $to] = $this->range($request);
        $today = CarbonImmutable::now(config('hrms.display_timezone'))->toDateString();
        $search = trim((string) $request->query('search', ''));
        $profiles = EmployeeProfile::query()->where('company_id', $actor->company_id)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('employee_number', 'like', '%'.addcslashes($search, '%_\\').'%')));
        if ($actor->role === Role::Manager) {
            $profiles->whereHas('assignments', fn ($query) => $query->whereDate('starts_on', '<=', $today)
                ->where(fn ($dates) => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
                ->whereHas('manager', fn ($manager) => $manager->where('user_id', $actor->id)));
        }
        $employees = $profiles->orderBy('name')->paginate(20)->withQueryString();
        $summaries = [];
        foreach ($employees as $employee) {
            $events = $service->effectiveEvents($employee)->filter(fn ($event) => $event->getAttribute('effective_business_date') >= $from && $event->getAttribute('effective_business_date') <= $to);
            $summaries[$employee->id] = [
                'check_ins' => $events->where('action', 'check_in')->count(),
                'check_outs' => $events->where('action', 'check_out')->count(),
            ];
        }

        return view('attendance.index', compact('employees', 'summaries', 'from', 'to', 'search'));
    }

    public function show(Request $request, AttendanceService $service, int $employee): View
    {
        $profile = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->findOrFail($employee);
        Gate::authorize('attendance-view', $profile);

        return $this->history($request, $service, $profile, false);
    }

    public function manual(Request $request, AttendanceService $service, int $employee): RedirectResponse
    {
        $profile = $this->employee($request, $employee);
        Gate::authorize('attendance-manual-entry', $profile);
        $data = $request->validate([
            'action' => ['required', Rule::in(['check_in', 'check_out'])],
            'occurred_local' => ['required', 'string'],
            'occurred_offset' => ['nullable', 'regex:/^[+-](0\d|1[0-4]):[0-5]\d$/'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);
        $instant = $service->parseLocalTime($profile, $data['occurred_local'], $data['occurred_offset'] ?? null);
        $service->manual($request->user(), $profile, $data['action'], $instant, $data['reason'], $data['idempotency_key']);

        return redirect()->route('attendance.show', $profile)->with('status', __('attendance.saved'));
    }

    public function correct(Request $request, AttendanceService $service, int $employee, int $record): RedirectResponse
    {
        $profile = $this->employee($request, $employee);
        $original = AttendanceRecord::query()->where('company_id', $profile->company_id)->where('employee_profile_id', $profile->id)->whereNull('correction_of_id')->findOrFail($record);
        Gate::authorize('attendance-propose-correction', $original);
        $data = $request->validate([
            'action' => ['required', Rule::in(['check_in', 'check_out'])],
            'occurred_local' => ['required', 'string'],
            'occurred_offset' => ['nullable', 'regex:/^[+-](0\d|1[0-4]):[0-5]\d$/'],
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);
        $instant = $service->parseLocalTime($profile, $data['occurred_local'], $data['occurred_offset'] ?? null);
        $service->proposeCorrection($request->user(), $original, $data['action'], $instant, $data['reason'], $data['idempotency_key']);

        return redirect()->route('attendance.show', $profile)->with('status', __('attendance.proposed'));
    }

    public function decide(Request $request, AttendanceService $service, int $employee, int $correction): RedirectResponse
    {
        $profile = $this->employee($request, $employee);
        $record = AttendanceRecord::query()->where('company_id', $profile->company_id)->where('employee_profile_id', $profile->id)->whereNotNull('correction_of_id')->findOrFail($correction);
        Gate::authorize('attendance-decide', $record);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $service->decide($request->user(), $record, $data['decision'], $data['reason']);

        return redirect()->route('attendance.show', $profile)->with('status', __('attendance.decided'));
    }

    private function employee(Request $request, int $id): EmployeeProfile
    {
        return EmployeeProfile::query()->where('company_id', $request->user()->company_id)->findOrFail($id);
    }

    private function history(Request $request, AttendanceService $service, EmployeeProfile $employee, bool $mine): View
    {
        Gate::authorize('attendance-view', $employee);
        [$from, $to] = $this->range($request);
        $allEffective = $service->effectiveEvents($employee);
        $effective = $allEffective->filter(fn ($event) => $event->getAttribute('effective_business_date') >= $from && $event->getAttribute('effective_business_date') <= $to);
        $originalIds = $effective->map(fn ($event) => $event->correction_of_id ?? $event->id)->all();
        $records = AttendanceRecord::query()->where('company_id', $employee->company_id)->where('employee_profile_id', $employee->id)
            ->where(fn ($query) => $query->whereIn('id', $originalIds)->orWhereIn('correction_of_id', $originalIds)
                ->orWhere(fn ($dates) => $dates->whereDate('business_date', '>=', $from)->whereDate('business_date', '<=', $to)))
            ->with(['actor', 'decision.actor', 'original', 'corrections.decision'])->orderByDesc('occurred_at')->paginate(30)->withQueryString();
        $latest = $allEffective->last();
        $canPunch = $mine && Gate::forUser($request->user())->allows('attendance-self-punch', $employee) && $service->canPunchNow($employee);

        return view('attendance.show', compact('employee', 'records', 'effective', 'latest', 'from', 'to', 'mine', 'canPunch'));
    }

    /** @return array{string, string} */
    private function range(Request $request): array
    {
        $today = CarbonImmutable::now(config('hrms.display_timezone'))->toDateString();
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $data['from'] ?? CarbonImmutable::parse($today)->subDays(30)->toDateString();
        $to = $data['to'] ?? $today;
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 366) {
            throw ValidationException::withMessages(['to' => 'The date range must be at most one year.']);
        }

        return [$from, $to];
    }
}
