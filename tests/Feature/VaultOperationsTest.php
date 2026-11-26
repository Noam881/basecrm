<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Modules;
use Awove\Vault\Cipher;
use Awove\Vault\Enrollment;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\Item;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\RecoveryCode;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Readiness;
use Awove\Vault\RestoreAuthentication;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->vaultOperationsDirectory = sys_get_temp_dir().'/vault-operations-'.bin2hex(random_bytes(12));
    mkdir($this->vaultOperationsDirectory, 0700);
    mkdir($this->vaultOperationsDirectory.'/private', 0700);
    $manifest = [...Installation::manifest(), 'modules' => ['vault']];
    file_put_contents($this->vaultOperationsDirectory.'/manifest.json', json_encode($manifest));
    $keyPath = $this->vaultOperationsDirectory.'/keyring.json';
    file_put_contents($keyPath, json_encode(['active_key' => 'fictional-v1', 'keys' => ['fictional-v1' => base64_encode(random_bytes(32))]]));
    chmod($keyPath, 0600);
    config(['core.manifest' => $this->vaultOperationsDirectory.'/manifest.json', 'vault.key_file' => $keyPath,
        'vault.development_question' => false, 'vault.fictional_only' => false,
        'app.url' => 'https://fictional.example.com', 'app.debug' => false,
        'session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions',
        'session.encrypt' => true, 'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
        'queue.default' => 'database', 'queue.connections.database.connection' => null,
        'mail.default' => 'array', 'mail.mailers.array.transport' => 'array',
        'cache.default' => 'database', 'cache.stores.database.connection' => null,
        'filesystems.disks.local.driver' => 'local', 'filesystems.disks.local.root' => $this->vaultOperationsDirectory.'/private',
        'hashing.argon.memory' => 1024, 'hashing.argon.time' => 1, 'hashing.argon.threads' => 1]);
    $this->artisan('crm:install')->assertSuccessful();
});

afterEach(function () {
    File::deleteDirectory($this->vaultOperationsDirectory);
});

