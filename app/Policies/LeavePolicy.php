<?php

namespace App\Policies;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Leave\LeaveRequest;
use App\Models\User;

class LeavePolicy
{
    public function configure(User $actor): bool
    {
        return $this->eligible($actor) && in_array($actor->role, [Role::Admin, Role::HR], true);
    }

    public function view(User $actor, EmployeeProfile $employee): bool
    {
        if (! $this->eligible($actor) || $actor->company_id !== $employee->company_id) {
            return false;
        }

        return $employee->user_id === $actor->id || $this->configure($actor) || $this->report($actor, $employee);
    }

    public function request(User $actor, EmployeeProfile $employee): bool
    {
        return $this->eligible($actor) && $actor->company_id === $employee->company_id
            && $employee->user_id === $actor->id && ($employee->status ?? 'active') === 'active';
    }

    public function decide(User $actor, LeaveRequest $request): bool
    {
        if (! $this->eligible($actor) || $actor->company_id !== $request->company_id
            || $actor->id === $request->requester_user_id || $actor->id === $request->employee->user_id) {
            return false;
        }

        $approvalRole = $request->charges()->orderBy('leave_year')->first()?->policy?->approval_role;

        return $this->configure($actor) || ($approvalRole === 'manager' && $this->report($actor, $request->employee));
    }

    public function cancel(User $actor, LeaveRequest $request): bool
    {
        return $this->configure($actor) && $actor->company_id === $request->company_id
            && $actor->id !== $request->requester_user_id && $actor->id !== $request->employee->user_id;
    }

    private function report(User $actor, EmployeeProfile $employee): bool
    {
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
