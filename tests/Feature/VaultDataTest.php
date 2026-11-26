<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Vault\Cipher;
use Awove\Vault\Enrollment;
use Awove\Vault\Models\Attachment;
use Awove\Vault\Models\Item;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Rotation;
use Awove\Vault\Unlocks;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Exceptions\StreamedResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->vaultDataManifest = tempnam(sys_get_temp_dir(), 'vault-data-profile-');
    $this->vaultDataSettings = [...Installation::manifest(), 'modules' => ['vault'], 'capabilities' => ['sales' => false]];
    file_put_contents($this->vaultDataManifest, json_encode($this->vaultDataSettings));
    config(['core.manifest' => $this->vaultDataManifest, 'vault.key_file' => null, 'vault.active_key' => 'test-v1',
        'vault.keys' => ['test-v1' => base64_encode(random_bytes(32))]]);
    $this->artisan('crm:install')->assertSuccessful();
    Storage::fake('local');
});

afterEach(function () {
    @unlink($this->vaultDataManifest);
});

function vdUser(bool $all = true): User
{
    $user = User::factory()->create(['name' => 'Tamar Cohen', 'email' => 'vault-'.Str::uuid().'@example.test', 'active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo($all ? ['vault.list', 'vault.read', 'vault.create', 'vault.update', 'vault.delete', 'vault.level1', 'vault.change-level', 'vault.transfer', 'vault.files.upload', 'vault.files.download', 'vault.files.delete'] : ['vault.list', 'vault.read']);

    return $user;
}

function vdCustomer(User $user, string $type = 'contacts')
{
    return ($type === 'contacts' ? Contact::class : Company::class)::create(['name' => $type === 'contacts' ? 'Yael Shalev' : 'Rimon Studio', 'email' => Str::uuid().'@example.test', 'created_by' => $user->id, 'search_key' => 'fictional']);
}

function vdGrant($test, User $user): void
{
    $test->actingAs($user)->withSession(['vault_test' => 'fictional']);
    $test->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
    $request = Request::create('/vault/api/auth/status');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);
    $totp = app(Cipher::class)->encrypt((new Google2FA)->generateSecretKey(), 'member:'.$user->id.':totp');
    $member = Member::where('installation_id', Enrollment::installation())->where('user_id', $user->id)->first();
    $member ??= Member::create(['installation_id' => Enrollment::installation(), 'user_id' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(),
        'password_hash' => Hash::make('Fictional-vault-password-'.Str::random(10)), 'encrypted_totp' => $totp['ciphertext'], 'totp_key_version' => $totp['key_version'], 'confirmed_at' => now(), 'factor_generation' => 1]);
    $issued = now()->toImmutable();
    UnlockGrant::create(['id' => (string) Str::uuid(), 'installation_id' => Enrollment::installation(), 'user_id' => $user->id,
        'session_hash' => Unlocks::sessionHash($request), 'factor_generation' => $member->factor_generation, 'credential_fingerprint' => Unlocks::fingerprint($user->fresh(), $member), 'issued_at' => $issued, 'expires_at' => $issued->addSeconds(300)]);
}

function vdData($customer, array $changes = []): array
{
    return [...['customer_type' => $customer instanceof Contact ? 'contacts' : 'companies', 'customer_id' => $customer->id,
        'title' => 'Fictional hosting access', 'type' => 'server', 'level' => 2,
        'payload' => ['fields' => [['id' => 'server.private_key', 'label' => 'Fictional private key', 'type' => 'secret_multiline',
            'value' => "  FICTIONAL-SECRET-ONLY\nשורה נוספת\n  ", 'order' => 0, 'hidden' => true]], 'notes' => "  הערה פיקטיבית\n"]], ...$changes];
}

function vdCreate($test, $customer, array $changes = []): Item
{
    $response = $test->postJson('/vault/api/items', vdData($customer, $changes))->assertCreated();

    return Item::findOrFail($response->json('item.id'));
}

it('stores sensitive values encrypted while both central and customer lists expose only the same authorized metadata', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    vdGrant($this, $user);
    $data = vdData($customer);
    $item = vdCreate($this, $customer);
    expect($item->encrypted_payload)->not->toContain('FICTIONAL-SECRET-ONLY', 'שורה נוספת', 'private key')
        ->and(json_encode($item))->not->toContain('encrypted_payload', 'key_version');
    $list = $this->getJson('/vault/api/items')->assertOk()->assertHeader('Cache-Control');
    expect($list->headers->get('Cache-Control'))->toContain('no-store', 'private', 'max-age=0');
    $scoped = $this->getJson('/vault/api/items?customer_type=contacts&customer_id='.$customer->id)->assertOk();
    expect($list->json('page.data.0.id'))->toBe($item->id)->and($scoped->json('page.data.0.id'))->toBe($item->id)
        ->and($list->getContent())->not->toContain('FICTIONAL-SECRET-ONLY', 'encrypted_payload', 'private key', 'key_version');
    $this->postJson('/vault/api/items/'.$item->id.'/content')->assertOk()->assertJsonPath('payload', $data['payload']);
    expect(DB::table('vault_audits')->where('item_id', $item->id)->pluck('action')->all())->toContain('item.created', 'item.read')
        ->and(json_encode(DB::table('vault_audits')->get()))->not->toContain('FICTIONAL-SECRET-ONLY');
    $this->postJson('/vault/api/auth/lock')->assertOk();
    $this->postJson('/vault/api/items/'.$item->id.'/content')->assertStatus(423);
    $this->getJson('/vault/api/items')->assertOk()->assertJsonPath('page.total', 1);
});

