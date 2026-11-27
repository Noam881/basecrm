<?php

use App\Integrations\Monday\AssetDownloader;
use App\Integrations\Monday\AssetLocation;
use App\Models\User;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Operation;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Core\Services\Identities;
use Awove\Vault\Enrollment;
use Awove\Vault\Files;
use Awove\Vault\Imports\MondayFiles;
use Awove\Vault\Imports\MondayImport;
use Awove\Vault\Imports\MondaySnapshot;
use Awove\Vault\Imports\MondayToken;
use Awove\Vault\Models\Attachment;
use Awove\Vault\Models\ImportEntry;
use Awove\Vault\Models\ImportFileTransfer;
use Awove\Vault\Models\Item;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\UnlockGrant;
use Awove\Vault\Notifications\EmailChallenge;
use Awove\Vault\Records;
use Awove\Vault\Riddle;
use Awove\Vault\Rotation;
use Awove\Vault\Unlocks;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Support/MondayVault.php';

uses(TestCase::class);

class FictionalVaultAssetDownloader extends AssetDownloader
{
    public int $calls = 0;

    public ?Closure $after = null;

    public function download(AssetLocation $asset, int $maximumBytes): string
    {
        $this->calls++;
        expect($asset->id())->toBe('12345001')->and($maximumBytes)->toBe(Files::MAX_BYTES);
        if ($this->after !== null) {
            ($this->after)();
        }

        return mviFileBytes();
    }
}

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test')->and(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('base_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();
    $this->mviDirectory = realpath(sys_get_temp_dir()).'/vault-monday-durable-'.bin2hex(random_bytes(8));
    mkdir($this->mviDirectory, 0700);
    file_put_contents($this->mviDirectory.'/manifest.json', json_encode(['id' => 'fictional-vault-import', 'modules' => ['vault']]));
    config(['core.manifest' => $this->mviDirectory.'/manifest.json', 'core.installation' => 'fictional-vault-import', 'app.debug' => false, 'vault.auth_mode' => 'email_riddle',
        'vault.key_file' => null, 'vault.active_key' => 'fictional-import', 'vault.keys' => ['fictional-import' => base64_encode(random_bytes(32))],
        'vault.monday_token_file' => $this->mviDirectory.'/token.json', 'vault.monday_plan_file' => $this->mviDirectory.'/plan.json',
        'hashing.argon.memory' => 1024, 'hashing.argon.time' => 1, 'hashing.argon.threads' => 1,
        'mail.default' => 'array', 'mail.mailers.array.transport' => 'array']);
    $this->artisan('crm:install')->assertSuccessful();
    Storage::fake('local');
});

