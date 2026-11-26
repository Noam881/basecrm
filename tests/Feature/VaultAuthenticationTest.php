<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Vault\AuditLog;
use Awove\Vault\DevQuestion;
use Awove\Vault\Enrollment;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\RecoveryCode;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Notifications\EmailChallenge;
use Awove\Vault\Notifications\FactorsChanged;
use Awove\Vault\Unlocks;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    Notification::fake();
    $manifest = Installation::manifest();
    $manifest['modules'] = ['vault'];
    $path = tempnam(sys_get_temp_dir(), 'vault-auth-');
    file_put_contents($path, json_encode($manifest));
    config(['core.manifest' => $path, 'vault.active_key' => 'auth-test-v1', 'vault.keys' => ['auth-test-v1' => base64_encode(random_bytes(32))],
        'vault.key_file' => null, 'vault.development_question' => false, 'vault.fictional_only' => false,
        'hashing.argon.memory' => 1024, 'hashing.argon.time' => 1, 'hashing.argon.threads' => 1]);
    Date::setTestNow(Date::parse('2026-11-26T10:00:00.123456Z'));
    $this->artisan('crm:install')->assertSuccessful();
});

afterEach(function () {
    @unlink(config('core.manifest'));
    Date::setTestNow();
});

function vatUser(bool $admin = false): User
{
    $user = User::factory()->create(['name' => 'Tamar Levi', 'email' => 'vault-'.Str::uuid().'@fictional.example.test', 'active' => true]);
    $user->assignRole('Employee');
    $permissions = app(Modules::class)->installed()['vault']->permissions();
    $user->givePermissionTo($admin ? $permissions : ['vault.list', 'vault.read']);

    return $user;
}

function vatRequest(User $user): Request
{
    $request = Request::create('/vault/api/auth/status', 'GET');
    $store = new Store('vault-test', new ArraySessionHandler(120), Str::random(40));
    $store->start();
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function vatApproved(User $user): array
{
    $invitation = Str::random(64);
    Member::create(['installation_id' => Enrollment::installation(), 'user_id' => $user->id, 'approved_by' => $user->id,
        'approved_at' => now(), 'enrollment_identity_hash' => Enrollment::identity($user), 'enrollment_token_hash' => hash('sha256', $invitation), 'enrollment_expires_at' => now()->addHour()]);

    return [$invitation, vatRequest($user)];
}

function vatCode(string $secret): string
{
    return (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
}

function vatEnrolled(User $user): array
{
    [$invitation, $request] = vatApproved($user);
    $password = ' Fictional-'.Str::random(24)." \n";
    $setup = app(Enrollment::class)->begin($request, ['invitation' => $invitation, 'password' => $password, 'password_confirmation' => $password]);
    $confirmed = app(Enrollment::class)->confirm($request, ['code' => vatCode($setup['secret'])]);
    test()->travel(31)->seconds();

    return [$request, $password, $setup['secret'], $confirmed['recovery_codes']];
}

function vatChallenge(Request $request, string $password): array
{
    $status = app(Unlocks::class)->password($request, ['password' => $password]);
    $notification = Notification::sent($request->user(), EmailChallenge::class)->last();
    parse_str(parse_url($notification->url, PHP_URL_FRAGMENT), $fragment);
    expect($fragment['challenge'])->toBe($status['challenge_id']);

    return [$status['challenge_id'], $fragment['token']];
}

function vatEmail(Request $request, string $password): string
{
    [$id, $token] = vatChallenge($request, $password);
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]);

    return $id;
}

function vatOpen(Request $request, string $password, string $secret): array
{
    $id = vatEmail($request, $password);

    return app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'code' => vatCode($secret)]);
}

it('bootstraps only an explicitly selected first account and can reissue only its unfinished initial invitation', function () {
    $user = vatUser();
    $crmPassword = $user->password;
    $approval = app(Enrollment::class)->bootstrap($user);
    expect($approval['invitation'])->toHaveLength(64)->and($user->fresh()->can('vault.admin'))->toBeTrue()
        ->and(Member::sole()->enrollment_token_hash)->not->toBe($approval['invitation'])
        ->and($user->fresh()->password)->toBe($crmPassword);
    $this->travel(2)->days();
    $reissued = app(Enrollment::class)->bootstrap($user);
    expect($reissued['invitation'])->not->toBe($approval['invitation'])->and(Member::count())->toBe(1);
    $other = vatUser();
    expect(fn () => app(Enrollment::class)->bootstrap($other))->toThrow(ValidationException::class);
    AuditLog::record($user, 'vault.factors_enrolled');
    expect(fn () => app(Enrollment::class)->bootstrap($user))->toThrow(ValidationException::class);
});

