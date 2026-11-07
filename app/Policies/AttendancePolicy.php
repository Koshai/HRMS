<?php

namespace App\Policies;

use App\Attendance\AttendanceRecord;
use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;

class AttendancePolicy
{
    public function view(User $actor, EmployeeProfile $employee): bool
    {
        if (! $this->eligibleActor($actor) || $actor->company_id !== $employee->company_id) {
            return false;
        }
        if (in_array($actor->role, [Role::Admin, Role::HR], true) || $employee->user_id === $actor->id) {
            return true;
        }

        return $actor->role === Role::Manager && $this->directReport($actor, $employee);
    }

    public function selfPunch(User $actor, EmployeeProfile $employee): bool
    {
        return $this->eligibleActor($actor) && $actor->company_id === $employee->company_id
            && $employee->user_id === $actor->id
            && ($employee->status ?? 'active') === 'active';
    }

    public function manualEntry(User $actor, EmployeeProfile $employee): bool
    {
        if (! $this->eligibleActor($actor) || $actor->company_id !== $employee->company_id || $employee->user_id === $actor->id) {
            return false;
        }

        return in_array($actor->role, [Role::Admin, Role::HR], true)
            || ($actor->role === Role::Manager && $this->directReport($actor, $employee));
    }

    public function proposeCorrection(User $actor, AttendanceRecord $original): bool
    {
        return $original->correction_of_id === null && $this->manualEntry($actor, $original->employee);
    }

    public function decide(User $actor, AttendanceRecord $correction): bool
    {
        return $this->eligibleActor($actor) && $correction->correction_of_id !== null
            && $actor->company_id === $correction->company_id
            && $actor->id !== $correction->actor_user_id
            && $actor->id !== $correction->employee->user_id
            && $actor->id !== $correction->original->actor_user_id
            && in_array($actor->role, [Role::Admin, Role::HR], true);
    }

    private function directReport(User $actor, EmployeeProfile $employee): bool
    {
        $today = now(config('hrms.display_timezone'))->toDateString();

        return $employee->assignments()
            ->whereDate('starts_on', '<=', $today)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
            ->whereHas('manager', fn ($query) => $query->where('user_id', $actor->id))
            ->exists();
    }

    private function eligibleActor(User $actor): bool
    {
        return $actor->hasVerifiedEmail() && ! $actor->employeeProfile()->where('status', 'inactive')->exists();
    }
}
