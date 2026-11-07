<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Organization\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_closed(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['role' => 'admin'])->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_operator_can_provision_an_unverified_employee_account(): void
    {
        $this->artisan('hrms:create-user', [
            'name' => 'Example Employee',
            'email' => 'EMPLOYEE@EXAMPLE.COM',
        ])->assertSuccessful();

        $user = User::sole();
        $this->assertSame('employee@example.com', $user->email);
        $this->assertSame(Company::INSTALLATION_ID, $user->company_id);
        $this->assertSame(Role::Employee, $user->role);
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_invalid_role_does_not_create_an_account(): void
    {
        $this->artisan('hrms:create-user', [
            'name' => 'Example Employee',
            'email' => 'employee@example.com',
            '--role' => 'unknown',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