it('requires an out-of-band invitation and valid authenticator before completing enrollment and preserves password whitespace', function () {
    $user = vatUser();
    $request = vatRequest($user);
    $password = " Fictional password with spaces \n";
    expect(fn () => app(Enrollment::class)->begin($request, ['invitation' => Str::random(64), 'password' => $password, 'password_confirmation' => $password]))->toThrow(ValidationException::class);
    [$invitation, $request] = vatApproved($user);
    expect(fn () => app(Enrollment::class)->confirm($request, ['code' => '000000']))->toThrow(ValidationException::class);
    $setup = app(Enrollment::class)->begin($request, compact('invitation', 'password') + ['password_confirmation' => $password]);
    $member = Member::sole();
    expect($setup['otpauth_uri'])->toStartWith('otpauth://totp/')
        ->and($member->pending_encrypted_totp)->not->toContain($setup['secret'])->and($member->password_hash)->toBeNull()
        ->and(Hash::driver('argon2id')->check($password, $member->pending_password_hash))->toBeTrue()
        ->and(Hash::driver('argon2id')->check(trim($password), $member->pending_password_hash))->toBeFalse();
    $confirmed = app(Enrollment::class)->confirm($request, ['code' => vatCode($setup['secret'])]);
    expect($confirmed['recovery_codes'])->toHaveCount(10)->and($confirmed['unlocked'])->toBeFalse()
        ->and(Member::sole()->enrollment_token_hash)->toBeNull()
        ->and(RecoveryCode::count())->toBe(10)->and(RecoveryCode::first()->code_hash)->not->toContain($confirmed['recovery_codes'][0]);
    expect(fn () => app(Enrollment::class)->confirm($request, ['code' => vatCode($setup['secret'])]))->toThrow(ValidationException::class);
});

it('requires password then a dedicated email confirmation then a fresh TOTP even for an administrator', function () {
    $user = vatUser(true);
    [$request, $password, $secret] = vatEnrolled($user);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
    [$id, $token] = vatChallenge($request, $password);
    expect(app(Unlocks::class)->status($request)['step'])->toBe('email');
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'code' => vatCode($secret)]))->toThrow(ValidationException::class);
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]);
    expect(app(Unlocks::class)->status($request)['step'])->toBe('totp')->and(UnlockGrant::count())->toBe(0);
    $status = app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'code' => vatCode($secret)]);
    expect($status['unlocked'])->toBeTrue()->and($status)->not->toHaveKeys(['token', 'session_hash', 'password_hash', 'encrypted_totp'])
        ->and(Challenge::sole()->token_hash)->toBeNull()->and(Challenge::sole()->completed_at)->not->toBeNull();
    app(Unlocks::class)->requireUnlocked($request);
});

it('does not consume an email challenge on GET or HEAD and uses a fragment rather than server-visible URL tokens', function () {
    $user = vatUser();
    [$request, $password] = vatEnrolled($user);
    [$id, $token] = vatChallenge($request, $password);
    $notification = Notification::sent($user, EmailChallenge::class)->last();
    expect(parse_url($notification->url, PHP_URL_QUERY))->toBeNull()
        ->and(parse_url($notification->url, PHP_URL_FRAGMENT))->toContain($token);
    $before = Challenge::sole()->getRawOriginal();
    $response = $this->actingAs($user)->get('/vault/confirm')->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private', 'max-age=0');
    $this->head('/vault/confirm')->assertOk();
    expect(Challenge::sole()->getRawOriginal())->toBe($before)->and(UnlockGrant::count())->toBe(0);
});

