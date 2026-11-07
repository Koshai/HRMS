<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Organization\Company;
use App\Validation\ProfileRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateStaffUser extends Command
{
    protected $signature = 'hrms:create-user {name} {email} {--role=employee}';

    protected $description = 'Provision a staff account that can set its password through Fortify';

    public function handle(): int
    {
        $input = [
            'name' => trim((string) $this->argument('name')),
            'email' => Str::lower(trim((string) $this->argument('email'))),
            'role' => $this->option('role'),
        ];

        $validator = Validator::make($input, [
            ...ProfileRules::forUser(),
            'role' => ['required', Rule::enum(Role::class)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Str::random(64),
        ]);
        $user->company()->associate(Company::findOrFail(Company::INSTALLATION_ID));
        $user->role = Role::from($input['role']);
        $user->save();

        $this->info('Account created. The user can set a password through Forgot password and then verify their email.');

        return self::SUCCESS;
    }
}