it('requires a customer title valid type and explicitly chosen level in validation and database constraints', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    vdGrant($this, $user);
    foreach (['customer_type', 'customer_id', 'title', 'type', 'level'] as $field) {
        $data = vdData($customer);
        unset($data[$field]);
        $this->postJson('/vault/api/items', $data)->assertUnprocessable();
    }
    foreach ([0, 3, null, 'other'] as $level) {
        $this->postJson('/vault/api/items', vdData($customer, ['level' => $level]))->assertUnprocessable();
    }
    $this->postJson('/vault/api/items', vdData($customer, ['title' => 'FICTIONAL 1234567890123456']))->assertUnprocessable();
    $forbidden = vdData($customer);
    $forbidden['payload']['fields'][0]['label'] = 'CVV';
    $this->postJson('/vault/api/items', $forbidden)->assertUnprocessable();
    expect(Item::count())->toBe(0);
    $constraints = DB::select("SELECT conname FROM pg_constraint WHERE conrelid='vault_items'::regclass AND contype='c'");
    expect(array_column($constraints, 'conname'))->toContain('vault_items_one_customer', 'vault_items_level', 'vault_items_type');
});

it('filters customer and level access before counts search and direct reads without granting mutations to readers', function () {
    $owner = vdUser();
    $customer = vdCustomer($owner);
    vdGrant($this, $owner);
    $ordinary = vdCreate($this, $customer);
    $restricted = vdCreate($this, $customer, ['title' => 'Restricted fictional account', 'level' => 1]);
    $reader = vdUser(false);
    $reader->givePermissionTo('identities.view.all');
    vdGrant($this, $reader);
    $this->getJson('/vault/api/items')->assertOk()->assertJsonPath('page.total', 1)->assertJsonPath('page.data.0.id', $ordinary->id);
    $this->getJson('/vault/api/items?q=Restricted')->assertOk()->assertJsonPath('page.total', 0);
    $this->postJson('/vault/api/items/'.$restricted->id.'/content')->assertForbidden();
    $this->postJson('/vault/api/items/'.$ordinary->id.'/content')->assertOk();
    $this->patchJson('/vault/api/items/'.$ordinary->id, [...vdData($customer), 'version' => 1])->assertForbidden();
    $this->deleteJson('/vault/api/items/'.$ordinary->id, ['version' => 1])->assertForbidden();
    $foreign = vdUser(false);
    vdGrant($this, $foreign);
    $this->getJson('/vault/api/items')->assertOk()->assertJsonPath('page.total', 0);
    $this->postJson('/vault/api/items/'.$ordinary->id.'/content')->assertForbidden();
    $this->getJson('/vault/api/items?customer_type=contacts&customer_id='.$customer->id)->assertForbidden();
});

