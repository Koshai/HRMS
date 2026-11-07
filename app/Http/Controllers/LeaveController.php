<?php

namespace App\Http\Controllers;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Leave\LeaveRequest;
use App\Leave\LeaveService;
use App\Leave\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeaveController extends Controller
{
    public function mine(Request $request, LeaveService $service): View
    {
        $employee = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return view('leave.unlinked');
        }
        Gate::authorize('leave-view', $employee);
        $query = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $year = (int) ($query['year'] ?? now(config('hrms.display_timezone'))->year);
        $types = LeaveType::query()->where('company_id', $employee->company_id)->orderBy('name')->get();
        $balances = $types->mapWithKeys(fn ($type) => [$type->id => $service->balance($employee, $type, $year)]);
        $requests = LeaveRequest::query()->where('company_id', $employee->company_id)->where('employee_profile_id', $employee->id)
            ->with('type')->orderByDesc('id')->paginate(20);

        return view('leave.mine', compact('employee', 'types', 'balances', 'requests', 'year'));
    }

    public function store(Request $request, LeaveService $service): RedirectResponse
    {
        $data = $request->validate([
            'leave_type_id' => ['required', 'integer'], 'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'], 'partial' => ['nullable', Rule::in(['am', 'pm'])],
            'reason' => ['nullable', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:128'],
            'employee_profile_id' => ['prohibited'], 'company_id' => ['prohibited'],
        ]);
        $employee = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->where('user_id', $request->user()->id)->firstOrFail();
        $type = LeaveType::query()->where('company_id', $employee->company_id)->findOrFail((int) $data['leave_type_id']);
        $service->request($request->user(), $employee, $type, $data['starts_on'], $data['ends_on'], $data['partial'] ?? null, $data['reason'] ?? null, $data['idempotency_key']);

        return redirect()->route('leave.mine')->with('status', __('leave.saved'));
    }

    public function withdraw(Request $request, LeaveService $service, int $leave): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $record = LeaveRequest::query()->where('company_id', $request->user()->company_id)->findOrFail($leave);
        $service->withdraw($request->user(), $record, $data['reason']);

        return redirect()->route('leave.mine')->with('status', __('leave.saved'));
    }

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, [Role::Admin, Role::HR, Role::Manager], true), 403);
        $monthInput = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now(config('hrms.display_timezone'))->format('Y-m');
        $month = CarbonImmutable::createFromFormat('!Y-m', $monthInput);
        $today = CarbonImmutable::now(config('hrms.display_timezone'))->toDateString();
        $profiles = EmployeeProfile::query()->where('company_id', $actor->company_id);
        if ($actor->role === Role::Manager) {
            $profiles->whereHas('assignments', fn ($q) => $q->whereDate('starts_on', '<=', $today)
                ->where(fn ($dates) => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
                ->whereHas('manager', fn ($m) => $m->where('user_id', $actor->id)));
        }
        $requests = LeaveRequest::query()->where('company_id', $actor->company_id)->whereIn('employee_profile_id', $profiles->select('id'))
            ->with(['employee', 'type'])->orderByDesc('id')->paginate(25);
        $calendarRequests = LeaveRequest::query()->where('company_id', $actor->company_id)->whereIn('employee_profile_id', $profiles->select('id'))
            ->where('status', 'approved')->whereDate('starts_on', '<=', $month->endOfMonth()->toDateString())
            ->whereDate('ends_on', '>=', $month->startOfMonth()->toDateString())->with(['employee', 'type'])->get();
        $calendar = [];
        for ($day = $month->startOfMonth(); $day->lte($month->endOfMonth()); $day = $day->addDay()) {
            $date = $day->toDateString();
            $calendar[$date] = $calendarRequests->filter(fn ($leave) => CarbonImmutable::parse($leave->starts_on)->toDateString() <= $date && CarbonImmutable::parse($leave->ends_on)->toDateString() >= $date);
        }

        return view('leave.index', compact('requests', 'calendar', 'monthInput'));
    }

    public function decide(Request $request, LeaveService $service, int $leave): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'max:2000']]);
        $record = LeaveRequest::query()->where('company_id', $request->user()->company_id)->findOrFail($leave);
        $service->decide($request->user(), $record, $data['action'], $data['reason']);

        return redirect()->route('leave.index')->with('status', __('leave.saved'));
    }

    public function cancel(Request $request, LeaveService $service, int $leave): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $record = LeaveRequest::query()->where('company_id', $request->user()->company_id)->findOrFail($leave);
        $service->cancel($request->user(), $record, $data['reason']);

        return redirect()->route('leave.index')->with('status', __('leave.saved'));
    }
}