it('binds email confirmation to the originating user session and installation and consumes its token only once', function () {
    $user = vatUser();
    [$request, $password] = vatEnrolled($user);
    [$id, $token] = vatChallenge($request, $password);
    expect(Challenge::sole()->token_hash)->toBe(hash('sha256', $token));
    foreach ([vatRequest($user), vatRequest(vatUser())] as $foreign) {
        expect(fn () => app(Unlocks::class)->email($foreign, ['challenge_id' => $id, 'token' => $token]))->toThrow(ValidationException::class);
    }
    expect(fn () => app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => Str::random(64)]))->toThrow(ValidationException::class);
    $manifest = json_decode(file_get_contents(config('core.manifest')), true);
    file_put_contents(config('core.manifest'), json_encode([...$manifest, 'id' => 'another-fictional-installation']));
    expect(fn () => app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]))->toThrow(ValidationException::class);
    file_put_contents(config('core.manifest'), json_encode($manifest));
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]);
    expect(fn () => app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]))->toThrow(ValidationException::class);
    expect(Challenge::sole()->token_hash)->toBeNull();
});

it('invalidates the old email token on resend without extending the original challenge expiry', function () {
    $user = vatUser();
    [$request, $password] = vatEnrolled($user);
    [$id, $old] = vatChallenge($request, $password);
    $expiry = Challenge::sole()->expires_at;
    $this->travel(40)->seconds();
    app(Unlocks::class)->resend($request, ['challenge_id' => $id]);
    parse_str(parse_url(Notification::sent($user, EmailChallenge::class)->last()->url, PHP_URL_FRAGMENT), $fragment);
    expect($fragment['token'])->not->toBe($old)->and(Challenge::sole()->expires_at)->toEqual($expiry);
    expect(fn () => app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $old]))->toThrow(ValidationException::class);
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $fragment['token']]);
});

it('expires challenges at their exact boundary and refuses replay after factor completion', function () {
    $user = vatUser();
    [$request, $password, $secret] = vatEnrolled($user);
    [$id, $token] = vatChallenge($request, $password);
    $this->travelTo(Challenge::sole()->expires_at);
    expect(fn () => app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]))->toThrow(ValidationException::class);
    [$id, $token] = vatChallenge($request, $password);
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $token]);
    app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'code' => vatCode($secret)]);
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'code' => vatCode($secret)]))->toThrow(ValidationException::class);
});

it('keeps the unlock valid for exactly 300 seconds without activity extending it', function () {
    $user = vatUser();
    [$request, $password, $secret] = vatEnrolled($user);
    $status = vatOpen($request, $password, $secret);
    $issued = Date::parse($status['issued_at']);
    $expiry = Date::parse($status['expires_at']);
    expect($issued->diffInSeconds($expiry))->toBe(300.0);
    $this->travelTo($issued->copy()->addSeconds(299)->addMicroseconds(999999));
    app(Unlocks::class)->requireUnlocked($request);
    expect(app(Unlocks::class)->status($request)['expires_at'])->toBe($status['expires_at']);
    $this->travelTo($expiry);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
    expect(app(Unlocks::class)->status($request)['unlocked'])->toBeFalse()->and(UnlockGrant::count())->toBe(0);
});

it('never transfers an unlock to a second session and enforces permission revocation before the deadline', function () {
    $user = vatUser();
    [$request, $password, $secret] = vatEnrolled($user);
    vatOpen($request, $password, $secret);
    expect(fn () => app(Unlocks::class)->requireUnlocked(vatRequest($user)))->toThrow(HttpException::class);
    $user->revokePermissionTo('vault.read');
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
    $user->givePermissionTo('vault.read');
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
});

it('prevents reuse of a TOTP across two different valid challenges', function () {
    $user = vatUser();
    [$request, $password, $secret] = vatEnrolled($user);
    $code = vatCode($secret);
    $id = vatEmail($request, $password);
    app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'code' => $code]);
    app(Unlocks::class)->lock($request);
    $next = vatEmail($request, $password);
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $next, 'code' => $code]))->toThrow(ValidationException::class);
    $this->travel(31)->seconds();
    expect(app(Unlocks::class)->factor($request, ['challenge_id' => $next, 'code' => vatCode($secret)])['unlocked'])->toBeTrue();
});

