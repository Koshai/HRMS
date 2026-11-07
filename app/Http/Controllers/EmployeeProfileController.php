<?php

namespace App\Http\Controllers;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmployeeProfileController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', EmployeeProfile::class);

        $actor = $request->user();
        $today = now(config('hrms.display_timezone'))->toDateString();
        $profiles = EmployeeProfile::query()->where('company_id', $actor->company_id);

        if (! in_array($actor->role, [Role::Admin, Role::HR], true)) {
            $profiles->where(function ($query) use ($actor, $today) {
                $query->where('user_id', $actor->id);

                if ($actor->role === Role::Manager) {
                    $query->orWhereHas('assignments', fn ($assignment) => $assignment
                        ->where('starts_on', '<=', $today)
                        ->where(fn ($dates) => $dates->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
                        ->whereHas('manager', fn ($manager) => $manager->where('user_id', $actor->id)));
                }
            });
        }

        $profiles->with(['assignments' => fn ($query) => $query
            ->where('starts_on', '<=', $today)
            ->where(fn ($dates) => $dates->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->with(['department', 'position', 'location'])]);

        return view('employees.index', [
            'employees' => $profiles->orderBy('name')->paginate(25),
        ]);
    }

    public function show(Request $request, int $employee): View
    {
        $profile = EmployeeProfile::query()
            ->where('company_id', $request->user()->company_id)
            ->findOrFail($employee);
        Gate::authorize('view', $profile);

        $profile->load(['assignments' => fn ($query) => $query
            ->orderByDesc('starts_on')
            ->with(['department', 'position', 'location', 'manager'])]);

        return view('employees.show', ['employee' => $profile]);
    }
}