afterEach(function () {
    $this->travelBack();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    (new Filesystem)->deleteDirectory($this->mviDirectory);
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('base_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    RefreshDatabaseState::$migrated = false;
});

function mviFixture($test, bool $withFiles = false): array
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'active' => true]);
    $user->assignRole('Administrator');
    $user->givePermissionTo([...app(Modules::class)->installed()['vault']->permissions(), 'imports.manage', 'identities.manage']);
    $company = app(Identities::class)->create($user, 'companies', ['name' => 'Fictional Rimon Office']);
    $ledger = app(Ledger::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'monday', 'source_account' => 'fictional-account',
        'mapping_revision' => str_repeat('c', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('d', 64)]);
    $identityKey = (string) Str::uuid();
    $mapping = $ledger->claim($user, $boundary, $batch['id'], ['board_id' => 'fictional-board', 'entity_type' => 'item', 'entity_id' => 'fictional-company', 'target_role' => 'company', 'relation_key' => 'internal'],
        ['request_key' => $identityKey, 'revision' => 1, 'source_fingerprint' => str_repeat('a', 64), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $ledger->finish($user, $boundary, $mapping['id'], $identityKey, ['state' => 'verified', 'target_type' => 'core.company', 'target_id' => (string) $company->id, 'result_fingerprint' => str_repeat('b', 64)]);
    $plan = [...mviPlanData(), 'run_id' => (string) Str::uuid(), 'boundary' => $boundary, 'company_mapping_id' => $mapping['mapping_id']];
    if ($withFiles) {
        $plan = mviFilePlan($plan);
    }
    file_put_contents($test->mviDirectory.'/plan.json', json_encode($plan));
    chmod($test->mviDirectory.'/plan.json', 0600);
    config(['vault.monday_plan_sha256' => hash_file('sha256', $test->mviDirectory.'/plan.json')]);
    $token = 'fictional-source-token-'.Str::random(24);
    app(MondayToken::class)->configure(Installation::manifest()['id'], $token, $token);
    $answer = 'fictional shared phrase';
    app(Riddle::class)->configure(Enrollment::installation(), 'Fictional question?', $answer, $answer);
    app(Riddle::class)->approve(Enrollment::installation(), $user);
    $request = Request::create('/vault/api/imports/monday/next', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $store = new Store('vault-monday-test', new ArraySessionHandler(120), Str::random(40));
    $store->start();
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);
    $challenge = app(Unlocks::class)->emailStart($request);
    $notification = Notification::sent($user, EmailChallenge::class)->last();
    parse_str(parse_url($notification->url, PHP_URL_FRAGMENT), $fragment);
    app(Unlocks::class)->email($request, ['challenge_id' => $challenge['challenge_id'], 'token' => $fragment['token']]);
    app(Unlocks::class)->factor($request, ['challenge_id' => $challenge['challenge_id'], 'answer' => $answer]);
    $key = (string) Str::uuid();
    $request->setJson(new InputBag(['request_key' => $key]));
    Http::fake(['https://api.monday.com/v2' => function ($request) use ($withFiles) {
        return Http::response(['data' => $request['operationName'] === 'BaseCrmVaultAsset' ? mviAssetData()
            : ($withFiles ? mviFileSourceData() : mviSourceData())], 200, ['API-Version' => '2026-10']);
    }]);

    return compact('user', 'request', 'key', 'company', 'plan');
}

function mviFileFixture($test): array
{
    $f = mviFixture($test, true);
    app(MondayImport::class)->next($f['request']);
    $downloader = new FictionalVaultAssetDownloader;
    app()->instance(AssetDownloader::class, $downloader);
    $fileKey = (string) Str::uuid();
    $f['request']->setJson(new InputBag(['request_key' => $fileKey]));

    return [...$f, 'downloader' => $downloader, 'file_key' => $fileKey];
}

it('imports one frozen node with a real email-riddle grant and stores exact source and history only inside encryption', function () {
    $f = mviFixture($this);
    $before = app(Unlocks::class)->status($f['request']);
    $result = app(MondayImport::class)->next($f['request']);
    $item = Item::sole();
    $entry = ImportEntry::sole();
    expect($result['state'])->toBe('verified')->and($result['accepted'])->toBe(1)->and($result['migration_complete'])->toBeFalse()
        ->and($item->level)->toBe(1)->and($item->company_id)->toBe($f['company']->id)->and($item->contact_id)->toBeNull()
        ->and($item->title)->toBe('Imported secure record fictional-node')->and($entry->state)->toBe('committed');
    $export = json_decode(MondaySnapshot::export(app(Records::class)->decrypt($item)), true, 64, JSON_THROW_ON_ERROR);
    expect($export['original'])->toBe(mviSourceData()['items'][0])
        ->and($item->encrypted_payload)->not->toContain('FICTIONAL-VAULT-IMPORT', 'conversation', 'Yael Cohen')
        ->and(json_encode(DB::table('vault_import_entries')->get()))->not->toContain('FICTIONAL-VAULT-IMPORT', 'conversation', 'Yael Cohen')
        ->and(app(Unlocks::class)->status($f['request'])['expires_at'])->toBe($before['expires_at']);
    $replay = app(MondayImport::class)->next($f['request']);
    expect($replay['replayed'])->toBeTrue()->and($replay['item_id'])->toBe($item->id)->and(Item::count())->toBe(1)->and(ImportEntry::count())->toBe(1);
    Http::assertSentCount(1);
    $f['request']->setJson(new InputBag(['request_key' => (string) Str::uuid()]));
    expect(app(MondayImport::class)->next($f['request'])['state'])->toBe('items-accepted');
});

it('refuses an expired or almost-expired grant before any source call and keeps its window fixed', function (int $seconds) {
    $f = mviFixture($this);
    $this->travel($seconds)->seconds();
    expect(fn () => app(MondayImport::class)->next($f['request']))->toThrow(HttpException::class);
    Http::assertNothingSent();
    expect(Item::count())->toBe(0);
})->with([280, 300]);

it('rolls back a late-expiring encrypted item while preserving only the committed metadata reservation', function () {
    $f = mviFixture($this);
    Event::listen('eloquent.created: '.Item::class, fn () => $this->travel(301)->seconds());
    try {
        expect(fn () => app(MondayImport::class)->next($f['request']))->toThrow(HttpException::class);
    } finally {
        Event::forget('eloquent.created: '.Item::class);
    }
    expect(Item::count())->toBe(0)->and(ImportEntry::sole()->state)->toBe('pending')
        ->and(Operation::where('result_target_type', 'vault.item')->count())->toBe(0)
        ->and(json_encode(DB::table('vault_import_entries')->get()))->not->toContain('FICTIONAL-VAULT-IMPORT');
});

it('replays accepted evidence after an ambiguous commit without another source read or item', function () {
    $f = mviFixture($this);
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0 && ImportEntry::where('state', 'committed')->exists()) {
            throw new RuntimeException('Fictional lost acknowledgment.');
        }
    });
    try {
        expect(fn () => app(MondayImport::class)->next($f['request']))->toThrow(RuntimeException::class);
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    expect(ImportEntry::sole()->state)->toBe('committed')->and(Item::count())->toBe(1);
    expect(app(MondayImport::class)->next($f['request'])['replayed'])->toBeTrue();
    Http::assertSentCount(1);
});

