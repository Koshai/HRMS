<?php

namespace App\Http\Controllers;

use App\Employees\AssignmentService;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use App\Organization\Company;
use App\Organization\Department;
use App\Organization\Location;
use App\Organization\Position;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class EmployeeAdminController extends Controller
{
    private function authorize(Request $request): void
    {
        Gate::authorize('manage-staff', Company::findOrFail($request->user()->company_id));
    }

    private function employee(Request $request, int $employee): EmployeeProfile
    {
        $profile = EmployeeProfile::query()->where('company_id', $request->user()->company_id)->findOrFail($employee);
        if ($request->user()->role === Role::HR && $profile->user?->role === Role::Admin) {
            abort(403);
        }

        return $profile;
    }

    public function create(Request $request): View
    {
        $this->authorize($request);

        return view('admin.employee-form', ['employee' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize($request);
        $data = $this->profileData($request);
        $employee = EmployeeProfile::create($data + ['company_id' => $request->user()->company_id]);

        return redirect()->route('employees.show', $employee)->with('status', __('admin.saved'));
    }

    public function edit(Request $request, int $employee): View
    {
        $this->authorize($request);

        return view('admin.employee-form', ['employee' => $this->employee($request, $employee)]);
    }

    public function update(Request $request, int $employee): RedirectResponse
    {
        $this->authorize($request);
        $profile = $this->employee($request, $employee);
        $data = $this->profileData($request, $profile);
        if ($profile->user_id === $request->user()->id && $data['status'] === 'inactive') {
            throw ValidationException::withMessages(['status' => 'You cannot deactivate your own employee profile.']);
        }
        $profile->update($data);

        return redirect()->route('employees.show', $profile)->with('status', __('admin.saved'));
    }

    /** @return array<string, mixed> */
    private function profileData(Request $request, ?EmployeeProfile $employee = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'employee_number' => ['required', 'string', 'max:64', Rule::unique('employee_profiles', 'employee_number')->where('company_id', $request->user()->company_id)->ignore($employee?->id)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'started_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    public function assignment(Request $request, int $employee): View
    {
        $this->authorize($request);
        $profile = $this->employee($request, $employee);
        $company = $request->user()->company_id;

        return view('admin.assignment-form', [
            'employee' => $profile,
            'assignment' => null,
            'departments' => Department::where('company_id', $company)->where('is_active', true)->orderBy('name')->get(),
            'positions' => Position::where('company_id', $company)->where('is_active', true)->orderBy('name')->get(),
            'locations' => Location::where('company_id', $company)->where('is_active', true)->orderBy('name')->get(),
            'managers' => EmployeeProfile::where('company_id', $company)->where('status', 'active')->where('id', '!=', $profile->id)->orderBy('name')->get(),
        ]);
    }

    public function editAssignment(Request $request, int $employee, int $assignment): View
    {
        $this->authorize($request);
        $profile = $this->employee($request, $employee);
        $record = $profile->assignments()->findOrFail($assignment);
        $company = $request->user()->company_id;

        return view('admin.assignment-form', [
            'employee' => $profile,
            'assignment' => $record,
            'departments' => Department::where('company_id', $company)->where(fn ($query) => $query->where('is_active', true)->orWhereKey($record->department_id))->orderBy('name')->get(),
            'positions' => Position::where('company_id', $company)->where(fn ($query) => $query->where('is_active', true)->orWhereKey($record->position_id))->orderBy('name')->get(),
            'locations' => Location::where('company_id', $company)->where(fn ($query) => $query->where('is_active', true)->orWhereKey($record->location_id))->orderBy('name')->get(),
            'managers' => EmployeeProfile::where('company_id', $company)->where('id', '!=', $profile->id)->where(fn ($query) => $query->where('status', 'active')->orWhereKey($record->manager_employee_profile_id))->orderBy('name')->get(),
        ]);
    }

    public function assign(Request $request, int $employee, AssignmentService $service): RedirectResponse
    {
        $this->authorize($request);
        $profile = $this->employee($request, $employee);
        $company = $request->user()->company_id;
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('company_id', $company)->where('is_active', true)],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->where('company_id', $company)->where('is_active', true)],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('company_id', $company)->where('is_active', true)],
            'manager_employee_profile_id' => ['nullable', 'integer', Rule::exists('employee_profiles', 'id')->where('company_id', $company)->where('status', 'active')],
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ]);

        $service->transfer(
            $profile,
            Department::query()->whereKey($data['department_id'])->firstOrFail(),
            Position::query()->whereKey($data['position_id'])->firstOrFail(),
            Location::query()->whereKey($data['location_id'])->firstOrFail(),
            CarbonImmutable::parse($data['starts_on']),
            $request->user(),
            isset($data['manager_employee_profile_id']) ? EmployeeProfile::query()->whereKey($data['manager_employee_profile_id'])->firstOrFail() : null,
        );

        return redirect()->route('employees.show', $profile)->with('status', __('admin.saved'));
    }

    public function updateAssignment(Request $request, int $employee, int $assignment, AssignmentService $service): RedirectResponse
    {
        $this->authorize($request);
        $profile = $this->employee($request, $employee);
        $record = $profile->assignments()->findOrFail($assignment);
        $company = $request->user()->company_id;
        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('company_id', $company)],
            'position_id' => ['required', 'integer', Rule::exists('positions', 'id')->where('company_id', $company)],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('company_id', $company)],
            'manager_employee_profile_id' => ['nullable', 'integer', Rule::exists('employee_profiles', 'id')->where('company_id', $company)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        $service->revise(
            $record,
            $request->user(),
            Department::query()->whereKey($data['department_id'])->firstOrFail(),
            Position::query()->whereKey($data['position_id'])->firstOrFail(),
            Location::query()->whereKey($data['location_id'])->firstOrFail(),
            CarbonImmutable::parse($data['starts_on']),
            isset($data['ends_on']) ? CarbonImmutable::parse($data['ends_on']) : null,
            isset($data['manager_employee_profile_id']) ? EmployeeProfile::query()->whereKey($data['manager_employee_profile_id'])->firstOrFail() : null,
        );

        return redirect()->route('employees.show', $profile)->with('status', __('admin.saved'));
    }

    public function provision(Request $request, int $employee): RedirectResponse
    {
        $this->authorize($request);
        $profile = $this->employee($request, $employee);
        if ($profile->user_id !== null) {
            throw ValidationException::withMessages(['email' => __('admin.already_linked')]);
        }

        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'role' => ['required', Rule::in([Role::Employee->value, Role::Manager->value, Role::HR->value, Role::Admin->value])],
        ]);
        if ($data['role'] === Role::Admin->value && $request->user()->role !== Role::Admin) {
            abort(403);
        }

        $email = Str::lower(trim($data['email']));
        DB::transaction(function () use ($profile, $email, $data) {
            $locked = EmployeeProfile::whereKey($profile->id)->lockForUpdate()->firstOrFail();
            if ($locked->user_id !== null) {
                throw ValidationException::withMessages(['email' => __('admin.already_linked')]);
            }
            $user = new User(['name' => $profile->name, 'email' => $email, 'password' => Str::random(64)]);
            $user->company_id = $profile->company_id;
            $user->role = Role::from($data['role']);
            $user->save();
            $locked->update(['user_id' => $user->id]);
        });

        $status = Password::sendResetLink(['email' => $email]);
        if ($status !== Password::RESET_LINK_SENT) {
            return redirect()->route('employees.show', $profile)->with('status', __('admin.invite_retry'));
        }

        return redirect()->route('employees.show', $profile)->with('status', __('admin.invited'));
    }

    public function resendInvite(Request $request, int $employee): RedirectResponse
    {
        $this->authorize($request);
        $profile = $this->employee($request, $employee);
        abort_if($profile->user_id === null, 404);
        $status = Password::sendResetLink(['email' => $profile->user->email]);

        return redirect()->route('employees.show', $profile)->with('status', $status === Password::RESET_LINK_SENT ? __('admin.invited') : __('admin.invite_retry'));
    }
}
