<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
    }

    public function test_security_settings_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'));

        $response->assertOk();

        $response->assertSee('Two-factor authentication');
        $response->assertSee('Enable 2FA');
    }

    public function test_security_settings_page_requires_password_confirmation_when_enabled(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(route('password.confirm'));
    }

    public function test_security_settings_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Update password')
            ->assertDontSee('Manage your passkeys for passwordless sign-in')
            ->assertDontSee('Add a passkey to sign in without a password')
            ->assertDontSee('Two-factor authentication');
    }

    public function test_two_factor_authentication_disabled_when_confirmation_abandoned_between_requests(): void
    {
        $user = User::factory()->withTwoFactor()->create([
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($user);

        $component = Livewire::test('pages::settings.security');

        $component->assertSet('twoFactorEnabled', false);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);
    }

    public function test_two_factor_setup_does_not_reveal_the_secret_when_already_enabled(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $encryptedSecret = $user->two_factor_secret;

        $this->actingAs($user);

        Livewire::test('pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true])
            ->call('startTwoFactorSetup')
            ->assertDispatched('two-factor-enabled')
            ->assertSet('manualSetupKey', '')
            ->assertSet('qrCodeSvg', '');

        $this->assertSame($encryptedSecret, $user->refresh()->two_factor_secret);
    }

    public function test_recovery_codes_are_only_loaded_when_requested(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.two-factor.recovery-codes')
            ->assertSet('recoveryCodes', [])
            ->call('viewRecoveryCodes')
            ->assertSet('recoveryCodes', $user->recoveryCodes());
    }

    public function test_password_can_be_updated(): void
    {
        $password = Str::password(24);
        $newPassword = Str::password(24);
        $user = User::factory()->create(['password' => $password]);

        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', $password)
            ->set('password', $newPassword)
            ->set('password_confirmation', $newPassword)
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check($newPassword, $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $newPassword = Str::password(24);
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test('pages::settings.security')
            ->set('current_password', Str::password(24))
            ->set('password', $newPassword)
            ->set('password_confirmation', $newPassword)
            ->call('updatePassword')
            ->assertHasErrors(['current_password']);
    }
}