it('refuses edited targets and partial source responses without overwriting or publishing secrets', function () {
    $f = mviFixture($this);
    $result = app(MondayImport::class)->next($f['request']);
    $item = Item::findOrFail($result['item_id']);
    $item->update(['title' => 'User changed this record']);
    expect(fn () => app(MondayImport::class)->next($f['request']))->toThrow(HttpException::class)
        ->and(Item::sole()->title)->toBe('User changed this record');
    Http::assertSentCount(1);
});

it('uses the normal HTTP authentication and locked boundaries before importing', function () {
    $this->getJson('/vault/api/imports/monday')->assertUnauthorized()->assertHeader('Cache-Control');
    $f = mviFixture($this);
    $this->actingAs($f['user'])->postJson('/vault/api/imports/monday/next', ['request_key' => (string) Str::uuid()])->assertStatus(423);
    Http::assertNothingSent();
});

it('imports only the exact frozen file occurrence into native encryption and replays accepted bytes without source access', function () {
    $f = mviFileFixture($this);
    $result = app(MondayFiles::class)->next($f['request']);
    $transfer = ImportFileTransfer::sole();
    $file = Attachment::sole();
    expect($result['state'])->toBe('verified')->and($result['files_accepted'])->toBe(1)->and($transfer->state)->toBe('committed')
        ->and($file->id)->toBe($transfer->file_id)->and(Item::sole()->version)->toBe(2)->and($f['downloader']->calls)->toBe(1)
        ->and(Storage::disk('local')->get($file->private_path))->not->toContain('FICTIONAL-ENCRYPTED-FILE-ONLY')
        ->and($file->encrypted_name)->not->toContain('Yael Cohen', '2020-02-29')
        ->and(json_encode(DB::table('vault_import_file_transfers')->get()))->not->toContain('Yael Cohen', 'signature', 'public_url', 'FICTIONAL-ENCRYPTED-FILE-ONLY');
    $read = app(Files::class)->inspectStored($f['request'], $file);
    expect($read['plaintext'])->toBe(mviFileBytes())->and($read['metadata']['source']['original_name'])->toBe('Yael Cohen original proposal.pdf')
        ->and($read['metadata']['source']['creator']['name'])->toBe('Yael Cohen')
        ->and($read['metadata']['source']['column_created_at'])->toBe('2020-02-29T09:30:52.123456789+02:00');
    expect(app(MondayFiles::class)->next($f['request'])['replayed'])->toBeTrue()->and($f['downloader']->calls)->toBe(1)
        ->and(Attachment::count())->toBe(1)->and(ImportFileTransfer::count())->toBe(1);
    $f['request']->setJson(new InputBag(['request_key' => $f['key']]));
    expect(app(MondayImport::class)->next($f['request'])['replayed'])->toBeTrue();
    Http::assertSentCount(2);
});

