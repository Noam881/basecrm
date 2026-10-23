<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->get(route('password.reset', ['token' => $notification->token]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('auth/ResetPassword')
                    ->where('email', null));

            $this->get(route('password.reset', ['token' => $notification->token, 'email' => $user->email]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('auth/ResetPassword')
                    ->where('email', $user->email));

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_reset_token_rejects_another_account_email_without_consuming_the_token(): void
    {
        Notification::fake();

        $account = User::factory()->create([
            'name' => 'Noa Levi',
            'email' => 'noa.levi@fictional.example.test',
            'password' => 'Original-Noa-Password!123',
        ]);
        $otherAccount = User::factory()->create([
            'name' => 'Daniel Cohen',
            'email' => 'daniel.cohen@fictional.example.test',
            'password' => 'Original-Daniel-Password!123',
        ]);
        $originalHash = $account->password;
        $otherOriginalHash = $otherAccount->password;
        $this->post(route('password.email'), ['email' => $account->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($account, ResetPassword::class, function ($notification) use ($account, $otherAccount, $originalHash, $otherOriginalHash) {
            $password = 'Updated-Noa-Password!456';
            $credentials = [
                'token' => $notification->token,
                'password' => $password,
                'password_confirmation' => $password,
            ];

            $this->post(route('password.update'), [...$credentials, 'email' => $otherAccount->email])
                ->assertSessionHasErrors('email');
            $this->assertSame($originalHash, $account->fresh()->password);
            $this->assertSame($otherOriginalHash, $otherAccount->fresh()->password);

            $this->post(route('password.update'), [...$credentials, 'email' => $account->email])
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));
            $this->assertTrue(Hash::check($password, $account->fresh()->password));
            $this->assertSame($otherOriginalHash, $otherAccount->fresh()->password);

            return true;
        });
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }
}