it('requires explicit level and transfer permissions and rejects stale versions and silent field removal on a type change', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    $company = vdCustomer($user, 'companies');
    vdGrant($this, $user);
    $item = vdCreate($this, $customer);
    $user->revokePermissionTo(['vault.change-level', 'vault.transfer']);
    // Permission changes revoke the old grant. Simulate a newly authenticated window
    // so these assertions independently exercise the remaining action permissions.
    expect(UnlockGrant::count())->toBe(0);
    vdGrant($this, $user);
    $this->patchJson('/vault/api/items/'.$item->id, [...vdData($customer, ['level' => 1]), 'version' => 1])->assertForbidden();
    $this->patchJson('/vault/api/items/'.$item->id, [...vdData($company), 'version' => 1])->assertForbidden();
    $this->patchJson('/vault/api/items/'.$item->id, [...vdData($customer, ['type' => 'note', 'payload' => ['fields' => [], 'notes' => '']]), 'version' => 1])->assertUnprocessable();
    $this->patchJson('/vault/api/items/'.$item->id, [...vdData($customer, ['title' => 'Updated fictional access']), 'version' => 1])->assertOk()->assertJsonPath('item.version', 2);
    $this->patchJson('/vault/api/items/'.$item->id, [...vdData($customer), 'version' => 1])->assertConflict();
    expect($item->fresh()->title)->toBe('Updated fictional access');
    $user->givePermissionTo(['vault.change-level', 'vault.transfer']);
    expect(UnlockGrant::count())->toBe(0);
    vdGrant($this, $user);
    $this->patchJson('/vault/api/items/'.$item->id, [...vdData($company, ['level' => 1]), 'version' => 2])->assertOk();
    expect($item->fresh()->contact_id)->toBeNull()->and($item->fresh()->company_id)->toBe($company->id)
        ->and(DB::table('vault_audits')->where('item_id', $item->id)->pluck('action')->all())->toContain('item.transferred', 'item.level_changed');
});

it('denies inactive revoked foreign-installation and disabled-module access even to previously unlocked users', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    vdGrant($this, $user);
    $item = vdCreate($this, $customer);
    $user->revokePermissionTo('vault.read');
    $this->postJson('/vault/api/items/'.$item->id.'/content')->assertStatus(423);
    $user->givePermissionTo('vault.read');
    expect(UnlockGrant::count())->toBe(0);
    vdGrant($this, $user->fresh());
    $user->forceFill(['active' => false])->save();
    $this->getJson('/vault/api/items')->assertForbidden();
    expect(UnlockGrant::count())->toBe(0);
    $user->forceFill(['active' => true])->save();
    $this->getJson('/vault/api/items')->assertUnauthorized();
    vdGrant($this, $user->fresh());
    $this->vaultDataSettings['id'] = 'other-fictional-installation';
    file_put_contents($this->vaultDataManifest, json_encode($this->vaultDataSettings));
    $this->getJson('/vault/api/items')->assertOk()->assertJsonPath('page.total', 0);
    $this->postJson('/vault/api/items/'.$item->id.'/content')->assertStatus(423);
    $this->vaultDataSettings['modules'] = [];
    file_put_contents($this->vaultDataManifest, json_encode($this->vaultDataSettings));
    $this->getJson('/vault/api/items')->assertNotFound();
    expect(Item::count())->toBe(1);
});

