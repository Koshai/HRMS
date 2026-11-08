<?php

namespace Tests\Feature;

use App\Employees\EmployeeProfile;
use App\Enums\Role;
use App\Models\User;
use App\Organization\Company;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffInvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_is_sent_to_linked_staff_and_other_company_cannot_resend(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => Role::Admin]);
        $profile = EmployeeProfile::create(['company_id' => $admin->company_id, 'employee_number' => 'FICT-INV-1', 'name' => 'Fictional Invitee']);
        $this->actingAs($admin)->post(route('admin.employees.provision', $profile), ['email' => 'invitee@example.invalid', 'role' => 'employee'])->assertRedirect();
        $invitee = $profile->fresh()->user;
        $this->assertFalse($invitee->hasVerifiedEmail());
        Notification::assertSentTo($invitee, ResetPassword::class);

        $foreign = Company::forceCreate(['public_id' => (string) Str::uuid()]);
        $foreignHr = User::factory()->create(['company_id' => $foreign->id, 'role' => Role::HR]);
        $this->actingAs($foreignHr)->post(route('admin.employees.resend-invite', $profile))->assertNotFound();
        $this->travel(61)->seconds();
        $this->actingAs($admin)->post(route('admin.employees.resend-invite', $profile))->assertRedirect();
        Notification::assertSentToTimes($invitee, ResetPassword::class, 2);
    }

    public function test_password_reset_token_expires_and_is_single_use(): void
    {
        $user = User::factory()->unverified()->create();
        $token = Password::broker()->createToken($user);
        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'New-Fictional-Password-2026!',
            'password_confirmation' => 'New-Fictional-Password-2026!'];
        $this->post(route('password.update'), $payload)->assertSessionHasNoErrors();
        $this->post(route('password.update'), $payload)->assertSessionHasErrors();

        $expired = Password::broker()->createToken($user);
        $this->travel(61)->minutes();
        $this->post(route('password.update'), array_merge($payload, ['token' => $expired]))->assertSessionHasErrors();
    }
}
