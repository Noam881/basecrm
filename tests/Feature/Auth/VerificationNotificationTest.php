<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class VerificationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_legacy_verification_notification_endpoint_is_unavailable(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);
        $response = $this->actingAs($user)->post('/email/verification-notification');

        $this->assertContains($response->getStatusCode(), [404, 405]);
        Notification::assertNothingSent();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
