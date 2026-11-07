<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class PayrollPolicy
{
    public function manage(User $actor): bool
    {
        return $actor->hasVerifiedEmail() && in_array($actor->role, [Role::Admin, Role::HR], true)
            && ! $actor->employeeProfile()->where('status', 'inactive')->exists();
    }
}