it('retains journal-owned ciphertext when the file SQL transaction rolls back and cleanup keeps the ledger quarantined', function () {
    $f = mviFileFixture($this);
    Event::listen('eloquent.creating: '.Attachment::class, fn () => throw new RuntimeException('Fictional SQL failure.'));
    try {
        expect(fn () => app(MondayFiles::class)->next($f['request']))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.Attachment::class);
    }
    $transfer = ImportFileTransfer::sole();
    expect($transfer->state)->toBe('reserved')->and(Attachment::count())->toBe(0)->and(Item::sole()->version)->toBe(1)
        ->and(Storage::disk('local')->exists($transfer->private_path))->toBeTrue()
        ->and(Storage::disk('local')->get($transfer->private_path))->not->toContain('FICTIONAL-ENCRYPTED-FILE-ONLY');
    expect(app(MondayFiles::class)->next($f['request'])['state'])->toBe('reconciliation-required')->and($f['downloader']->calls)->toBe(1);
    $f['request']->setJson(new InputBag([]));
    expect(app(MondayFiles::class)->reconcile($f['request'], $transfer->id))->toMatchArray(['state' => 'reserved', 'exists' => true]);
    $f['request']->setJson(new InputBag(['cleanup' => true]));
    expect(app(MondayFiles::class)->reconcile($f['request'], $transfer->id))->toMatchArray(['state' => 'cleaned', 'ledger_quarantine_retained' => true])
        ->and(Storage::disk('local')->allFiles())->toBe([])->and(Operation::findOrFail($transfer->operation_id)->state)->toBe('failed');
});

it('survives process death after ciphertext transfer with no plaintext staging or attachment adoption', function () {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('The durability regression requires POSIX CLI extensions.');
    }
    $f = mviFileFixture($this);
    $pid = pcntl_fork();
    expect($pid)->toBeGreaterThanOrEqual(0);
    if ($pid === 0) {
        DB::purge();
        Event::listen('eloquent.creating: '.Attachment::class, fn () => posix_kill(getmypid(), SIGKILL));
        app(MondayFiles::class)->next($f['request']);
        exit(91);
    }
    pcntl_waitpid($pid, $status);
    DB::purge();
    $transfer = ImportFileTransfer::sole();
    expect(pcntl_wifsignaled($status))->toBeTrue()->and(pcntl_wtermsig($status))->toBe(SIGKILL)
        ->and(Attachment::count())->toBe(0)->and($transfer->state)->toBe('reserved')
        ->and(Storage::disk('local')->allFiles())->toBe([$transfer->private_path])
        ->and(Storage::disk('local')->get($transfer->private_path))->not->toContain('FICTIONAL-ENCRYPTED-FILE-ONLY');
});

it('retains committed ciphertext and honest replay after a lost file COMMIT acknowledgment', function () {
    $f = mviFileFixture($this);
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0 && ImportFileTransfer::where('state', 'committed')->exists()) {
            throw new RuntimeException('Fictional lost file acknowledgment.');
        }
    });
    try {
        expect(fn () => app(MondayFiles::class)->next($f['request']))->toThrow(RuntimeException::class);
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    expect(Attachment::count())->toBe(1)->and(ImportFileTransfer::sole()->state)->toBe('committed')
        ->and(Storage::disk('local')->exists(Attachment::sole()->private_path))->toBeTrue();
    expect(app(MondayFiles::class)->next($f['request'])['replayed'])->toBeTrue()->and($f['downloader']->calls)->toBe(1);
});

