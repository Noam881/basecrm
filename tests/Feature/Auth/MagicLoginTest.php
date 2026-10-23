<?php

namespace Tests\Feature\Auth;

use App\Auth\MagicLogin;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
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
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');
        $status = session('status');
        $this->assertNotEmpty($status);

        foreach (['unknown@fictional.example.test', $inactive->email] as $email) {
            $this->post(route('magic-login.email'), ['email' => $email])
                ->assertRedirect(route('login'))
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
                ->assertRedirect(route('login'));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])
            ->post(route('magic-login.email'), ['email' => '  '.$user->email.'  '])
            ->assertTooManyRequests();
        Notification::assertSentToTimes($user, MagicLoginLink::class, 3);
        $this->assertDatabaseCount('magic_login_tokens', 1);
        $this->assertGuest();
    }

    public function test_request_ip_limits_cover_unknown_addresses_without_creating_accounts_or_sending_mail(): void
    {
        $user = $this->user();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('magic-login.email'), ['email' => 'unknown'.$attempt.'@fictional.example.test'])
                ->assertRedirect(route('login'));
        }
        $this->post(route('magic-login.email'), ['email' => 'another-unknown@fictional.example.test'])
            ->assertTooManyRequests();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('magic_login_tokens', 0);
        $this->assertGuest();

        $this->assertDatabaseCount('users', 1);
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
            ->assertRedirect(route('login'))
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
                ->assertRedirect(route('login'))
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
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertSessionMissing('login.id');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertTrue(Hash::check('Fictional-Noa-Password!123', $password));
        $this->assertDatabaseMissing('magic_login_tokens', ['user_id' => $user->id]);
        $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $other->id]);
    }

    public function test_inertia_confirmation_starts_a_fresh_dashboard_document_without_leaking_the_token(): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);
        $this->get($link['url'])->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $sessionId = session()->getId();

        $response = $this->withHeader('X-Inertia', 'true')->post($link['url'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('dashboard'));

        $location = $response->headers->get('X-Inertia-Location');
        $this->assertNotNull($location);
        $this->assertStringNotContainsString($link['token'], $location);
        $this->assertNull(parse_url($location, PHP_URL_QUERY));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, session()->getId());
        $this->assertDatabaseMissing('magic_login_tokens', ['user_id' => $user->id]);
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
            ->assertRedirect(route('login'))
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
            ->assertRedirect(route('login'))
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
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public static function credentialChanges(): array
    {
        return [
            'email changed' => ['email'],
            'account deactivated' => ['inactive'],
        ];
    }

    public function test_another_authenticated_account_cannot_switch_accounts_or_consume_the_link(): void
    {
        $user = $this->user();
        $other = $this->user(['name' => 'Daniel Cohen', 'email' => 'daniel.cohen@fictional.example.test']);
        $link = app(MagicLogin::class)->issue($user);

        $this->actingAs($other)->post($link['url'])
            ->assertRedirect(route('login'))
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
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('magic_login_tokens', ['user_id' => $user->id]);
    }

    public function test_legacy_password_and_mfa_fields_do_not_invalidate_or_block_email_sign_in(): void
    {
        $user = $this->user();
        $service = app(MagicLogin::class);
        $link = $service->issue($user);
        $fingerprint = $service->fingerprint($user);
        $user->forceFill(['password' => Str::random(64)])->save();
        $this->withMfa($user);

        $this->assertSame($fingerprint, $service->fingerprint($user->fresh()));
        $this->post($link['url'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('auth.magic_pending')
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    #[DataProvider('staleIntendedTargets')]
    public function test_sign_in_always_lands_on_dashboard_instead_of_stale_security_or_external_targets(string $target): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);

        $this->withSession([
            'url.intended' => $target,
            'login.id' => $user->id,
            'login.remember' => true,
            'auth.magic_pending' => ['user_id' => $user->id],
            'auth.password_confirmed_at' => now()->timestamp,
        ])->post($link['url'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('url.intended')
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('login.remember')
            ->assertSessionMissing('auth.magic_pending')
            ->assertSessionMissing('auth.password_confirmed_at')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public static function staleIntendedTargets(): array
    {
        return [
            'security settings' => ['/settings/security'],
            'password confirmation' => ['/user/confirm-password'],
            'old MFA challenge' => ['/two-factor-challenge'],
            'external target' => ['https://outside.example.test/'],
        ];
    }

    #[DataProvider('legacyMfaStates')]
    public function test_an_administrator_reaches_every_navigation_page_without_password_or_mfa_enrollment(bool $legacyMfa): void
    {
        $user = $this->user(['password' => Str::random(64)]);
        if ($legacyMfa) {
            $this->withMfa($user);
        }
        $user->assignRole('Administrator');
        $link = app(MagicLogin::class)->issue($user);

        $this->post($link['url'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('auth.magic_pending')
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        foreach (['/dashboard', '/leads', '/people', '/tasks', '/reminders', '/business-settings', '/settings/profile', '/settings/security'] as $path) {
            $this->get($path)->assertOk();
            $this->assertAuthenticatedAs($user);
        }
        $this->assertDatabaseCount('users', 1);
    }

    public static function legacyMfaStates(): array
    {
        return ['no MFA configured' => [false], 'legacy MFA configured' => [true]];
    }

    public function test_reopening_a_consumed_link_while_still_signed_in_returns_to_dashboard(): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);
        $this->post($link['url'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->get($link['url'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('magic_login_tokens', ['user_id' => $user->id]);
    }

    public function test_automatic_confirmation_still_requires_a_valid_csrf_token(): void
    {
        $user = $this->user();
        $link = app(MagicLogin::class)->issue($user);
        $this->get($link['url'])->assertOk();
        $environment = $this->app->environment();
        // Exercise the real CSRF middleware instead of its normal testing bypass.
        $this->app->instance('env', 'local');
        try {
            $this->post($link['url'])->assertStatus(419);
            $this->assertGuest();
            $this->assertDatabaseHas('magic_login_tokens', ['user_id' => $user->id]);
            $this->post($link['url'], ['_token' => csrf_token()])->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($user);
        } finally {
            $this->app->instance('env', $environment);
        }
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
            'inactive' => ['active' => false],
        })->save();
    }
}
