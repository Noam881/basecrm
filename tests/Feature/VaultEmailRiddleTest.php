<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Vault\AuthMode;
use Awove\Vault\Enrollment;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\RiddleSetting;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Notifications\EmailChallenge;
use Awove\Vault\Readiness;
use Awove\Vault\Riddle;
use Awove\Vault\Unlocks;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    Notification::fake();
    $this->withoutVite();
    $this->riddleManifest = tempnam(sys_get_temp_dir(), 'vault-riddle-test-');
    file_put_contents($this->riddleManifest, json_encode([...Installation::manifest(), 'modules' => ['vault']]));
    config(['core.manifest' => $this->riddleManifest, 'vault.auth_mode' => AuthMode::EMAIL_RIDDLE,
        'vault.active_key' => 'riddle-test-v1', 'vault.keys' => ['riddle-test-v1' => base64_encode(random_bytes(32))], 'vault.key_file' => null,
        'vault.development_question' => false, 'vault.fictional_only' => false,
        'mail.default' => 'array', 'mail.mailers.array.transport' => 'array',
        'hashing.argon.memory' => 1024, 'hashing.argon.time' => 1, 'hashing.argon.threads' => 1]);
    Date::setTestNow(Date::parse('2026-11-26T12:00:00.123456Z'));
    $this->artisan('crm:install')->assertSuccessful();
    $this->riddleAnswer = ' '.Str::random(32)." \n";
    app(Riddle::class)->configure(Enrollment::installation(), 'Which phrase did the operators agree?', $this->riddleAnswer, $this->riddleAnswer);
});

afterEach(function () {
    @unlink($this->riddleManifest);
    Date::setTestNow();
});

function vrUser(bool $administrator = true, bool $full = true): User
{
    $user = User::factory()->create(['name' => 'Yael Cohen', 'email' => 'vault-riddle-'.Str::uuid().'@fictional.example.test', 'active' => true]);
    $user->assignRole($administrator ? 'Administrator' : 'Employee');
    $user->givePermissionTo($full ? [...app(Modules::class)->installed()['vault']->permissions(), 'identities.manage'] : ['vault.admin']);

    return $user;
}

function vrRequest(User $user): Request
{
    $request = Request::create('/vault/api/auth/status');
    $store = new Store('vault-riddle-test', new ArraySessionHandler(120), Str::random(40));
    $store->start();
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function vrApproved(): array
{
    $user = vrUser();
    app(Riddle::class)->approve(Enrollment::installation(), $user);

    return [$user, vrRequest($user)];
}

function vrStart(Request $request): array
{
    $status = app(Unlocks::class)->emailStart($request);
    $notification = Notification::sent($request->user(), EmailChallenge::class)->last();
    parse_str(parse_url($notification->url, PHP_URL_FRAGMENT), $fragment);
    expect($fragment['challenge'])->toBe($status['challenge_id']);

    return [$fragment['challenge'], $fragment['token']];
}

function vrEmail(Request $request): string
{
    [$id, $token] = vrStart($request);
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]);

    return $id;
}

it('uses protected operator setup and explicit role/action approval without personal factors or automatic grants', function () {
    $settings = RiddleSetting::sole();
    expect(Hash::driver('argon2id')->check($this->riddleAnswer, $settings->answer_hash))->toBeTrue()
        ->and($settings->answer_hash)->not->toContain($this->riddleAnswer)
        ->and($settings->toArray())->not->toHaveKey('answer_hash');
    $user = vrUser();
    file_put_contents($this->riddleManifest, json_encode([...Installation::manifest(), 'modules' => []]));
    app(Riddle::class)->configure(Enrollment::installation(), 'A deployment-owned question', $this->riddleAnswer, $this->riddleAnswer);
    expect(Artisan::call('vault:approve-riddle-admin', ['email' => $user->email, '--confirm-installation' => Enrollment::installation()]))->toBe(0);
    $member = Enrollment::member($user);
    expect($member->riddle_approved_at)->not->toBeNull()->and($member->password_hash)->toBeNull()
        ->and($member->encrypted_totp)->toBeNull()->and($member->confirmed_at)->toBeNull()
        ->and(Artisan::output())->not->toContain($this->riddleAnswer, $user->email);
    foreach ([vrUser(false), vrUser(true, false)] as $unauthorized) {
        $before = $unauthorized->getAllPermissions()->pluck('name')->all();
        expect(fn () => app(Riddle::class)->approve(Enrollment::installation(), $unauthorized))->toThrow(HttpException::class)
            ->and($unauthorized->fresh()->getAllPermissions()->pluck('name')->all())->toBe($before)
            ->and(Enrollment::member($unauthorized))->toBeNull();
    }
    expect(fn () => app(Riddle::class)->configure('wrong-installation', 'Question', $this->riddleAnswer, $this->riddleAnswer))->toThrow(HttpException::class);
});