it('checks the real grant after download and the last file audit callback before accepting source bytes', function (string $boundary) {
    $f = mviFileFixture($this);
    if ($boundary === 'download') {
        $f['downloader']->after = fn () => $this->travel(301)->seconds();
    } else {
        Event::listen(QueryExecuted::class, function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "vault_audits"') && in_array('import.file', $query->bindings, true)) {
                $this->travel(301)->seconds();
            }
        });
    }
    try {
        expect(fn () => app(MondayFiles::class)->next($f['request']))->toThrow(HttpException::class);
    } finally {
        Event::forget(QueryExecuted::class);
    }
    expect(Attachment::count())->toBe(0)->and(Item::sole()->version)->toBe(1)
        ->and(ImportFileTransfer::sole()->state)->toBe($boundary === 'download' ? 'pending' : 'reserved');
    expect(count(Storage::disk('local')->allFiles()))->toBe($boundary === 'download' ? 0 : 1);
})->with(['download', 'audit']);

it('rejects source file-column drift before invoking the file downloader', function () {
    $f = mviFileFixture($this);
    $data = mviAssetData();
    $data['items'][0]['column_values'][0]['value'] = '{"files":[]}';
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.monday.com/v2' => Http::response(['data' => $data], 200, ['API-Version' => '2026-10'])]);
    expect(fn () => app(MondayFiles::class)->next($f['request']))->toThrow(HttpException::class)
        ->and($f['downloader']->calls)->toBe(0)->and(Attachment::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(ImportFileTransfer::sole()->state)->toBe('pending');
});

it('retains accepted file provenance and replay through a native dedicated-key rotation', function () {
    $f = mviFileFixture($this);
    app(MondayFiles::class)->next($f['request']);
    $originalPath = Attachment::sole()->private_path;
    config(['vault.keys' => [...config('vault.keys'), 'fictional-next' => base64_encode(random_bytes(32))], 'vault.active_key' => 'fictional-next']);
    expect(app(Rotation::class)->run(true)['files'])->toBe(1)
        ->and(Attachment::sole()->private_path)->not->toBe($originalPath)
        ->and(ImportFileTransfer::sole()->private_path)->toBe($originalPath);
    expect(app(MondayFiles::class)->next($f['request'])['replayed'])->toBeTrue()->and($f['downloader']->calls)->toBe(1);
});

it('rechecks current factor generation and parent authority after binary source access', function (string $change) {
    $f = mviFileFixture($this);
    $f['downloader']->after = function () use ($f, $change): void {
        if ($change === 'factor') {
            $member = Member::where('user_id', $f['user']->id)->firstOrFail();
            $member->update(['factor_generation' => $member->factor_generation + 1]);
        } else {
            // Bypass the normal revocation event only in this fixture to independently exercise the fresh parent Gate.
            $role = Role::findByName('Administrator');
            $permissions = Permission::whereIn('name', ['identities.manage', 'leads.edit.own'])->pluck('id');
            DB::table('role_has_permissions')->where('role_id', $role->id)->whereIn('permission_id', $permissions)->delete();
            DB::table('model_has_permissions')->where('model_id', $f['user']->id)->whereIn('permission_id', $permissions)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    };
    expect(fn () => app(MondayFiles::class)->next($f['request']))->toThrow(HttpException::class)
        ->and(Attachment::count())->toBe(0)->and(ImportFileTransfer::sole()->state)->toBe('pending')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['factor', 'parent']);

it('emits no source-export bytes when its real session expires before the stream begins', function () {
    $f = mviFixture($this);
    $result = app(MondayImport::class)->next($f['request']);
    $response = app(MondayImport::class)->export($f['request'], $result['item_id']);
    $this->travel(301)->seconds();
    $error = null;
    ob_start();
    try {
        $response->sendContent();
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $output = ob_get_clean();
    }
    expect($error)->not->toBeNull()->and($output)->toBe('');
});

it('never adopts a later valid unlock into an existing source request but permits an explicit new request', function () {
    $f = mviFileFixture($this);
    $f['downloader']->after = function () use ($f): void {
        $grant = UnlockGrant::where('user_id', $f['user']->id)->sole();
        $attributes = $grant->getAttributes();
        $grant->delete();
        $this->travel(10)->seconds();
        UnlockGrant::create([...$attributes, 'id' => (string) Str::uuid(), 'issued_at' => now()->toImmutable(), 'expires_at' => now()->toImmutable()->addSeconds(300)]);
        app(Unlocks::class)->requireUnlocked($f['request']); // The replacement is genuinely valid; only adoption by this old request is refused.
    };
    expect(fn () => app(MondayFiles::class)->next($f['request']))->toThrow(HttpException::class)
        ->and(Attachment::count())->toBe(0)->and(ImportFileTransfer::sole()->state)->toBe('pending');
    $f['downloader']->after = null;
    $renewed = Request::create('/vault/api/imports/monday/next-file', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $renewed->setLaravelSession($f['request']->session());
    $renewed->setUserResolver(fn () => $f['user']);
    $renewed->setJson(new InputBag(['request_key' => $f['file_key']]));
    expect(app(MondayFiles::class)->next($renewed)['state'])->toBe('verified')->and(Attachment::count())->toBe(1);
});

it('verifies one representative node and its own file before all other planned nodes are imported', function () {
    $f = mviFixture($this, true);
    $plan = $f['plan'];
    $second = [...$plan['nodes'][0], 'id' => 'fictional-second-node', 'update_ids' => [], 'assets' => ['12345002'],
        'asset_occurrences' => [['id' => '12345002', 'column_id' => 'files', 'index' => 0]]];
    $plan['nodes'][] = $second;
    file_put_contents($this->mviDirectory.'/plan.json', json_encode($plan));
    config(['vault.monday_plan_sha256' => hash_file('sha256', $this->mviDirectory.'/plan.json')]);
    $result = app(MondayImport::class)->next($f['request']);
    expect($result['accepted'])->toBe(1)->and($result['total'])->toBe(2);
    $downloader = new FictionalVaultAssetDownloader;
    app()->instance(AssetDownloader::class, $downloader);
    $f['request']->setJson(new InputBag(['request_key' => (string) Str::uuid()]));
    $file = app(MondayFiles::class)->next($f['request']);
    expect($file['state'])->toBe('verified')->and($file['files_accepted'])->toBe(1)->and($file['file_total'])->toBe(2)
        ->and(Item::count())->toBe(1)->and(Attachment::count())->toBe(1)->and($downloader->calls)->toBe(1);
    $f['request']->setJson(new InputBag(['request_key' => (string) Str::uuid()]));
    $waiting = app(MondayFiles::class)->next($f['request']);
    expect($waiting['state'])->toBe('awaiting-items')->and($waiting['files_eligible'])->toBe(0)
        ->and(ImportFileTransfer::count())->toBe(1)->and($downloader->calls)->toBe(1);
    Http::assertSentCount(2);
});

it('does not fetch or create a file for an unaccepted source item', function () {
    $f = mviFixture($this, true);
    $downloader = new FictionalVaultAssetDownloader;
    app()->instance(AssetDownloader::class, $downloader);
    $result = app(MondayFiles::class)->next($f['request']);
    expect($result['state'])->toBe('awaiting-items')->and($result['files_eligible'])->toBe(0)->and($result['file_total'])->toBe(1)
        ->and(ImportEntry::count())->toBe(0)->and(ImportFileTransfer::count())->toBe(0)->and(Attachment::count())->toBe(0)
        ->and($downloader->calls)->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    Http::assertNothingSent();
});