it('stores documents and original filenames encrypted and requires fresh permission unlock and item versions for file actions', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    vdGrant($this, $user);
    $item = vdCreate($this, $customer);
    $bytes = "FICTIONAL-DOCUMENT-ONLY\nמידע פיקטיבי";
    $name = 'Fictional-sensitive-name.txt';
    $this->post('/vault/api/items/'.$item->id.'/files', ['file' => UploadedFile::fake()->createWithContent($name, $bytes), 'version' => 1], ['Accept' => 'application/json'])->assertOk();
    $file = Attachment::sole();
    expect($file->encrypted_name)->not->toContain($name)->and(Storage::disk('local')->get($file->private_path))->not->toContain($bytes, 'FICTIONAL-DOCUMENT-ONLY');
    $download = $this->postJson('/vault/api/files/'.$file->id.'/download')->assertOk()->assertHeader('Cache-Control');
    expect($download->streamedContent())->toBe($bytes);
    $this->postJson('/vault/api/files/'.$file->id.'/download')->assertOk()->assertDownload($name);
    $this->getJson('/vault/api/items')->assertOk()->assertDontSee($name);
    $this->deleteJson('/vault/api/files/'.$file->id, ['version' => 1])->assertConflict();
    $this->deleteJson('/vault/api/files/'.$file->id, ['version' => 2])->assertOk();
    $this->postJson('/vault/api/files/'.$file->id.'/download')->assertNotFound();
    $this->postJson('/vault/api/auth/lock')->assertOk();
    $this->postJson('/vault/api/files/'.$file->id.'/download')->assertStatus(423);
});

it('authenticates item installation and attachment context, then rotates and restores ciphertext with dedicated versioned keys', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    vdGrant($this, $user);
    $item = vdCreate($this, $customer);
    $plain = app(Cipher::class)->decrypt($item->encrypted_payload, 'test-v1', 'item:'.$item->id);
    expect(fn () => app(Cipher::class)->decrypt($item->encrypted_payload, 'test-v1', 'item:'.Str::uuid()))->toThrow(HttpException::class);
    $backup = $item->getRawOriginal();
    $oldKey = config('vault.keys.test-v1');
    config(['vault.active_key' => 'test-v2', 'vault.keys' => ['test-v1' => $oldKey, 'test-v2' => base64_encode(random_bytes(32))]]);
    expect(app(Rotation::class)->run(false)['items'])->toBe(1)->and($item->fresh()->key_version)->toBe('test-v1');
    expect(app(Rotation::class)->run(true)['items'])->toBe(1)->and($item->fresh()->key_version)->toBe('test-v2');
    expect(app(Cipher::class)->decrypt($item->fresh()->encrypted_payload, 'test-v2', 'item:'.$item->id))->toBe($plain)
        ->and(app(Cipher::class)->decrypt($backup['encrypted_payload'], 'test-v1', 'item:'.$item->id))->toBe($plain);
    config(['vault.keys' => []]);
    expect(fn () => app(Cipher::class)->decrypt($item->fresh()->encrypted_payload, 'test-v2', 'item:'.$item->id))->toThrow(HttpException::class);
});

it('rolls back a sensitive create when the fixed unlock expires during encryption before the transaction commits', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    vdGrant($this, $user);
    $real = app(Cipher::class);
    app()->instance(Cipher::class, new class($real)
    {
        public function __construct(private Cipher $real) {}

        public function encrypt(string $plaintext, string $context): array
        {
            $envelope = $this->real->encrypt($plaintext, $context);
            Carbon::setTestNow(now()->addSeconds(301));

            return $envelope;
        }
    });
    try {
        $this->postJson('/vault/api/items', vdData($customer))->assertStatus(423);
        expect(Item::count())->toBe(0)
            ->and(DB::table('vault_audits')->where('action', 'item.created')->count())->toBe(0)
            ->and(DB::table('vault_audits')->where('action', 'item.create')->where('result', 'denied')->count())->toBe(1);
    } finally {
        Carbon::setTestNow();
    }
});

