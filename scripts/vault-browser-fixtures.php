<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Services\Identities;
use Awove\Vault\Enrollment;
use Awove\Vault\Models\Member;
use Awove\Vault\Riddle;
use Awove\Vault\VaultModule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
$authMode = $argv[3] ?? 'password_email_totp';
if (! in_array($authMode, ['password_email_totp', 'email_riddle'], true)) {
    throw new RuntimeException('Only recognized fictional Vault authentication profiles are permitted.');
}
$riddle = $authMode === 'email_riddle';
if (! in_array($mode, ['setup', 'cleanup', 'code', 'expire'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Only known isolated Vault fixture commands and projects are permitted.');
}
$manifest = realpath(__DIR__.'/../storage/app/private/vault-browser-manifest.json');
$keyFile = getenv('VAULT_REVIEW_KEY_FILE') ?: '/private/tmp/basecrm-vault-review/keyring.json';
if (! $manifest || ! is_file($keyFile)) {
    throw new RuntimeException('Prepare the private fictional Vault preview manifest and keyring first.');
}
foreach (['CRM_MANIFEST' => $manifest, 'VAULT_KEY_FILE' => $keyFile] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom($riddle ? '.env.previewAlpha' : '.env.alpha');
$app->make(Kernel::class)->bootstrap();
config(['vault.auth_mode' => $authMode]);
FictionalCrmFixtures::assertLocalExample('alpha');
$connection = DB::connection();
if (! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true)
    || (string) $connection->getConfig('port') !== '55438' || $connection->getConfig('database') !== 'base_alpha') {
    throw new RuntimeException('Vault fixtures require the resolved isolated Alpha database.');
}
$actual = $connection->selectOne('SELECT current_database() AS database_name, current_user AS role_name');
if ($actual->database_name !== 'base_alpha' || $actual->role_name !== 'base_alpha'
    || Installation::manifest()['id'] !== 'alpha' || Installation::manifest()['modules'] !== ['vault']) {
    throw new RuntimeException('Vault fixtures require the exact fictional Alpha profile and role.');
}
$marker = $riddle ? 'riddle-' : '';
$email = 'vault-'.$marker.$project.'@alpha.example.test';
$contactEmail = 'vault-'.$marker.'contact-'.$project.'@alpha.example.test';
$name = $project === 'desktop' ? 'Tamar Ben Ami' : 'Eitan Barak';
$privateFile = storage_path('app/private/vault-'.$marker.'browser-'.$project.'.json');
$riddleQuestion = "What is the fictional studio's private review phrase?";
$user = User::where('email', $email)->first();
if ($user && ($user->name !== $name || $user->getRoleNames()->diff([$riddle ? 'Administrator' : 'Employee'])->isNotEmpty()
    || $user->getDirectPermissions()->contains(fn ($permission) => ! str_starts_with($permission->name, 'vault.') && ! ($riddle && $permission->name === 'identities.manage')))) {
    throw new RuntimeException('The reserved Vault account was changed; no fixture action is permitted.');
}
if ($mode === 'code') {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $secret = $input['secret'] ?? '';
    if (! is_string($secret) || ! preg_match('/^[A-Z2-7]{16,128}$/D', $secret) || ! $user) {
        throw new RuntimeException('Only an explicit fictional setup secret and fixture account are accepted.');
    }
    $current = intdiv(time(), 30);
    $counter = ! empty($input['after_enrollment']) ? max($current, (Member::where('installation_id', 'alpha')->where('user_id', $user->id)->value('last_totp_counter') ?? -1) + 1) : $current;
    if ($counter > $current + 1) {
        echo json_encode(['code' => null, 'wait_seconds' => min(31, max(1, ($counter - 1) * 30 - time() + 1))]).PHP_EOL;
    } else {
        echo json_encode(['code' => (new Google2FA)->oathTotp($secret, $counter)]).PHP_EOL;
    }
    exit;
}
if ($mode === 'expire') {
    if (! $user) {
        throw new RuntimeException('Missing reserved Vault fixture account.');
    }
    $issued = now()->toImmutable()->subSeconds(301);
    $count = DB::table('vault_unlocks')->where('installation_id', 'alpha')->where('user_id', $user->id)
        ->update(['issued_at' => $issued, 'expires_at' => $issued->addSeconds(300)]);
    echo json_encode(['expired_owned_grants' => $count]).PHP_EOL;
    exit;
}

/** Refuse symlink traversal and paths outside the encrypted fixture namespace. */
function vaultFixturePath(string $path): void
{
    $prefix = 'vault/'.hash('sha256', 'alpha').'/';
    if (! preg_match('~^'.preg_quote($prefix, '~').'[0-9a-f-]{36}\.enc$~D', $path)
        || config('filesystems.disks.local.driver') !== 'local') {
        throw new RuntimeException('Unsafe Vault fixture file path.');
    }
    $disk = Storage::disk('local');
    $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
    $cursor = $root;
    if (is_link($cursor)) {
        throw new RuntimeException('Vault fixture storage cannot be a symlink.');
    }
    foreach (explode('/', $path) as $part) {
        $cursor .= DIRECTORY_SEPARATOR.$part;
        if (is_link($cursor)) {
            throw new RuntimeException('Vault fixture file components cannot be symlinks.');
        }
    }
}
$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $name, $privateFile, $user, $riddle, $riddleQuestion) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-vault-browser-fixtures']);
    if ($mode === 'setup') {
        if (Member::where('installation_id', 'alpha')->exists()) {
            throw new RuntimeException('Clean the known fictional Vault fixtures first. Existing Vault enrollments are preserved.');
        }
        if ($riddle && DB::table('vault_riddle_settings')->where('installation_id', 'alpha')->exists()) {
            throw new RuntimeException('An existing Vault riddle policy is protected; fixture setup refused.');
        }
        Artisan::call('crm:install');
        $fixture = $user ?? User::factory()->create(['name' => $name, 'email' => $email, 'email_verified_at' => now(), 'active' => true]);
        $fixture->assignRole($riddle ? 'Administrator' : 'Employee');
        $fixture->syncPermissions((new VaultModule)->permissions());
        if ($riddle) {
            $fixture->givePermissionTo('identities.manage');
        }
        // Reset only previous attempts by this exact reserved fictional account.
        // Never clear a shared IP limiter or another account's attempts.
        foreach (['enrollment', 'enrollment-factor', 'password', 'email-start', 'email-send', 'email-confirm', 'factor'] as $action) {
            RateLimiter::clear('vault:'.hash('sha256', 'alpha:'.$fixture->id.':'.$action));
        }
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));

        if (Contact::where('email', $contactEmail)->exists()) {
            throw new RuntimeException('A protected fixture identity already exists.');
        }
        $contact = app(Identities::class)->create($fixture, 'contacts', ['name' => 'Yael Shalev', 'email' => $contactEmail, 'phone' => '+972 50-000-0191', 'country' => 'Israel', 'city' => 'Haifa']);
        if ($riddle) {
            $answer = ' Fictional-Vault-'.bin2hex(random_bytes(18)).' ';
            $beforeAudit = DB::table('vault_audits')->max('id') ?? 0;
            app(Riddle::class)->configure('alpha', $riddleQuestion, $answer, $answer);
            app(Riddle::class)->approve('alpha', $fixture);
            $policy = DB::table('vault_riddle_settings')->where('installation_id', 'alpha')->first();
            $policyAudits = DB::table('vault_audits')->where('id', '>', $beforeAudit)->where('installation_id', 'alpha')->where('action', 'riddle.configuration_changed')->whereNull('actor_id')->whereNull('target_user_id')->get();
            if ($policyAudits->count() !== 1) {
                throw new RuntimeException('Unexpected fictional riddle configuration audit; fixture setup refused.');
            }
            $data = ['email' => $email, 'user_id' => $fixture->id, 'contact_id' => $contact->id, 'question' => $riddleQuestion, 'answer' => $answer,
                'policy_fingerprint' => hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)), 'policy_audit_ids' => $policyAudits->pluck('id')->all()];
        } else {
            $invitation = app(Enrollment::class)->bootstrap($fixture);
            $data = ['email' => $email, 'user_id' => $fixture->id, 'contact_id' => $contact->id, 'invitation' => $invitation['invitation'], 'password' => 'Fictional-Vault-'.bin2hex(random_bytes(18))];
        }
        file_put_contents($privateFile, json_encode($data, JSON_THROW_ON_ERROR));
        chmod($privateFile, 0600);

        return $data;
    }
    if (! $user) {
        return ['cleaned' => 0];
    }
    $policy = null;
    $policyAudits = collect();
    if ($riddle) {
        $policy = DB::table('vault_riddle_settings')->where('installation_id', 'alpha')->lockForUpdate()->first();
        if ($policy) {
            $saved = is_file($privateFile) ? json_decode(file_get_contents($privateFile), true, 32, JSON_THROW_ON_ERROR) : [];
            if ($policy->question !== $riddleQuestion || ($saved['email'] ?? '') !== $email
                || ! hash_equals($saved['policy_fingerprint'] ?? '', hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR)))
                || Member::where('installation_id', 'alpha')->where('user_id', '!=', $user->id)->exists()) {
                throw new RuntimeException('A protected riddle policy or member changed; fixture cleanup refused.');
            }
            $policyAudits = DB::table('vault_audits')->whereIn('id', $saved['policy_audit_ids'] ?? [])->get();
            if ($policyAudits->count() !== 1 || $policyAudits->contains(fn ($audit) => $audit->installation_id !== 'alpha' || $audit->action !== 'riddle.configuration_changed' || $audit->actor_id !== null || $audit->target_user_id !== null)) {
                throw new RuntimeException('A protected riddle configuration audit changed; cleanup refused.');
            }
        }
    }
    $contact = Contact::where('email', $contactEmail)->first();
    if ($contact && ($contact->created_by !== $user->id || $contact->name !== 'Yael Shalev')) {
        throw new RuntimeException('A protected customer references this fixture.');
    }
    $items = DB::table('vault_items')->where(fn ($query) => $query->where('created_by', $user->id)->orWhere('updated_by', $user->id)->when($contact, fn ($query) => $query->orWhere('contact_id', $contact->id)))->get();
    if ($items->contains(fn ($item) => $item->installation_id !== 'alpha' || $item->created_by !== $user->id || $item->updated_by !== $user->id
        || $item->contact_id !== $contact?->id || $item->company_id !== null || ! in_array($item->title, ['Fictional portal access', 'Fictional portal access revised', 'גישה פיקטיבית לפורטל'], true))) {
        throw new RuntimeException('Protected Vault item references fixture; cleanup refused.');
    }
    $files = DB::table('vault_files')->whereIn('item_id', $items->pluck('id'))->get();
    if ($files->contains(fn ($file) => $file->installation_id !== 'alpha' || $file->created_by !== $user->id)) {
        throw new RuntimeException('Protected Vault file references fixture.');
    }
    $members = DB::table('vault_members')->where('user_id', $user->id)->get();
    if ($members->contains(fn ($member) => $member->installation_id !== 'alpha' || ($member->approved_by !== $user->id && ! ($riddle && $member->approved_by === null)))) {
        throw new RuntimeException('Protected Vault enrollment references fixture.');
    }
    $audits = DB::table('vault_audits')->where(fn ($query) => $query->where('actor_id', $user->id)->orWhere('target_user_id', $user->id)->orWhereIn('item_id', $items->pluck('id')))->get();
    if ($audits->contains(fn ($audit) => $audit->installation_id !== 'alpha' || ($audit->actor_id !== $user->id && ! ($riddle && $audit->actor_id === null && $audit->action === 'riddle.administrator_approved' && $audit->target_user_id === $user->id)) || ($audit->target_user_id !== null && $audit->target_user_id !== $user->id))) {
        throw new RuntimeException('Protected Vault audit actor references fixture.');
    }
    if ($contact) {
        // New module relations must not silently make a demo customer eligible for deletion.
        $references = DB::select("SELECT conrelid::regclass::text AS table_name, a.attname AS column_name FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey) WHERE c.contype='f' AND c.confrelid='crm_contacts'::regclass");
        foreach ($references as $reference) {
            if (in_array($reference->table_name, ['vault_items', 'crm_contact_notes', 'crm_identity_activity'], true)) {
                continue;
            }
            if (DB::table($reference->table_name)->where($reference->column_name, $contact->id)->exists()) {
                throw new RuntimeException('A shared record references the Vault demo customer; cleanup refused.');
            }
        }
    }
    $notes = DB::table('crm_contact_notes')->where('contact_id', $contact?->id ?? 0)->get();
    $history = DB::table('crm_identity_activity')->where('contact_id', $contact?->id ?? 0)->get();
    if ($notes->contains(fn ($note) => $note->author_id !== $user->id) || $history->contains(fn ($entry) => $entry->actor_id !== $user->id)) {
        throw new RuntimeException('Protected customer history references the fixture.');
    }
    $collections = [
        'vault_recovery_codes' => DB::table('vault_recovery_codes')->whereIn('member_id', $members->pluck('id'))->get(),
        'vault_challenges' => DB::table('vault_challenges')->where('installation_id', 'alpha')->where('user_id', $user->id)->get(),
        'vault_unlocks' => DB::table('vault_unlocks')->where('installation_id', 'alpha')->where('user_id', $user->id)->get(),
        'vault_files' => $files, 'vault_items' => $items, 'vault_audits' => $audits->merge($policyAudits), 'vault_members' => $members,
        'crm_contact_notes' => $notes, 'crm_identity_activity' => $history,
    ];
    if ($contact) {
        $collections['crm_contacts'] = collect([(object) $contact->getAttributes()]);
    }
    $bytes = [];
    foreach ($files as $file) {
        vaultFixturePath($file->private_path);
        if (Storage::disk('local')->exists($file->private_path)) {
            $bytes[$file->private_path] = base64_encode(Storage::disk('local')->get($file->private_path));
        }
    }
    if ($members->isNotEmpty() || $items->isNotEmpty() || $contact) {
        $directory = storage_path('app/private/vault-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $copy = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($copy, json_encode(['fictional_only' => true, 'rows' => array_map(fn ($rows) => $rows->all(), $collections), 'encrypted_files' => $bytes, 'riddle_policy' => $policy], JSON_THROW_ON_ERROR));
        chmod($copy, 0600);
    }
    foreach ($collections as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }
    if ($policy) {
        DB::table('vault_riddle_settings')->where('installation_id', 'alpha')->delete();
    }
    DB::afterCommit(function () use ($files) {
        foreach ($files as $file) {
            if (DB::table('vault_files')->where('private_path', $file->private_path)->exists()) {
                throw new RuntimeException('A retained Vault file references fixture bytes.');
            }
            vaultFixturePath($file->private_path);
            Storage::disk('local')->delete($file->private_path);
        }
    });
    if (is_file($privateFile)) {
        unlink($privateFile);
    }

    return ['cleaned' => $items->count(), 'customer_cleaned' => $contact !== null];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
