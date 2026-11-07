<?php

namespace App\Policies;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;

class SchedulePolicy
{
    public function manage(User $actor): bool
    {
        return $this->eligible($actor) && in_array($actor->role, [Role::Admin, Role::HR], true);
    }

    public function view(User $actor, EmployeeProfile $employee): bool
    {
        if (! $this->eligible($actor) || $actor->company_id !== $employee->company_id) {
            return false;
        }
        if ($this->manage($actor) || $employee->user_id === $actor->id) {
            return true;
        }
        if ($actor->role !== Role::Manager) {
            return false;
        }
        $today = now(config('hrms.display_timezone'))->toDateString();

        return $employee->assignments()->whereDate('starts_on', '<=', $today)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->whereHas('manager', fn ($query) => $query->where('user_id', $actor->id))->exists();
    }

    private function eligible(User $actor): bool
    {
        return $actor->hasVerifiedEmail() && ! $actor->employeeProfile()->where('status', 'inactive')->exists();
    }
}