it('rotates encrypted document contents and names atomically and restores an encrypted backup with its retained key version', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    vdGrant($this, $user);
    $item = vdCreate($this, $customer);
    $bytes = "FICTIONAL-BACKUP-DOCUMENT\n  שלום\n";
    $this->post('/vault/api/items/'.$item->id.'/files', ['file' => UploadedFile::fake()->createWithContent('Fictional-backup.txt', $bytes), 'version' => 1], ['Accept' => 'application/json'])->assertOk();
    $file = Attachment::sole();
    $backupItem = $item->fresh()->getRawOriginal();
    $backupFile = $file->getRawOriginal();
    $backupBlob = Storage::disk('local')->get($file->private_path);
    $oldKey = config('vault.keys.test-v1');
    $newKey = base64_encode(random_bytes(32));
    config(['vault.active_key' => 'test-v2', 'vault.keys' => ['test-v1' => $oldKey, 'test-v2' => $newKey]]);
    $preview = app(Rotation::class)->run();
    expect($preview['files'])->toBe(1)->and($file->fresh()->private_path)->toBe($backupFile['private_path']);
    $result = app(Rotation::class)->run(true);
    expect($result['files'])->toBe(1)->and($result['factors'])->toBe(1)
        ->and($file->fresh()->content_key_version)->toBe('test-v2')->and($file->fresh()->name_key_version)->toBe('test-v2')
        ->and($file->fresh()->private_path)->not->toBe($backupFile['private_path']);
    $download = $this->postJson('/vault/api/files/'.$file->id.'/download')->assertOk()->assertDownload('Fictional-backup.txt');
    expect($download->streamedContent())->toBe($bytes);
    $context = 'file:'.$item->id.':'.$file->id.':content';
    expect(app(Cipher::class)->decrypt($backupBlob, 'test-v1', $context))->toBe($bytes)
        ->and(fn () => app(Cipher::class)->decrypt($backupBlob, 'test-v1', 'file:'.Str::uuid().':'.$file->id.':content'))->toThrow(HttpException::class);
    // Restore only the fictional encrypted database/blob backup, without decoding into persistent storage.
    DB::table('vault_items')->where('id', $item->id)->update($backupItem);
    DB::table('vault_files')->where('id', $file->id)->update($backupFile);
    Storage::disk('local')->put($backupFile['private_path'], $backupBlob, ['visibility' => 'private']);
    config(['vault.keys' => ['test-v2' => $newKey]]);
    $this->postJson('/vault/api/files/'.$file->id.'/download')->assertStatus(503);
    config(['vault.keys' => ['test-v1' => $oldKey, 'test-v2' => $newKey]]);
    $restored = $this->postJson('/vault/api/files/'.$file->id.'/download')->assertOk();
    expect($restored->streamedContent())->toBe($bytes);
    $this->postJson('/vault/api/items/'.$item->id.'/content')->assertOk()->assertJsonPath('payload', vdData($customer)['payload']);
});

it('rechecks the exact unlock boundary when a document stream starts late and emits no plaintext bytes', function () {
    $user = vdUser();
    $customer = vdCustomer($user);
    vdGrant($this, $user);
    $item = vdCreate($this, $customer);
    $bytes = 'FICTIONAL-LATE-STREAM-DOCUMENT';
    $this->post('/vault/api/items/'.$item->id.'/files', ['file' => UploadedFile::fake()->createWithContent('Fictional-late.txt', $bytes), 'version' => 1], ['Accept' => 'application/json'])->assertOk();
    $file = Attachment::sole();
    $download = $this->postJson('/vault/api/files/'.$file->id.'/download')->assertOk();
    $callback = $download->baseResponse->getCallback();
    $this->travelTo(UnlockGrant::sole()->expires_at);
    ob_start();
    try {
        try {
            $callback();
            test()->fail('An expired document stream must be rejected before its first byte.');
        } catch (StreamedResponseException $error) {
            $inner = $error->getInnerException();
            expect($inner)->toBeInstanceOf(HttpException::class);
            expect($inner->getStatusCode())->toBe(423)->and(ob_get_contents())->toBe('');
        }
    } finally {
        ob_end_clean();
        $this->travelBack();
    }
    expect(DB::table('vault_audits')->where('action', 'file.downloaded')->where('result', 'success')->count())->toBe(0)
        ->and(DB::table('vault_audits')->where('action', 'file.downloaded')->where('result', 'denied')->count())->toBe(1);
});