function voUser(bool $full = true): User
{
    $user = User::factory()->create(['name' => 'Tamar Levi', 'email' => 'vault-operations-'.Str::uuid().'@fictional.example.test', 'active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo($full ? [...app(Modules::class)->installed()['vault']->permissions(), 'identities.manage'] : ['vault.admin']);

    return $user;
}

function voMember(User $user): Member
{
    $sealed = app(Cipher::class)->encrypt(Str::random(32), 'member:'.$user->id.':totp');

    return Member::create(['installation_id' => Enrollment::installation(), 'user_id' => $user->id, 'approved_by' => $user->id,
        'approved_at' => now(), 'confirmed_at' => now(), 'password_hash' => Hash::driver('argon2id')->make(Str::random(40)),
        'encrypted_totp' => $sealed['ciphertext'], 'totp_key_version' => $sealed['key_version'], 'last_totp_counter' => 123])->refresh();
}

function voMaintenance(bool $active): void
{
    app()->instance(MaintenanceMode::class, Mockery::mock(MaintenanceMode::class, function ($mock) use ($active) {
        $mock->shouldReceive('active')->andReturn($active);
    }));
}

function voRestoredState(User $user): array
{
    $member = voMember($user);
    $recovery = RecoveryCode::create(['member_id' => $member->id, 'code_hash' => Hash::driver('argon2id')->make(Str::random(24)), 'used_at' => now()]);
    $pending = voUser();
    $pendingMember = Member::create(['installation_id' => Enrollment::installation(), 'user_id' => $pending->id, 'approved_by' => $user->id,
        'approved_at' => now(), 'enrollment_token_hash' => hash('sha256', Str::random(64)), 'enrollment_identity_hash' => Enrollment::identity($pending),
        'enrollment_expires_at' => now()->addDay(), 'pending_password_hash' => Hash::driver('argon2id')->make(Str::random(40)),
        'pending_encrypted_totp' => 'FICTIONAL-PENDING-ENVELOPE', 'pending_totp_key_version' => 'fictional-v1']);
    $issued = now()->toImmutable();
    UnlockGrant::create(['id' => Str::uuid(), 'installation_id' => Enrollment::installation(), 'user_id' => $user->id,
        'session_hash' => hash('sha256', Str::random(40)), 'factor_generation' => $member->factor_generation,
        'credential_fingerprint' => hash('sha256', Str::random(40)), 'issued_at' => $issued, 'expires_at' => $issued->addSeconds(300)]);
    Challenge::create(['id' => Str::uuid(), 'installation_id' => Enrollment::installation(), 'user_id' => $user->id,
        'session_hash' => hash('sha256', Str::random(40)), 'factor_generation' => $member->factor_generation,
        'credential_fingerprint' => hash('sha256', Str::random(40)), 'token_hash' => hash('sha256', Str::random(64)),
        'password_verified_at' => now(), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
    DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => base64_encode('{}'), 'last_activity' => now()->timestamp]);
    DB::table('magic_login_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', Str::random(64)),
        'credential_hash' => hash('sha256', Str::random(40)), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);

    return [$member, $pendingMember, $recovery];
}

it('reports boolean-only evidence without granting access or claiming manual checks passed', function () {
    $user = voUser();
    voMember($user);
    $before = [Member::count(), UnlockGrant::count(), $user->getAllPermissions()->pluck('name')->sort()->all()];
    expect(Artisan::call('vault:preflight', ['--confirm-installation' => Enrollment::installation()]))->toBe(1);
    $output = Artisan::output();
    $report = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    foreach ($report as $value) {
        expect($value)->toBeBool();
    }
    expect($report['minimum_full_admins_enrolled'])->toBeTrue()
        ->and($report['production_runtime'])->toBeFalse()
        ->and($report['delivering_mail_transport_configured'])->toBeFalse()
        ->and($report['mail_delivery_verified'])->toBeFalse()
        ->and($report['separate_key_backup_verified'])->toBeFalse()
        ->and($report['restore_rehearsal_verified'])->toBeFalse()
        ->and($report['manual_verification_required'])->toBeTrue()
        ->and($output)->not->toContain($user->email, $this->vaultOperationsDirectory, 'fictional-v1')
        ->and([Member::count(), UnlockGrant::count(), $user->fresh()->getAllPermissions()->pluck('name')->sort()->all()])->toBe($before);
});

it('supports an explicit administrator minimum and never treats the admin label as full content authority', function () {
    $user = voUser();
    voMember($user);
    expect(app(Readiness::class)->report(Enrollment::installation())['minimum_full_admins_enrolled'])->toBeTrue()
        ->and(app(Readiness::class)->report(Enrollment::installation(), 2)['minimum_full_admins_enrolled'])->toBeFalse();
    $limited = voUser(false);
    voMember($limited);
    expect(app(Readiness::class)->report(Enrollment::installation(), 2)['minimum_full_admins_enrolled'])->toBeFalse();
    $limited->givePermissionTo([...app(Modules::class)->installed()['vault']->permissions(), 'identities.manage']);
    expect(app(Readiness::class)->report(Enrollment::installation(), 2)['minimum_full_admins_enrolled'])->toBeTrue();
    $limited->revokePermissionTo('identities.manage');
    expect(app(Readiness::class)->report(Enrollment::installation(), 2)['minimum_full_admins_enrolled'])->toBeFalse();
});

it('reports an automatically configured profile while retaining pending manual evidence', function () {
    voMember(voUser());
    $original = app()->environment();
    app()['env'] = 'production';
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
    try {
        expect(Artisan::call('vault:preflight', ['--confirm-installation' => Enrollment::installation()]))->toBe(0);
        $report = json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR);
        expect($report['automated_checks_passed'])->toBeTrue()->and($report['manual_verification_required'])->toBeTrue()
            ->and($report['mail_delivery_verified'])->toBeFalse()->and($report['separate_key_backup_verified'])->toBeFalse();
    } finally {
        app()['env'] = $original;
    }
});

it('fails closed for missing installation confirmation invalid administrator minima and insecure private paths', function () {
    expect(Artisan::call('vault:preflight'))->toBe(1)
        ->and(json_decode(Artisan::output(), true)['installation_confirmed'])->toBeFalse();
    expect(Artisan::call('vault:preflight', ['--confirm-installation' => Enrollment::installation(), '--minimum-admins' => '0']))->toBe(1);
    chmod($this->vaultOperationsDirectory.'/keyring.json', 0644);
    clearstatcache();
    $report = app(Readiness::class)->report(Enrollment::installation());
    expect($report['external_protected_keyring'])->toBeFalse()->and($report['encryption_configured'])->toBeFalse();
    chmod($this->vaultOperationsDirectory.'/keyring.json', 0600);
    chmod($this->vaultOperationsDirectory, 0755);
    clearstatcache();
    expect(app(Readiness::class)->report(Enrollment::installation())['external_protected_keyring'])->toBeFalse();
    config(['filesystems.disks.local.root' => public_path()]);
    expect(app(Readiness::class)->report(Enrollment::installation())['private_local_storage'])->toBeFalse();
});

