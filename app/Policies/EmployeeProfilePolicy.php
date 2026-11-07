<?php

namespace App\Policies;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;

class EmployeeProfilePolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, EmployeeProfile $employee): bool
    {
        if ($actor->company_id !== $employee->company_id) {
            return false;
        }

        if (in_array($actor->role, [Role::Admin, Role::HR], true) || $employee->user_id === $actor->id) {
            return true;
        }

        if ($actor->role !== Role::Manager) {
            return false;
        }

        $today = now(config('hrms.display_timezone'))->toDateString();

        return $employee->assignments()
            ->where('starts_on', '<=', $today)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->whereHas('manager', fn ($query) => $query->where('user_id', $actor->id))
            ->exists();
    }
}
