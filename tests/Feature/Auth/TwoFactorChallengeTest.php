<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_challenge_screen_redirects_to_email_sign_in(): void
    {
        $this->get('/two-factor-challenge')->assertRedirect('/login');
        $this->assertGuest();
    }

    #[DataProvider('legacyMfaEndpoints')]
    public function test_legacy_mfa_endpoints_are_unavailable_and_cannot_change_enrolled_secrets(string $method, string $path): void
    {
        $user = User::factory()->withTwoFactor()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);
        $secrets = array_intersect_key($user->getRawOriginal(), array_flip(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
        $response = $this->actingAs($user)->call($method, $path, ['code' => '123456', 'recovery_code' => 'recovery-code-1']);

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertSame($secrets, array_intersect_key($user->fresh()->getRawOriginal(), $secrets));
        $this->assertAuthenticatedAs($user);
    }

    public static function legacyMfaEndpoints(): array
    {
        return [
            'challenge' => ['POST', '/two-factor-challenge'],
            'enable' => ['POST', '/user/two-factor-authentication'],
            'confirm' => ['POST', '/user/confirmed-two-factor-authentication'],
            'disable' => ['DELETE', '/user/two-factor-authentication'],
            'regenerate recovery codes' => ['POST', '/user/two-factor-recovery-codes'],
            'read recovery codes' => ['GET', '/user/two-factor-recovery-codes'],
            'read QR code' => ['GET', '/user/two-factor-qr-code'],
            'read secret' => ['GET', '/user/two-factor-secret-key'],
        ];
    }
}
