<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => $this->withoutVite());

it('protects the CRM entry document against framing before an Inertia Vault visit', function () {
    $this->get('/login')->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
});

it('sets host-only HSTS on secure requests without upgrading unrelated subdomains', function () {
    $this->get('https://localhost/login')->assertOk()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('does not advertise HSTS over plain HTTP', function () {
    $this->get('http://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
});

it('preserves an existing stronger content security policy', function () {
    $response = (new SecurityHeaders)->handle(Request::create('https://localhost/'),
        fn () => response('ok')->header('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'"));

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; frame-ancestors 'none'");
    expect($response->headers->all('Content-Security-Policy'))->toContain("frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
});

it('keeps the anti-framing policy when another response allows framing', function () {
    $response = (new SecurityHeaders)->handle(Request::create('https://localhost/'),
        fn () => response('ok')->header('Content-Security-Policy', "frame-ancestors *; script-src 'self'"));

    expect($response->headers->all('Content-Security-Policy'))->toBe([
        "frame-ancestors *; script-src 'self'", "frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
    ]);
});

it('preserves Vault cache protection and covers exception responses', function () {
    $this->getJson('/vault/api/auth/status')->assertUnauthorized()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect($this->getJson('/vault/api/auth/status')->headers->get('Cache-Control'))->toContain('no-store');
    Route::get('/security-header-exception-fixture', fn () => abort(404));
    $this->get('/security-header-exception-fixture')->assertNotFound()->assertHeader('X-Frame-Options', 'DENY');
});