it('requires explicit shared-mode approval even when a CRM administrator already has every Vault grant', function () {
    $user = vrUser();
    $request = vrRequest($user);
    expect(app(Unlocks::class)->status($request)['approved'])->toBeFalse();
    expect(fn () => app(Unlocks::class)->emailStart($request))->toThrow(HttpException::class)
        ->and(Member::count())->toBe(0)->and(Challenge::count())->toBe(0);
    Notification::assertNothingSent();
});

it('denies every shared-mode API entry to an Employee even when they hold all Vault and customer permissions', function (string $method, string $url) {
    $user = vrUser(false);
    $this->actingAs($user)->json($method, $url)->assertForbidden();
})->with([
    ['GET', '/vault/api/auth/status'], ['POST', '/vault/api/auth/email-start'], ['GET', '/vault/api/items'],
    ['GET', '/vault/api/options'], ['POST', '/vault/api/items'],
    ['POST', '/vault/api/items/00000000-0000-4000-8000-000000000000/content'],
    ['PATCH', '/vault/api/items/00000000-0000-4000-8000-000000000000'],
    ['DELETE', '/vault/api/items/00000000-0000-4000-8000-000000000000'],
    ['POST', '/vault/api/files/00000000-0000-4000-8000-000000000000/download'],
    ['POST', '/vault/api/items/00000000-0000-4000-8000-000000000000/files'],
    ['DELETE', '/vault/api/files/00000000-0000-4000-8000-000000000000'],
]);

it('requires fresh dedicated email proof before the exact shared passphrase and creates a fixed non-sliding grant', function () {
    [$user, $request] = vrApproved();
    $status = app(Unlocks::class)->status($request);
    expect($status['auth_mode'])->toBe('email_riddle')->and($status['step'])->toBe('email_start')
        ->and($status['question'])->toBe('Which phrase did the operators agree?')->and($status['dev_question_mode'])->toBeFalse()
        ->and($status['can_admin'])->toBeFalse();
    [$id, $token] = vrStart($request);
    expect(Challenge::sole()->password_verified_at)->toBeNull();
    $this->actingAs($user)->get('/vault/confirm')->assertOk();
    $this->actingAs($user)->head('/vault/confirm')->assertOk();
    expect(Challenge::sole()->email_verified_at)->toBeNull();
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]))->toThrow(ValidationException::class);
    expect(fn () => app(Unlocks::class)->email(vrRequest($user), ['challenge_id' => $id, 'token' => $token]))->toThrow(ValidationException::class);
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]);
    expect(app(Unlocks::class)->status($request)['step'])->toBe('riddle');
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => trim($this->riddleAnswer)]))->toThrow(HttpException::class);
    $opened = app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]);
    $grant = UnlockGrant::sole();
    expect($opened['unlocked'])->toBeTrue()->and($grant->auth_mode)->toBe('email_riddle')
        ->and($grant->expires_at->diffInMicroseconds($grant->issued_at, true))->toBe(300000000.0);
    $expiry = $opened['expires_at'];
    $this->travel(10)->seconds();
    expect(app(Unlocks::class)->status($request)['expires_at'])->toBe($expiry);
    Date::setTestNow($grant->expires_at->subMicrosecond());
    app(Unlocks::class)->requireUnlocked($request);
    Date::setTestNow($grant->expires_at);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
});

it('rejects reused email and completed challenge proofs and never substitutes recovery or TOTP for the shared answer', function () {
    [, $request] = vrApproved();
    [$id, $token] = vrStart($request);
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]);
    expect(fn () => app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]))->toThrow(ValidationException::class);
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'code' => '123456', 'recovery_code' => Str::random(24)]))->toThrow(ValidationException::class);
    app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]);
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]))->toThrow(ValidationException::class)
        ->and(UnlockGrant::count())->toBe(1);
});