it('uses each recovery code once and still requires the vault password and a fresh email confirmation', function () {
    $user = vatUser();
    [$request, $password, $secret, $codes] = vatEnrolled($user);
    [$id] = vatChallenge($request, $password);
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'recovery_code' => $codes[0]]))->toThrow(ValidationException::class);
    parse_str(parse_url(Notification::sent($user, EmailChallenge::class)->last()->url, PHP_URL_FRAGMENT), $fragment);
    app(Unlocks::class)->email($request, ['challenge_id' => $id, 'token' => $fragment['token']]);
    expect(app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'recovery_code' => $codes[0]])['unlocked'])->toBeTrue();
    app(Unlocks::class)->lock($request);
    $id = vatEmail($request, $password);
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'recovery_code' => $codes[0]]))->toThrow(ValidationException::class);
    expect(RecoveryCode::whereNotNull('used_at')->count())->toBe(1);
});

it('requires an unlocked administrator to approve or replace factors and invalidates every session on replacement', function () {
    $admin = vatUser(true);
    $target = vatUser();
    [$request, $password, $secret] = vatEnrolled($target);
    vatOpen($request, $password, $secret);
    $this->actingAs($admin)->postJson('/vault/api/auth/approve', ['user_id' => $target->id])->assertStatus(423);
    $generation = Enrollment::member($target)->factor_generation;
    $replacement = app(Enrollment::class)->approve($admin, $target, true);
    expect($replacement['invitation'])->toHaveLength(64)
        ->and(Enrollment::member($target)->factor_generation)->toBe($generation + 1)
        ->and(Enrollment::member($target)->password_hash)->toBeNull()
        ->and(UnlockGrant::where('user_id', $target->id)->count())->toBe(0)
        ->and(Challenge::where('user_id', $target->id)->count())->toBe(0);
    Notification::assertSentTo($target, FactorsChanged::class);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
    expect(fn () => app(Enrollment::class)->approve($target, $target, true))->toThrow(HttpException::class);
});

it('rate limits password guesses and does not put sensitive inputs in old input or response bodies', function () {
    $user = vatUser();
    [$request, $password] = vatEnrolled($user);
    for ($index = 0; $index < 3; $index++) {
        expect(fn () => app(Unlocks::class)->password($request, ['password' => 'Fictional-wrong-password']))->toThrow(ValidationException::class);
    }
    // The separate email-send budget also bounds repeated requests across sessions.
    expect(fn () => app(Unlocks::class)->password($request, ['password' => $password]))->toThrow(HttpException::class);
    $marker = 'FICTIONAL-SENSITIVE-INPUT-'.Str::random(20);
    $response = $this->actingAs($user)->post('/vault/api/auth/password', ['password' => $marker]);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
    $response->assertDontSee($marker)->assertSessionMissing('_old_input');
});

it('blocks all authentication paths when the module is disabled or the account is inactive', function () {
    $user = vatUser();
    [$request, $password, $secret] = vatEnrolled($user);
    vatOpen($request, $password, $secret);
    $user->forceFill(['active' => false])->save();
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
    $user->forceFill(['active' => true])->save();
    $manifest = json_decode(file_get_contents(config('core.manifest')), true);
    file_put_contents(config('core.manifest'), json_encode([...$manifest, 'modules' => []]));
    expect(fn () => app(Unlocks::class)->status($request))->toThrow(HttpException::class);
    $this->actingAs($user)->getJson('/vault/api/auth/status')->assertNotFound();
});

it('refuses the security-question mode in production even if both flags are set', function () {
    config(['vault.development_question' => true, 'vault.fictional_only' => true]);
    $original = app()->environment();
    try {
        app()->detectEnvironment(fn () => 'production');
        expect(fn () => DevQuestion::enabled())->toThrow(HttpException::class);
        app()->detectEnvironment(fn () => 'staging');
        expect(fn () => DevQuestion::enabled())->toThrow(HttpException::class);
    } finally {
        app()->detectEnvironment(fn () => $original);
    }
});

it('allows only an explicitly configured hashed development answer in the isolated fictional test database', function () {
    $user = vatUser();
    [$request, $password] = vatEnrolled($user);
    config(['vault.development_question' => true, 'vault.fictional_only' => true]);
    $answer = 'FICTIONAL-ANSWER-'.Str::random(24);
    DevQuestion::set($user, $answer);
    expect(Enrollment::member($user)->dev_question_hash)->not->toContain($answer);
    $id = vatEmail($request, $password);
    expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => 'a different fictional answer']))->toThrow(ValidationException::class);
    expect(app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'answer' => $answer])['unlocked'])->toBeTrue();
});

