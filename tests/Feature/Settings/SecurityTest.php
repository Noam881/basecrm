<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('legacyMfaStates')]
    public function test_security_explains_email_sign_in_without_password_confirmation_or_mfa_controls(bool $legacyMfa): void
    {
        $this->withoutVite();
        $factory = User::factory();
        $user = ($legacyMfa ? $factory->withTwoFactor() : $factory)->unverified()->create([
            'name' => 'Noa Levi',
            'email' => 'noa.levi@fictional.example.test',
        ]);

        $this->actingAs($user)->get('/settings/security')
            ->assertOk()
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Security')
                ->where('email', $user->email)
                ->where('signInMethod', 'email_link')
                ->missing('passwordRules')
                ->missing('canManageTwoFactor')
                ->missing('twoFactorEnabled')
                ->missing('requiresConfirmation'));
    }

    public static function legacyMfaStates(): array
    {
        return ['without legacy MFA' => [false], 'with legacy MFA' => [true]];
    }

    public function test_legacy_password_updates_are_unavailable_and_preserve_credentials(): void
    {
        $user = User::factory()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);
        $password = $user->password;
        $response = $this->actingAs($user)->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'Changed-Fictional-Password!456',
            'password_confirmation' => 'Changed-Fictional-Password!456',
        ]);

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertSame($password, $user->fresh()->password);
    }
}