it('revokes every session and pending email proof on shared policy rotation and rejects mismatched authentication modes', function () {
    [$user, $request] = vrApproved();
    $id = vrEmail($request);
    $second = vrRequest($user);
    $secondId = vrEmail($second);
    app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]);
    app(Riddle::class)->configure(Enrollment::installation(), 'A rotated question', $next = Str::random(40), $next);
    expect(UnlockGrant::count())->toBe(0)->and(Challenge::count())->toBe(0);
    expect(fn () => app(Unlocks::class)->factor($second, ['challenge_id' => $secondId, 'answer' => $next]))->toThrow(ValidationException::class);
    $id = vrEmail($request);
    app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $next]);
    config(['vault.auth_mode' => AuthMode::STRONG]);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
});

it('cannot use old personal enrollment password or reset endpoints to change the shared verifier', function (string $url) {
    [$user] = vrApproved();
    $before = RiddleSetting::sole()->getRawOriginal();
    $this->actingAs($user)->postJson($url, ['user_id' => $user->id, 'password' => Str::random(32)])->assertForbidden();
    expect(RiddleSetting::sole()->getRawOriginal())->toBe($before);
})->with(['/vault/api/auth/password', '/vault/api/auth/enroll', '/vault/api/auth/enroll/confirm', '/vault/api/auth/approve', '/vault/api/auth/reset']);

it('rejects a stale recipient when the verified email changes during challenge initiation', function () {
    [$user, $request] = vrApproved();
    $changed = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use ($user, &$changed) {
        if (! $changed && str_contains($event->sql, '"vault_members"') && str_contains($event->sql, 'for update')) {
            $changed = true;
            $fresh = $user->fresh();
            $fresh->email = 'changed-'.Str::uuid().'@fictional.example.test';
            $fresh->email_verified_at = now();
            $fresh->save();
        }
    });
    expect(fn () => app(Unlocks::class)->emailStart($request))->toThrow(HttpException::class);
    Notification::assertNothingSent();
    expect($changed)->toBeTrue()->and(Challenge::count())->toBe(0);
});

it('persists wrong-answer throttles with a database limiter and applies the shared limit across administrators', function () {
    RateLimiter::swap(new CacheRateLimiter(Cache::store('database')));
    $requests = [];
    for ($index = 0; $index < 3; $index++) {
        [, $request] = vrApproved();
        $requests[] = [$request, vrEmail($request)];
    }
    foreach (array_slice($requests, 0, 2) as [$request, $id]) {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => Str::random(40)]))->toThrow(HttpException::class);
        }
    }
    expect(RateLimiter::attempts('vault:riddle:'.hash('sha256', Enrollment::installation())))->toBe(10);
    [$request, $id] = $requests[2];
    try {
        app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]);
        $this->fail('A shared limited answer attempt was accepted.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(429);
    }
    expect(UnlockGrant::count())->toBe(0);
});

it('does not issue a grant when riddle hashing outlives the email challenge', function () {
    [, $request] = vrApproved();
    $id = vrEmail($request);
    $realHasher = Hash::driver('argon2id');
    $hasher = Mockery::mock(Hasher::class);
    $hasher->shouldReceive('check')->once()->andReturnUsing(function ($answer, $hash) use ($realHasher) {
        Date::setTestNow(now()->addSeconds(601));

        return $realHasher->check($answer, $hash);
    });
    Hash::partialMock()->shouldReceive('driver')->with('argon2id')->andReturn($hasher);
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]))->toThrow(ValidationException::class)
        ->and(UnlockGrant::count())->toBe(0)->and(Challenge::sole()->completed_at)->toBeNull();
});

it('requires individual action and parent access after shared authentication and rejects role revocation immediately', function () {
    [$user, $request] = vrApproved();
    $id = vrEmail($request);
    app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]);
    $user->revokePermissionTo('vault.read');
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
    $user->givePermissionTo('vault.read');
    $id = vrEmail($request);
    app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $this->riddleAnswer]);
    $user->removeRole('Administrator');
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
});

it('reports approved shared-mode administrators without requiring individual password or TOTP enrollment', function () {
    vrApproved();
    $report = app(Readiness::class)->report(Enrollment::installation());
    expect($report['minimum_full_admins_enrolled'])->toBeTrue()->and($report['authentication_policy_configured'])->toBeTrue()
        ->and($report['separate_key_backup_required'])->toBeFalse()->and($report['ordinary_backup_verified'])->toBeFalse()
        ->and($report['persistent_limiter_store_configured'])->toBeFalse();
});
