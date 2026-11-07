<?php

namespace App\Validation;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class ProfileRules
{
    /** @return array<string, list<string|Unique>> */
    public static function forUser(?User $user = null): array
    {
        $emailUnique = Rule::unique(User::class, 'email');

        if ($user !== null) {
            $emailUnique->ignore($user);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', $emailUnique],
        ];
    }
}
