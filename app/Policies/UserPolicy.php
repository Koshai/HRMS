<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Organization\Company;

class UserPolicy
{
    public function view(User $actor, User $subject): bool
    {
        return $actor->company_id === $subject->company_id && $actor->is($subject);
    }

    public function manageStaff(User $actor, Company $company): bool
    {
        return $actor->company_id === $company->getKey()
            && in_array($actor->role, [Role::Admin, Role::HR], true);
    }
}
