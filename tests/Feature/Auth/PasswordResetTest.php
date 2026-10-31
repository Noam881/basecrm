<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('legacyScreens')]
    public function test_legacy_reset_screens_redirect_to_email_sign_in(string $path): void
    {
        $this->get($path)->assertRedirect('/login');
        $this->assertGuest();
    }

    public static function legacyScreens(): array
    {
        return [
            'forgot password' => ['/forgot-password'],
            'reset without token' => ['/reset-password'],
            'reset with token' => ['/reset-password/'.str_repeat('a', 64)],
        ];
    }

    public function test_legacy_reset_mutations_are_unavailable_and_leave_the_account_unchanged(): void
    {
        Notification::fake();
        $user = User::factory()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);
        $password = $user->password;

        foreach (['/forgot-password', '/reset-password'] as $path) {
            $response = $this->post($path, [
                'email' => $user->email,
                'token' => str_repeat('a', 64),
                'password' => 'Changed-Fictional-Password!456',
                'password_confirmation' => 'Changed-Fictional-Password!456',
            ]);
            $this->assertContains($response->getStatusCode(), [404, 405]);
            $this->assertGuest();
        }
        Notification::assertNothingSent();
        $this->assertSame($password, $user->fresh()->password);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }
}