it('requires maintenance explicit restore confirmation and the installation database boundary before writing', function (string $refusal) {
    [$member] = voRestoredState(voUser());
    voMaintenance($refusal !== 'maintenance');
    $confirmation = $refusal === 'installation' ? 'different-installation' : Enrollment::installation();
    if ($refusal === 'sessions') {
        config(['session.table' => 'vault_items']);
    } elseif ($refusal === 'connection') {
        config(['session.connection' => 'other-database']);
    }
    expect(Artisan::call('vault:invalidate-restored-auth', ['--confirm-installation' => $confirmation, '--confirm-restore' => $refusal !== 'confirmation']))->toBe(1)
        ->and($member->fresh()->factor_generation)->toBe($member->factor_generation)
        ->and(UnlockGrant::count())->toBe(1)->and(Challenge::count())->toBe(1)
        ->and(DB::table('sessions')->count())->toBe(1)->and(DB::table('magic_login_tokens')->count())->toBe(1);
})->with(['maintenance', 'installation', 'confirmation', 'sessions', 'connection']);

it('invalidates restored temporary authentication while preserving active factors recovery history and encrypted customer data', function () {
    $user = voUser();
    [$member, $pending, $recovery] = voRestoredState($user);
    $contact = Contact::create(['name' => 'Tamar Levi', 'search_key' => 'tamar levi', 'created_by' => $user->id]);
    $sealed = app(Cipher::class)->encrypt('FICTIONAL-RESTORE-TEST-ONLY', 'item:'.($id = Str::uuid()->toString()));
    $item = Item::create(['id' => $id, 'installation_id' => Enrollment::installation(), 'contact_id' => $contact->id,
        'title' => 'Fictional restored service', 'type' => 'note', 'level' => 1, 'version' => 1,
        'encrypted_payload' => $sealed['ciphertext'], 'key_version' => $sealed['key_version'], 'created_by' => $user->id, 'updated_by' => $user->id]);
    $active = $member->only(['password_hash', 'encrypted_totp', 'totp_key_version', 'confirmed_at', 'last_totp_counter']);
    $recoveryBefore = $recovery->refresh()->getRawOriginal();
    $ciphertext = $item->refresh()->getRawOriginal();
    voMaintenance(true);
    // A restored installation may intentionally keep Vault disabled until reconciliation is complete.
    file_put_contents(config('core.manifest'), json_encode([...Installation::manifest(), 'modules' => []]));
    expect(Artisan::call('vault:invalidate-restored-auth', ['--confirm-installation' => Enrollment::installation(), '--confirm-restore' => true]))->toBe(0)
        ->and($member->fresh()->factor_generation)->toBe($member->factor_generation + 1)
        ->and($member->fresh()->only(array_keys($active)))->toEqual($active)
        ->and($recovery->fresh()->getRawOriginal())->toBe($recoveryBefore)
        ->and($item->fresh()->getRawOriginal())->toBe($ciphertext)
        ->and($pending->fresh()->enrollment_token_hash)->toBeNull()->and($pending->fresh()->pending_encrypted_totp)->toBeNull()
        ->and($pending->fresh()->pending_password_hash)->toBeNull()
        ->and(UnlockGrant::count())->toBe(0)->and(Challenge::count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(0)->and(DB::table('magic_login_tokens')->count())->toBe(0)
        ->and(DB::table('vault_audits')->where('action', 'restore.authentication_invalidated')->count())->toBe(1);
});

it('rolls back the invalidation if maintenance ends before completion', function () {
    [$member] = voRestoredState(voUser());
    app()->instance(MaintenanceMode::class, Mockery::mock(MaintenanceMode::class, function ($mock) {
        $mock->shouldReceive('active')->andReturn(true, true, false);
    }));
    expect(fn () => app(RestoreAuthentication::class)->invalidate(Enrollment::installation(), true))->toThrow(HttpException::class)
        ->and($member->fresh()->factor_generation)->toBe($member->factor_generation)
        ->and(UnlockGrant::count())->toBe(1)->and(Challenge::count())->toBe(1)
        ->and(DB::table('sessions')->count())->toBe(1)->and(DB::table('magic_login_tokens')->count())->toBe(1)
        ->and(DB::table('vault_audits')->where('action', 'restore.authentication_invalidated')->count())->toBe(0);
});

it('refuses a mixed or wrongly identified Vault database before clearing installation-wide CRM sessions', function () {
    [$member] = voRestoredState(voUser());
    DB::table('vault_audits')->insert(['installation_id' => 'different-fictional-installation', 'action' => 'vault.locked', 'result' => 'success', 'created_at' => now()]);
    voMaintenance(true);
    expect(app(Readiness::class)->report(Enrollment::installation())['vault_installation_scope_verified'])->toBeFalse()
        ->and(Artisan::call('vault:invalidate-restored-auth', ['--confirm-installation' => Enrollment::installation(), '--confirm-restore' => true]))->toBe(1)
        ->and($member->fresh()->factor_generation)->toBe($member->factor_generation)
        ->and(DB::table('sessions')->count())->toBe(1)->and(DB::table('magic_login_tokens')->count())->toBe(1);
});
