<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_screen_has_no_password_authentication_properties(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->missing('canResetPassword')
            ->missing('passwordRules'));
        $this->assertGuest();
    }

    public function test_submitting_legacy_credentials_only_sends_an_email_link_and_never_authenticates(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);

        foreach (['password', 'incorrect-fictional-password'] as $password) {
            $this->post('/login', ['email' => $user->email, 'password' => $password])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));
            $this->assertGuest();
        }

        Notification::assertSentToTimes($user, MagicLoginLink::class, 2);
        $this->assertDatabaseCount('magic_login_tokens', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unknown_login_addresses_do_not_create_accounts_or_send_notifications(): void
    {
        Notification::fake();
        $this->post('/login', ['email' => 'unknown@fictional.example.test', 'password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));
        Notification::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_protected_pages_require_email_link_authentication(): void
    {
        foreach (['/dashboard', '/settings/profile', '/settings/security'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->assertGuest();
    }

    public function test_users_can_logout_and_cannot_reuse_the_authenticated_session(): void
    {
        $user = User::factory()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);
        $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
