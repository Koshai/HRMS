<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Organization\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_view_only_their_own_account_record(): void
    {
        $employee = User::factory()->create();
        $coworker = User::factory()->create();

        $this->assertTrue(Gate::forUser($employee)->allows('view', $employee));
        $this->assertFalse(Gate::forUser($employee)->allows('view', $coworker));
        $this->assertFalse(Gate::forUser($coworker)->allows('view', $employee));
        $this->assertFalse(Gate::allows('view', $employee));
    }

    #[DataProvider('staffRoles')]
    public function test_staff_administration_requires_a_privileged_role_and_matching_company(Role $role, bool $allowed): void
    {
        $company = Company::findOrFail(Company::INSTALLATION_ID);
        $otherCompany = new Company;
        $otherCompany->forceFill(['public_id' => (string) Str::uuid()])->save();
        $actor = User::factory()->create(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($actor)->allows('manage-staff', $company));
        $this->assertFalse(Gate::forUser($actor)->allows('manage-staff', $otherCompany));
    }

    public static function staffRoles(): array
    {
        return [
            'admin' => [Role::Admin, true],
            'hr' => [Role::HR, true],
            'manager' => [Role::Manager, false],
            'employee' => [Role::Employee, false],
        ];
    }
}
