<?php

namespace App\Http\Controllers;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Organization\Location;
use App\Scheduling\AttendanceSummaryService;
use App\Scheduling\ShiftAssignment;
use App\Scheduling\ShiftScheduleService;
use App\Scheduling\ShiftTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function mine(Request $request, AttendanceSummaryService $service): View
    {
        $employee = EmployeeProfile::query()->where('company_id', $request->user()->company_id)
            ->where('user_id', $request->user()->id)->first();
        if (! $employee) {
            return view('schedule.unlinked');
        }

        return $this->calendar($request, $service, $employee, true);
    }

    public function index(Request $request): View
    {
        $actor = $request->user();
        abort_unless(in_array($actor->role, [Role::Admin, Role::HR, Role::Manager], true), 403);
        $today = now(config('hrms.display_timezone'))->toDateString();
        $employees = EmployeeProfile::query()->where('company_id', $actor->company_id)
            ->when($actor->role === Role::Manager, fn ($query) => $query->whereHas('assignments', fn ($assignments) => $assignments
                ->whereDate('starts_on', '<=', $today)
                ->where(fn ($dates) => $dates->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
                ->whereHas('manager', fn ($manager) => $manager->where('user_id', $actor->id))))
            ->orderBy('name')->paginate(25);

        return view('schedule.index', compact('employees'));
    }

    public function show(Request $request, AttendanceSummaryService $service, int $employee): View
    {
        $profile = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->findOrFail($employee);

        return $this->calendar($request, $service, $profile, false);
    }

    public function settings(Request $request): View
    {
        Gate::authorize('schedule-manage');
        $company = $request->user()->company_id;
        $locations = Location::query()->where('company_id', $company)->orderBy('name')->get();
        $templates = ShiftTemplate::query()->where('company_id', $company)->with('location')->orderBy('name')->get();
        $employees = EmployeeProfile::query()->where('company_id', $company)->orderBy('name')->get();
        $assignments = ShiftAssignment::query()->where('company_id', $company)->with(['employee', 'template.location'])
            ->orderByDesc('starts_on')->paginate(30);

        return view('schedule.settings', compact('locations', 'templates', 'employees', 'assignments'));
    }

    public function storeTemplate(Request $request, ShiftScheduleService $service): RedirectResponse
    {
        Gate::authorize('schedule-manage');
        $data = $this->templateData($request, false);
        $location = Location::query()->where('company_id', $request->user()->company_id)->findOrFail((int) $data['location_id']);
        $service->createTemplate($request->user(), $location, $data);

        return back()->with('status', __('schedule.saved'));
    }

    public function updateTemplate(Request $request, ShiftScheduleService $service, int $template): RedirectResponse
    {
        Gate::authorize('schedule-manage');
        $record = ShiftTemplate::query()->where('company_id', $request->user()->company_id)->findOrFail($template);
        $data = $this->templateData($request, true);
        $service->updateTemplate($request->user(), $record, $data);

        return back()->with('status', __('schedule.saved'));
    }

    public function assign(Request $request, ShiftScheduleService $service): RedirectResponse
    {
        Gate::authorize('schedule-manage');
        $data = $request->validate([
            'employee_profile_id' => ['required', 'integer'], 'shift_template_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'weekdays' => ['required', 'array', 'min:1'], 'weekdays.*' => ['required', 'integer', 'between:1,7'],
            'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:128'],
            'supersedes_shift_assignment_id' => ['nullable', 'integer'], 'company_id' => ['prohibited'],
        ]);
        $employee = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->findOrFail((int) $data['employee_profile_id']);
        $template = ShiftTemplate::query()->where('company_id', $request->user()->company_id)->findOrFail((int) $data['shift_template_id']);
        $service->assign($request->user(), $employee, $template, $data['starts_on'], $data['ends_on'] ?? null,
            array_values(array_map('intval', $data['weekdays'])), $data['reason'], $data['idempotency_key'],
            isset($data['supersedes_shift_assignment_id']) ? (int) $data['supersedes_shift_assignment_id'] : null);

        return back()->with('status', __('schedule.saved'));
    }

    private function calendar(Request $request, AttendanceSummaryService $service, EmployeeProfile $employee, bool $mine): View
    {
        Gate::authorize('schedule-view', $employee);
        $input = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $input['month'] ?? now(config('hrms.display_timezone'))->format('Y-m');
        $first = CarbonImmutable::createFromFormat('!Y-m', $month);
        if (! $first) {
            throw ValidationException::withMessages(['month' => __('schedule.invalid_date')]);
        }
        $days = $service->month($employee, $first);

        return view('schedule.show', compact('employee', 'days', 'month', 'mine'));
    }

    /** @return array<string, mixed> */
    private function templateData(Request $request, bool $updating): array
    {
        return $request->validate([
            'location_id' => [$updating ? 'prohibited' : 'required', 'integer'],
            'code' => [$updating ? 'prohibited' : 'required', 'alpha_dash', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'starts_at_local' => ['required', 'date_format:H:i'], 'ends_at_local' => ['required', 'date_format:H:i'],
            'unpaid_break_minutes' => ['required', 'integer', 'between:0,960'],
            'grace_in_minutes' => ['required', 'integer', 'between:0,120'],
            'grace_out_minutes' => ['required', 'integer', 'between:0,120'],
            'is_active' => [$updating ? 'required' : 'prohibited', 'boolean'],
            'ends_next_day' => ['prohibited'], 'company_id' => ['prohibited'],
        ]);
    }
}