it('does not reuse an approved invitation or pending enrollment after the account email changes', function () {
    $user = vatUser();
    [$invitation, $request] = vatApproved($user);
    $password = 'FICTIONAL-PASSWORD-'.Str::random(20);
    $setup = app(Enrollment::class)->begin($request, ['invitation' => $invitation, 'password' => $password, 'password_confirmation' => $password]);
    $user->forceFill(['email' => 'replacement-'.Str::uuid().'@fictional.example.test', 'email_verified_at' => now()])->save();
    expect(fn () => app(Enrollment::class)->confirm($request, ['code' => vatCode($setup['secret'])]))->toThrow(ValidationException::class);
    expect(fn () => app(Enrollment::class)->begin($request, ['invitation' => $invitation, 'password' => $password, 'password_confirmation' => $password]))->toThrow(ValidationException::class);
    expect(Member::sole()->confirmed_at)->toBeNull()->and(UnlockGrant::count())->toBe(0);
});

it('uses the installation Hebrew locale for challenge and CLI-style factor-reset notices even when the console locale is English', function () {
    $manifest = json_decode(file_get_contents(config('core.manifest')), true);
    file_put_contents(config('core.manifest'), json_encode([...$manifest, 'locale' => 'he']));
    app()->setLocale('en');
    $admin = vatUser(true);
    $target = vatUser();
    [$request, $password] = vatEnrolled($target);
    vatChallenge($request, $password);
    Notification::assertSentTo($target, EmailChallenge::class, fn ($notification) => $notification->locale === 'he');
    app(Enrollment::class)->approve($admin, $target, true);
    Notification::assertSentTo($target, FactorsChanged::class, fn ($notification) => $notification->locale === 'he');
    expect(app()->getLocale())->toBe('en');
});

it('cancels existing grants and pending challenges on direct permission revoke and regrant without an intervening vault request', function () {
    $user = vatUser();
    [$request, $password, $secret] = vatEnrolled($user);
    vatOpen($request, $password, $secret);
    vatChallenge(vatRequest($user), $password);
    $generation = Enrollment::member($user)->factor_generation;
    $user->revokePermissionTo('vault.read');
    $user->givePermissionTo('vault.read');
    expect(UnlockGrant::count())->toBe(0)->and(Challenge::count())->toBe(0)
        ->and(Enrollment::member($user)->factor_generation)->toBeGreaterThan($generation);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
});

it('cancels grants for role permission changes even when the changed permission belongs to customer access rather than Vault', function () {
    $user = vatUser();
    $role = Role::create(['name' => 'Fictional customer access '.Str::uuid(), 'guard_name' => 'web']);
    $role->givePermissionTo('identities.view.all');
    $user->assignRole($role);
    [$request, $password, $secret] = vatEnrolled($user);
    vatOpen($request, $password, $secret);
    $role->revokePermissionTo('identities.view.all');
    $role->givePermissionTo('identities.view.all');
    expect(UnlockGrant::count())->toBe(0);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
});

it('cancels grants on role detach and reattach or an account disable and reenable without an intervening vault request', function (string $operation) {
    $user = vatUser();
    [$request, $password, $secret] = vatEnrolled($user);
    vatOpen($request, $password, $secret);
    if ($operation === 'role') {
        $user->removeRole('Employee');
        $user->assignRole('Employee');
    } else {
        $user->forceFill(['active' => false])->save();
        $user->forceFill(['active' => true])->save();
    }
    expect(UnlockGrant::count())->toBe(0);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
})->with(['role', 'account']);

it('invalidates all installation memberships for a globally changed account', function () {
    $user = vatUser();
    [$request, $password, $secret] = vatEnrolled($user);
    vatOpen($request, $password, $secret);
    $other = Member::create(['installation_id' => 'other-fictional-installation', 'user_id' => $user->id, 'approved_by' => $user->id,
        'approved_at' => now(), 'confirmed_at' => now(), 'factor_generation' => 1]);
    $grant = UnlockGrant::sole()->getAttributes();
    UnlockGrant::create([...$grant, 'id' => (string) Str::uuid(), 'installation_id' => $other->installation_id]);
    $user->revokePermissionTo('vault.read');
    $user->givePermissionTo('vault.read');
    expect(UnlockGrant::count())->toBe(0)->and($other->fresh()->factor_generation)->toBeGreaterThan(1);
});

