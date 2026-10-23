<?php

namespace Tests\Feature\Auth;

use App\Auth\MagicLogin;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MagicLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $this->artisan('crm:install')->assertSuccessful();
    }

    public function test_request_screen_is_available_without_authentication(): void
    {
        $this->get(route('magic-login.request'))->assertOk();
        $this->assertGuest();
    }

    public function test_requests_have_the_same_response_for_active_unknown_and_inactive_addresses(): void
    {
        $user = $this->user();
        $inactive = $this->user(['name' => 'Daniel Cohen', 'email' => 'daniel.cohen@fictional.example.test', 'active' => false]);

        $this->post(route('magic-login.email'), ['email' => strtoupper($user->email)])
            ->assertRedirect(route('magic-login.request'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');
        $status = session('status');
        $this->assertNotEmpty($status);

        foreach (['unknown@fictional.example.test', $inactive->email] as $email) {
            $this->post(route('magic-login.email'), ['email' => $email])
                ->assertRedirect(route('magic-login.request'))
                ->assertSessionHasNoErrors()
                ->assertSessionHas('status', $status);
        }

        Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $notification): bool {
            $this->assertNotEmpty($notification->url);
            $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), parse_url($notification->url, PHP_URL_HOST));
            $this->assertInstanceOf(ShouldQueue::class, $notification);
            $this->assertInstanceOf(ShouldBeEncrypted::class, $notification);

            return true;
        });
        Notification::assertNothingSentTo($inactive);
        Notification::assertCount(1);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('magic_login_tokens', 1);
        $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $user->id]);
        $this->assertGuest();
    }

    public function test_request_email_limits_apply_to_normalized_addresses_across_ip_addresses(): void
    {
        $user = $this->user();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->post(route('magic-login.email'), ['email' => strtoupper($user->email)])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('magic-login.request'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
            ->post(route('magic-login.email'), ['email' => '  '.$user->email.'  '])
            ->assertTooManyRequests();
        Notification::assertSentToTimes($user, MagicLoginLink::class, 3);
        $this->assertDatabaseCount('magic_login_tokens', 1);
        $this->assertGuest();
    }

    public function test_request_ip_limits_cover_unknown_addresses_without_disabling_password_login(): void
    {
        $user = $this->user();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('magic-login.email'), ['email' => 'unknown'.$attempt.'@fictional.example.test'])
                ->assertRedirect(route('magic-login.request'));
        }
        $this->post(route('magic-login.email'), ['email' => 'another-unknown@fictional.example.test'])
            ->assertTooManyRequests();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('magic_login_tokens', 0);
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Fictional-Noa-Password!123'])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_consumption_is_rate_limited_without_destroying_a_valid_link(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);
        $unknown = URL::temporarySignedRoute('magic-login.confirm', now()->addMinutes(10), ['token' => Str::random(64)]);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->get($unknown)->assertOk();
        }

        $this->post($link['url'])->assertTooManyRequests();
        $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $user->id]);
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post($link['url'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_only_a_hash_is_stored_and_a_new_link_supersedes_the_previous_link(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $service = app(MagicLogin::class);
        $first = $service->issue($user);
        $record = DB::table('magic_login_tokens')->where('user_id', $user->id)->first();

        $this->assertNotNull($record);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $first['token']);
        $this->assertSame(hash('sha256', $first['token']), $record->token_hash);
        $this->assertNotSame($first['token'], $record->token_hash);
        $this->assertSame($service->fingerprint($user), $record->credential_hash);
        $this->assertSame(now()->addMinutes(10)->timestamp, strtotime($record->expires_at));
        $this->assertSame($user->id, $service->peek($first['token'])?->id);

        $second = $service->issue($user);

        $this->assertNotSame($first['token'], $second['token']);
        $this->assertDatabaseCount('magic_login_tokens', 1);
        $this->assertNull($service->peek($first['token']));
        $this->assertSame($user->id, $service->peek($second['token'])?->id);
        $this->post($first['url'])
            ->assertRedirect(route('magic-login.request'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post($second['url'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_get_and_head_requests_do_not_consume_the_link_or_sign_the_user_in(): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);

        for ($visit = 0; $visit < 2; $visit++) {
            $response = $this->get($link['url'])
                ->assertOk()
                ->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertInertia(fn (Assert $page) => $page->component('auth/ConfirmMagicLogin')->where('valid', true));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertGuest();
            $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $user->id]);
        }

        $this->head($link['url'])->assertOk();
        $this->assertGuest();
        $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $user->id]);
        $this->post($link['url'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unknown_or_tampered_link_reveals_no_account_and_cannot_authenticate(): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);
        $unknown = URL::temporarySignedRoute('magic-login.confirm', now()->addMinutes(10), ['token' => Str::random(64)]);
        $tampered = $link['url'].'&unexpected=value';
        $unsigned = route('magic-login.confirm', ['token' => $link['token']]);

        foreach ([$unknown, $tampered, $unsigned] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('auth/ConfirmMagicLogin')
                    ->where('valid', false)
                    ->where('action', '')
                    ->where('email', null));
            $this->post($url)
                ->assertRedirect(route('magic-login.request'))
                ->assertSessionHasErrors('email');
            $this->assertGuest();
        }

        $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $user->id]);
    }

    public function test_confirmation_uses_the_route_token_rotates_the_session_and_verifies_the_bound_email(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        $other = $this->user(['name' => 'Daniel Cohen', 'email' => 'daniel.cohen@fictional.example.test']);
        $link = app(MagicLogin::class)->issue($user);
        $otherLink = app(MagicLogin::class)->issue($other);
        $password = $user->password;
        $this->get($link['url'])->assertOk();
        $sessionId = session()->getId();

        $this->post($link['url'], [
            'token' => $otherLink['token'],
            'email' => $other->email,
            'redirect' => 'https://outside.example.test/',
        ])->assertSessionHasNoErrors()
            ->assertRedirect('/dashboard')
            ->assertSessionHas('auth.password_confirmed_at')
            ->assertSessionMissing('login.id');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertTrue(Hash::check('Fictional-Noa-Password!123', $password));
        $this->assertDatabaseMissing('magic_login_tokens', ['user_id' => $user->id]);
        $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $other->id]);
    }

    public function test_a_consumed_link_cannot_be_replayed_in_a_later_session(): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);

        $this->post($link['url'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'))->assertRedirect();
        $this->assertGuest();
        $this->post($link['url'])
            ->assertRedirect(route('magic-login.request'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseMissing('magic_login_tokens', ['user_id' => $user->id]);
    }

    public function test_expired_links_remain_unconsumed_by_scanners_and_cannot_be_used(): void
    {
        $this->freezeTime();
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);
        $this->travel(11)->minutes();

        $this->get($link['url'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/ConfirmMagicLogin')->where('valid', false));
        $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $user->id]);
        $this->post($link['url'])
            ->assertRedirect(route('magic-login.request'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[DataProvider('credentialChanges')]
    public function test_account_changes_invalidate_an_outstanding_link(string $change): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);
        $this->changeCredentials($user, $change);

        $this->get($link['url'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/ConfirmMagicLogin')->where('valid', false));
        $this->post($link['url'])
            ->assertRedirect(route('magic-login.request'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public static function credentialChanges(): array
    {
        return [
            'email changed' => ['email'],
            'password changed' => ['password'],
            'MFA changed' => ['mfa'],
            'account deactivated' => ['inactive'],
        ];
    }

    public function test_another_authenticated_account_cannot_switch_accounts_or_consume_the_link(): void
    {
        $user = $this->user();
        $other = $this->user(['name' => 'Daniel Cohen', 'email' => 'daniel.cohen@fictional.example.test']);
        $link = app(MagicLogin::class)->issue($user);

        $this->actingAs($other)->post($link['url'])
            ->assertRedirect(route('magic-login.request'))
            ->assertSessionHasErrors('email');
        $this->assertAuthenticatedAs($other);
        $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $user->id]);
        $this->post(route('logout'));
        $this->post($link['url'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_same_authenticated_account_can_reauthenticate_with_a_fresh_link(): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);

        $this->actingAs($user)->post($link['url'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('magic_login_tokens', ['user_id' => $user->id]);
    }

    public function test_configured_mfa_is_required_before_a_magic_link_session_is_authenticated(): void
    {
        $user = $this->withMfa($this->user());
        $link = app(MagicLogin::class)->issue($user);

        $this->post($link['url'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id)
            ->assertSessionHas('login.remember', false)
            ->assertSessionHas('auth.magic_pending.user_id', $user->id)
            ->assertSessionMissing('auth.password_confirmed_at');
        $this->assertGuest();
        $this->assertDatabaseMissing('magic_login_tokens', ['user_id' => $user->id]);
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->post(route('two-factor.login'), ['code' => 'invalid-code'])->assertSessionHasErrors();
        $this->assertGuest();

        $this->post(route('two-factor.login'), ['code' => $this->otp($user)])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('auth.magic_pending')
            ->assertSessionHas('auth.password_confirmed_at')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_magic_mfa_accepts_and_rotates_a_recovery_code_without_invalidating_its_fingerprint(): void
    {
        $user = $this->withMfa($this->user());
        $link = app(MagicLogin::class)->issue($user);
        $fingerprint = app(MagicLogin::class)->fingerprint($user);
        $this->post($link['url'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post(route('two-factor.login'), ['recovery_code' => 'fictional-recovery-code'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('auth.magic_pending')
            ->assertSessionHas('auth.password_confirmed_at')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('fictional-recovery-code', $user->fresh()->recoveryCodes());
        $this->assertSame($fingerprint, app(MagicLogin::class)->fingerprint($user->fresh()));
    }

    public function test_mfa_response_rechecks_eligibility_if_the_account_changes_after_the_challenge_guard(): void
    {
        $user = $this->withMfa($this->user());
        $link = app(MagicLogin::class)->issue($user);
        $this->post($link['url'])->assertRedirect(route('two-factor.login'));
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, function () use ($user): void {
            DB::table('users')->where('id', $user->id)->update(['active' => false]);
        });

        $this->post(route('two-factor.login'), ['code' => $this->otp($user)])
            ->assertRedirect(route('magic-login.request'))
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('auth.magic_pending')
            ->assertSessionMissing('auth.password_confirmed_at');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_a_pending_magic_challenge_cannot_be_rebound_to_another_login_id(): void
    {
        $user = $this->withMfa($this->user());
        $other = $this->withMfa($this->user(['name' => 'Daniel Cohen', 'email' => 'daniel.cohen@fictional.example.test']));
        $link = app(MagicLogin::class)->issue($user);
        $this->post($link['url'])->assertRedirect(route('two-factor.login'));

        $this->withSession(['login.id' => $other->id])
            ->post(route('two-factor.login'), ['code' => $this->otp($other)])
            ->assertRedirect(route('magic-login.request'))
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('auth.magic_pending');
        $this->assertGuest();
    }

    public function test_an_invalid_password_attempt_clears_an_older_magic_mfa_challenge(): void
    {
        $user = $this->withMfa($this->user());
        $link = app(MagicLogin::class)->issue($user);
        $this->post($link['url'])->assertRedirect(route('two-factor.login'));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'incorrect-fictional-password'])
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('auth.magic_pending')
            ->assertSessionMissing('auth.password_confirmed_at');
        $this->assertGuest();
        $this->post(route('two-factor.login'), ['code' => $this->otp($user)])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_ordinary_password_mfa_does_not_gain_magic_link_recent_authentication(): void
    {
        $user = $this->withMfa($this->user());
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Fictional-Noa-Password!123'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionMissing('auth.magic_pending');
        $this->assertGuest();
        $this->post(route('two-factor.login'), ['code' => $this->otp($user)])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('pendingChanges')]
    public function test_pending_magic_mfa_rejects_expiry_or_changes_to_the_account(string $change): void
    {
        $this->freezeTime();
        $user = $this->withMfa($this->user());
        $link = app(MagicLogin::class)->issue($user);
        $this->post($link['url'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        if ($change === 'expired') {
            $this->travel(11)->minutes();
        } else {
            $this->changeCredentials($user, $change);
        }

        $this->post(route('two-factor.login'), ['code' => $this->otp($user->fresh())])
            ->assertRedirect()
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('auth.magic_pending');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public static function pendingChanges(): array
    {
        return [...self::credentialChanges(), 'pending challenge expired' => ['expired']];
    }

    public function test_a_new_administrator_can_enroll_in_mfa_without_a_known_password_but_cannot_use_admin_features_first(): void
    {
        $user = $this->user(['password' => Str::random(64)]);
        $user->assignRole('Administrator');
        $link = app(MagicLogin::class)->issue($user);

        $this->post($link['url'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertRedirect('/settings/security');
        $this->post('/users', ['name' => 'Daniel Cohen', 'email' => 'daniel.cohen@fictional.example.test', 'role' => 'Employee'])
            ->assertRedirect('/settings/security');
        $this->assertDatabaseCount('users', 1);
        $this->get(route('security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/Security')->where('twoFactorEnabled', false));
        $this->postJson(route('two-factor.enable'))->assertOk();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->get('/dashboard')->assertRedirect('/settings/security');
        $this->postJson(route('two-factor.confirm'), ['code' => $this->otp($user)])->assertOk();
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->get('/dashboard')->assertOk();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create([
            'name' => 'Noa Levi',
            'email' => 'noa.levi@fictional.example.test',
            'password' => 'Fictional-Noa-Password!123',
            ...$attributes,
        ]);
    }

    private function withMfa(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['fictional-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function changeCredentials(User $user, string $change): void
    {
        $user->forceFill(match ($change) {
            'email' => ['email' => 'noa.updated@fictional.example.test'],
            'password' => ['password' => 'Changed-Fictional-Password!456'],
            'mfa' => ['two_factor_secret' => encrypt('GEZDGNBVGY3TQOJQ'), 'two_factor_confirmed_at' => now()],
            'inactive' => ['active' => false],
        })->save();
    }

    private function otp(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->two_factor_secret));
    }
}
