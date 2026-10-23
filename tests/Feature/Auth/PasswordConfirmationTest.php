<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_confirmation_screens_redirect_to_email_sign_in(): void
    {
        foreach (['/user/confirm-password', '/user/confirmed-password-status'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->assertGuest();
    }

    public function test_password_confirmation_is_unavailable_even_with_a_correct_legacy_password(): void
    {
        $user = User::factory()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);
        $response = $this->actingAs($user)->post('/user/confirm-password', ['password' => 'password']);

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $response->assertSessionMissing('auth.password_confirmed_at');
        $this->assertAuthenticatedAs($user);
    }
}
