<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_verification_links_cannot_verify_an_email_or_start_an_authentication_detour(): void
    {
        $user = User::factory()->unverified()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);
        Event::fake([Verified::class]);

        $this->actingAs($user)->get('/email/verify')->assertRedirect('/login');
        $this->get('/email/verify/'.$user->id.'/'.sha1($user->email))->assertRedirect('/login');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Event::assertNotDispatched(Verified::class);
    }

    public function test_authenticated_profile_and_security_pages_do_not_require_a_separate_verification_step(): void
    {
        $this->withoutVite();
        $user = User::factory()->unverified()->create(['name' => 'Noa Levi', 'email' => 'noa.levi@fictional.example.test']);
        $this->actingAs($user)->get('/settings/profile')->assertOk();
        $this->get('/settings/security')->assertOk();
    }
}