it('rejects a second authorized editor saving the same stale item version without losing the first edit', function () {
    $first = vdUser();
    $customer = vdCustomer($first);
    vdGrant($this, $first);
    $item = vdCreate($this, $customer);
    $second = vdUser();
    $second->givePermissionTo('identities.manage');
    $this->patchJson('/vault/api/items/'.$item->id, [...vdData($customer, ['title' => 'First approved revision']), 'version' => 1])->assertOk()->assertJsonPath('item.version', 2);
    $retained = $item->fresh()->getRawOriginal();
    vdGrant($this, $second);
    $this->postJson('/vault/api/items/'.$item->id.'/content')->assertOk()->assertJsonPath('item.version', 2);
    $this->patchJson('/vault/api/items/'.$item->id, [...vdData($customer, ['title' => 'Stale second revision']), 'version' => 1])->assertConflict();
    expect($item->fresh()->getRawOriginal())->toBe($retained)
        ->and($item->fresh()->updated_by)->toBe($first->id);
});

it('finds localized item type labels as metadata while preserving raw type filters and authorized counts', function (string $locale, string $search) {
    $this->vaultDataSettings['locale'] = $locale;
    file_put_contents($this->vaultDataManifest, json_encode($this->vaultDataSettings));
    $owner = vdUser();
    $customer = vdCustomer($owner);
    vdGrant($this, $owner);
    $card = vdCreate($this, $customer, ['title' => 'Business expenses', 'type' => 'card']);
    $titleMatch = vdCreate($this, $customer, ['title' => $search.' guide', 'type' => 'note']);
    vdCreate($this, $customer, ['title' => 'Restricted expenses', 'type' => 'card', 'level' => 1]);
    $other = vdUser();
    $otherCustomer = vdCustomer($other);
    vdGrant($this, $other);
    vdCreate($this, $otherCustomer, ['title' => 'Other business expenses', 'type' => 'card']);
    $viewer = vdUser(false);
    // Explicit customer ownership grants access without broad visibility or level-one privilege.
    $customer->update(['created_by' => $viewer->id]);
    vdGrant($this, $viewer);
    $url = '/vault/api/items?'.http_build_query(['q' => $search, 'sorts' => [['id' => 'title', 'direction' => 'asc']]]);
    $response = $this->getJson($url)->assertOk()->assertJsonPath('page.total', 2)->assertJsonPath('state.q', $search);
    expect(array_column($response->json('page.data'), 'id'))->toEqualCanonicalizing([$card->id, $titleMatch->id]);
    $filtered = $this->getJson('/vault/api/items?'.http_build_query(['q' => $search, 'filters' => [['id' => 'type', 'op' => 'eq', 'value' => 'card']]]))->assertOk()->assertJsonPath('page.total', 1);
    expect($filtered->json('page.data.0.cells.type'))->toBe('card')->and($filtered->json('page.data.0.id'))->toBe($card->id);
    $this->getJson('/vault/api/items?'.http_build_query(['q' => 'FICTIONAL-SECRET-ONLY']))->assertOk()->assertJsonPath('page.total', 0);
})->with([['en', 'Payment card'], ['he', 'כרטיס אשראי']]);
