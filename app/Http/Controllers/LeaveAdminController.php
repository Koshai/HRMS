<?php

namespace App\Http\Controllers;

use App\Employees\EmployeeProfile;
use App\Leave\LeaveBalanceEntry;
use App\Leave\LeaveHoliday;
use App\Leave\LeavePolicy;
use App\Leave\LeaveService;
use App\Leave\LeaveType;
use App\Organization\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeaveAdminController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('leave-configure');
        $company = $request->user()->company_id;
        $types = LeaveType::query()->where('company_id', $company)->orderBy('name')->get();
        $locations = Location::query()->where('company_id', $company)->orderBy('name')->get();
        $policies = LeavePolicy::query()->where('company_id', $company)->orderByDesc('effective_from')->get();
        $holidays = LeaveHoliday::query()->where('company_id', $company)->orderByDesc('holiday_on')->limit(50)->get();
        $employees = EmployeeProfile::query()->where('company_id', $company)->orderBy('name')->get();
        $entries = LeaveBalanceEntry::query()->where('company_id', $company)->orderByDesc('id')->limit(50)->get();

        return view('leave.admin', compact('types', 'locations', 'policies', 'holidays', 'employees', 'entries'));
    }

    public function type(Request $request): RedirectResponse
    {
        Gate::authorize('leave-configure');
        $data = $request->validate(['code' => ['required', 'alpha_dash', 'max:32', Rule::unique('leave_types')->where('company_id', $request->user()->company_id)],
            'name' => ['required', 'string', 'max:255'], 'is_paid' => ['required', 'boolean']]);
        LeaveType::create($data + ['company_id' => $request->user()->company_id, 'is_active' => true]);

        return back()->with('status', __('leave.saved'));
    }

    public function updateType(Request $request, int $type): RedirectResponse
    {
        Gate::authorize('leave-configure');
        $record = LeaveType::query()->where('company_id', $request->user()->company_id)->findOrFail($type);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'is_active' => ['required', 'boolean']]);
        $record->update($data);

        return back()->with('status', __('leave.saved'));
    }

    public function policy(Request $request, LeaveService $service): RedirectResponse
    {
        Gate::authorize('leave-configure');
        $data = $request->validate(['leave_type_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'annual_units' => ['required', 'integer', 'min:0', 'max:730'], 'allow_half_day' => ['required', 'boolean'],
            'allow_negative' => ['required', 'boolean'], 'approval_role' => ['required', Rule::in(['manager', 'hr'])],
            'working_weekdays' => ['required', 'array', 'min:1'], 'working_weekdays.*' => ['required', 'integer', 'between:1,7']]);
        $type = LeaveType::query()->where('company_id', $request->user()->company_id)->findOrFail((int) $data['leave_type_id']);
        $service->createPolicy($request->user(), $type, (int) $data['location_id'], $data);

        return back()->with('status', __('leave.saved'));
    }

    public function holiday(Request $request): RedirectResponse
    {
        Gate::authorize('leave-configure');
        $data = $request->validate(['location_id' => ['required', 'integer'], 'holiday_on' => ['required', 'date_format:Y-m-d'], 'name' => ['required', 'string', 'max:255']]);
        Location::query()->where('company_id', $request->user()->company_id)->findOrFail($data['location_id']);
        $exists = LeaveHoliday::query()->where('company_id', $request->user()->company_id)->where('location_id', $data['location_id'])
            ->whereDate('holiday_on', $data['holiday_on'])->exists();
        if (! $exists) {
            LeaveHoliday::create(['company_id' => $request->user()->company_id, 'location_id' => $data['location_id'],
                'holiday_on' => $data['holiday_on'], 'name' => $data['name']]);
        }

        return back()->with('status', __('leave.saved'));
    }

    public function grant(Request $request, LeaveService $service): RedirectResponse
    {
        Gate::authorize('leave-configure');
        $data = $request->validate(['employee_profile_id' => ['required', 'integer'], 'leave_type_id' => ['required', 'integer'],
            'leave_year' => ['required', 'integer', 'between:2000,2100'], 'units' => ['required', 'integer', 'between:-730,730', 'not_in:0'],
            'kind' => ['required', Rule::in(['grant', 'adjustment'])], 'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:128']]);
        $employee = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->findOrFail((int) $data['employee_profile_id']);
        $type = LeaveType::query()->where('company_id', $request->user()->company_id)->findOrFail((int) $data['leave_type_id']);
        $service->grant($request->user(), $employee, $type, (int) $data['leave_year'], (int) $data['units'], $data['reason'], $data['idempotency_key'], $data['kind']);

        return back()->with('status', __('leave.saved'));
    }
}