it('cancels grants when an already-booted role or permission model deletes its assignment pivots', function (string $model) {
    $user = vatUser();
    $role = Role::create(['name' => 'Fictional deletion role '.Str::uuid(), 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'fictional.deletion.'.Str::uuid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);
    [$request, $password, $secret] = vatEnrolled($user);
    vatOpen($request, $password, $secret);
    ($model === 'role' ? $role : $permission)->delete();
    expect(UnlockGrant::count())->toBe(0);
    expect(fn () => app(Unlocks::class)->requireUnlocked($request))->toThrow(HttpException::class);
})->with(['role', 'permission']);

it('rolls back an administrative approval or reset when its original unlock expires inside the transaction', function (bool $reset) {
    $admin = vatUser(true);
    [$request, $password, $secret] = vatEnrolled($admin);
    $opened = vatOpen($request, $password, $secret);
    $target = vatUser();
    $before = null;
    if ($reset) {
        vatEnrolled($target);
        $before = Enrollment::member($target)->getRawOriginal();
    }
    $event = ($reset ? 'eloquent.updated: ' : 'eloquent.created: ').Member::class;
    Event::listen($event, function (Member $member) use ($target, $opened) {
        if ($member->user_id === $target->id) {
            Date::setTestNow(Date::parse($opened['expires_at']));
        }
    });
    try {
        expect(fn () => app(Enrollment::class)->approve($admin, $target, $reset, $request))->toThrow(HttpException::class);
    } finally {
        Event::forget($event);
    }
    expect(Enrollment::member($target)?->getRawOriginal())->toEqual($before);
    expect(DB::table('vault_audits')->where('target_user_id', $target->id)->whereIn('action', ['vault.enrollment_approved', 'vault.factors_reset'])->count())->toBe(0);
})->with([false, true]);

it('allows an intentional authenticated self-reset while invalidating its own grants and challenges', function () {
    $admin = vatUser(true);
    [$request, $password, $secret] = vatEnrolled($admin);
    vatOpen($request, $password, $secret);
    $generation = Enrollment::member($admin)->factor_generation;
    $result = app(Enrollment::class)->approve($admin, $admin, true, $request);
    expect($result['invitation'])->toHaveLength(64)->and(Enrollment::member($admin)->factor_generation)->toBe($generation + 1)
        ->and(Enrollment::member($admin)->confirmed_at)->toBeNull()->and(UnlockGrant::count())->toBe(0)->and(Challenge::count())->toBe(0);
    Notification::assertSentTo($admin, FactorsChanged::class);
});

it('does not issue a grant if a recovery-code hash check outlives its email challenge', function () {
    $user = vatUser();
    [$request, $password, , $codes] = vatEnrolled($user);
    $id = vatEmail($request, $password);
    $deadline = Challenge::sole()->expires_at;
    $real = Hash::driver('argon2id');
    $proxy = Mockery::mock($real)->makePartial();
    $proxy->shouldReceive('check')->once()->andReturnUsing(function ($value, $hash) use ($real, $deadline) {
        $valid = $real->check($value, $hash);
        Date::setTestNow($deadline);

        return $valid;
    });
    $manager = Hash::getFacadeRoot();
    $drivers = new ReflectionProperty($manager, 'drivers');
    $original = $drivers->getValue($manager);
    $drivers->setValue($manager, [...$original, 'argon2id' => $proxy]);
    try {
        expect(fn () => app(Unlocks::class)->factor($request, ['challenge_id' => $id, 'recovery_code' => $codes[0]]))->toThrow(ValidationException::class);
    } finally {
        $drivers->setValue($manager, $original);
    }
    expect(UnlockGrant::count())->toBe(0)->and(RecoveryCode::whereNotNull('used_at')->count())->toBe(0)
        ->and(Challenge::sole()->completed_at)->toBeNull();
});
