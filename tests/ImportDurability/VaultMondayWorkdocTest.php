<?php

use App\Models\User;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Operation;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Core\Services\Identities;
use Awove\Vault\Cipher;
use Awove\Vault\Enrollment;
use Awove\Vault\Imports\MondayImport;
use Awove\Vault\Imports\MondayToken;
use Awove\Vault\Imports\MondayWorkdocs;
use Awove\Vault\Imports\MondayWorkdocSnapshot;
use Awove\Vault\Models\ImportEntry;
use Awove\Vault\Models\ImportWorkdoc;
use Awove\Vault\Models\Item;
use Awove\Vault\Notifications\EmailChallenge;
use Awove\Vault\Records;
use Awove\Vault\Riddle;
use Awove\Vault\Unlocks;
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
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Support/MondayWorkdoc.php';

uses(TestCase::class);

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test')->and(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('base_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->withoutVite();
    Http::preventStrayRequests();
    Notification::fake();
    $this->mvwDirectory = realpath(sys_get_temp_dir()).'/vault-workdoc-durable-'.bin2hex(random_bytes(8));
    mkdir($this->mvwDirectory, 0700);
    file_put_contents($this->mvwDirectory.'/manifest.json', json_encode(['id' => 'fictional-vault-import', 'modules' => ['vault']]));
    config(['core.manifest' => $this->mvwDirectory.'/manifest.json', 'core.installation' => 'fictional-vault-import', 'app.debug' => false, 'vault.auth_mode' => 'email_riddle',
        'vault.key_file' => null, 'vault.active_key' => 'fictional-workdoc', 'vault.keys' => ['fictional-workdoc' => base64_encode(random_bytes(32))],
        'vault.monday_token_file' => $this->mvwDirectory.'/token.json', 'vault.monday_plan_file' => $this->mvwDirectory.'/plan.json',
        'vault.monday_workdoc_plan_file' => $this->mvwDirectory.'/workdocs.json',
        'hashing.argon.memory' => 1024, 'hashing.argon.time' => 1, 'hashing.argon.threads' => 1,
        'mail.default' => 'array', 'mail.mailers.array.transport' => 'array']);
    $this->artisan('crm:install')->assertSuccessful();
});

afterEach(function () {
    $this->travelBack();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    (new Filesystem)->deleteDirectory($this->mvwDirectory);
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('base_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    RefreshDatabaseState::$migrated = false;
});

function mvwFixture($test, bool $accepted = true): array
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'active' => true]);
    $user->assignRole('Administrator');
    $user->givePermissionTo([...app(Modules::class)->installed()['vault']->permissions(), 'imports.manage', 'identities.manage']);
    $company = app(Identities::class)->create($user, 'companies', ['name' => 'Fictional Rimon Office']);
    $ledger = app(Ledger::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'monday', 'source_account' => 'fictional-account',
        'mapping_revision' => str_repeat('c', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('d', 64)]);
    $companyKey = (string) Str::uuid();
    $mapping = $ledger->claim($user, $boundary, $batch['id'], ['board_id' => 'fictional-board', 'entity_type' => 'item', 'entity_id' => 'fictional-company', 'target_role' => 'company', 'relation_key' => 'internal'],
        ['request_key' => $companyKey, 'revision' => 1, 'source_fingerprint' => str_repeat('a', 64), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $ledger->finish($user, $boundary, $mapping['id'], $companyKey, ['state' => 'verified', 'target_type' => 'core.company', 'target_id' => (string) $company->id, 'result_fingerprint' => str_repeat('b', 64)]);
    $main = [...mvwMainPlanData(), 'run_id' => (string) Str::uuid(), 'boundary' => $boundary, 'company_mapping_id' => $mapping['mapping_id']];
    file_put_contents($test->mvwDirectory.'/plan.json', json_encode($main));
    chmod($test->mvwDirectory.'/plan.json', 0600);
    $mainHash = hash_file('sha256', $test->mvwDirectory.'/plan.json');
    config(['vault.monday_plan_sha256' => $mainHash]);
    $plan = mvwPlanData($main, $mainHash);
    file_put_contents($test->mvwDirectory.'/workdocs.json', json_encode($plan));
    chmod($test->mvwDirectory.'/workdocs.json', 0600);
    config(['vault.monday_workdoc_plan_sha256' => hash_file('sha256', $test->mvwDirectory.'/workdocs.json')]);
    $token = 'fictional-workdoc-token-'.Str::random(24);
    app(MondayToken::class)->configure(Installation::manifest()['id'], $token, $token);
    $answer = 'fictional shared phrase';
    app(Riddle::class)->configure(Enrollment::installation(), 'Fictional question?', $answer, $answer);
    app(Riddle::class)->approve(Enrollment::installation(), $user);
    $request = Request::create('/vault/api/imports/monday/workdocs/next', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
    $session = new Store('vault-workdoc-test', new ArraySessionHandler(120), Str::random(40));
    $session->start();
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);
    $challenge = app(Unlocks::class)->emailStart($request);
    $notification = Notification::sent($user, EmailChallenge::class)->last();
    parse_str(parse_url($notification->url, PHP_URL_FRAGMENT), $fragment);
    app(Unlocks::class)->email($request, ['challenge_id' => $challenge['challenge_id'], 'token' => $fragment['token']]);
    app(Unlocks::class)->factor($request, ['challenge_id' => $challenge['challenge_id'], 'answer' => $answer]);
    Http::fake(['https://api.monday.com/v2' => fn ($call) => Http::response(['data' => $call['operationName'] === 'BaseCrmVaultWorkdoc'
        ? mvwPage($call['variables']['page']) : mvwMainSource()], 200, ['API-Version' => '2026-10'])]);
    $request->setJson(new InputBag(['request_key' => (string) Str::uuid()]));
    if ($accepted) {
        app(MondayImport::class)->next($request);
    }
    $key = (string) Str::uuid();
    $request->setJson(new InputBag(['request_key' => $key]));

    return compact('user', 'company', 'request', 'key', 'main', 'plan');
}

it('imports one workdoc with numeric IDs exact scalar content and original dates into an encrypted note and replays without source reads', function () {
    $f = mvwFixture($this);
    $result = app(MondayWorkdocs::class)->next($f['request']);
    $item = Item::findOrFail($result['item_id']);
    $payload = app(Records::class)->decrypt($item);
    $snapshot = json_decode(MondayWorkdocSnapshot::export($payload), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    expect($result['replayed'])->toBeFalse()->and($item->title)->toBe('Imported protected workdoc 41300001')
        ->and($item->company_id)->toBe($f['company']->id)->and($item->level)->toBe(1)->and($item->type)->toBe('note')
        ->and($item->encrypted_payload)->not->toContain('FICTIONAL-WORKDOC-ONLY')
        ->and($snapshot['original']['document']['settings'])->toBe(mvwPage(1)['docs'][0]['settings'])
        ->and($snapshot['original']['document']['blocks'])->toBe(mvwPage(1)['docs'][0]['blocks'])
        ->and($snapshot['original']['document']['created_at'])->toBe('2020-02-29')
        ->and($snapshot['parent_entry_id'])->toBe(ImportEntry::sole()->id)->and($snapshot['parent_item_id'])->toBe(ImportEntry::sole()->item_id)
        ->and(ImportWorkdoc::sole()->state)->toBe('committed')->and(Operation::find(ImportWorkdoc::sole()->operation_id)->state)->toBe('verified')
        ->and(json_encode(DB::table('vault_import_workdocs')->get()))->not->toContain('FICTIONAL-WORKDOC-ONLY')->not->toContain('Yael Cohen');
    expect(app(MondayWorkdocs::class)->next($f['request'])['replayed'])->toBeTrue()->and(Item::count())->toBe(2);
    Http::assertSentCount(3);
    $content = app(Records::class)->content($f['request'], $item->id);
    expect($content['workdoc_export'])->toBeTrue()->and($content['source_export'])->toBeFalse();
    $response = app(MondayWorkdocs::class)->export($f['request'], $item->id);
    ob_start();
    try {
        $response->sendContent();
    } finally {
        $bytes = ob_get_clean();
    }
    expect($bytes)->toBe(MondayWorkdocSnapshot::export($payload))->and($response->headers->get('Content-Disposition'))->toContain('original-workdoc-export.txt')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('waits for its verified parent before reading any workdoc or reserving a journal', function () {
    $f = mvwFixture($this, false);
    expect(app(MondayWorkdocs::class)->next($f['request'])['state'])->toBe('awaiting-items')
        ->and(ImportWorkdoc::count())->toBe(0)->and(Item::count())->toBe(0);
    Http::assertNothingSent();
});

it('reports two remaining workdocs after the first of three is accepted without requiring optional supplement configuration', function () {
    $f = mvwFixture($this, false);
    $main = $f['main'];
    foreach (['2', '3'] as $suffix) {
        $main['nodes'][0]['workdocs'][] = ['object_id' => '900719925474099'.$suffix, 'doc_id' => '4130000'.$suffix, 'file_id' => 'fictional-file-'.$suffix, 'column_id' => 'docs'];
    }
    file_put_contents($this->mvwDirectory.'/plan.json', json_encode($main));
    $hash = hash_file('sha256', $this->mvwDirectory.'/plan.json');
    config(['vault.monday_plan_sha256' => $hash]);
    $plan = mvwPlanData($main, $hash);
    foreach (array_slice($main['nodes'][0]['workdocs'], 1) as $doc) {
        $plan['documents'][] = ['source' => ['item_id' => 'fictional-node', 'board_id' => 'fictional-board', ...$doc], 'blocks' => []];
    }
    file_put_contents($this->mvwDirectory.'/workdocs.json', json_encode($plan));
    config(['vault.monday_workdoc_plan_sha256' => hash_file('sha256', $this->mvwDirectory.'/workdocs.json')]);
    app(MondayImport::class)->next($f['request']);
    expect(app(MondayImport::class)->status($f['request'])['workdocs_pending'])->toBe(3);
    $f['request']->setJson(new InputBag(['request_key' => (string) Str::uuid()]));
    app(MondayWorkdocs::class)->next($f['request']);
    config(['vault.monday_workdoc_plan_file' => null, 'vault.monday_workdoc_plan_sha256' => null]);
    expect(app(MondayImport::class)->status($f['request'])['workdocs_pending'])->toBe(2);
});

it('keeps incomplete or expired page reads pending without an encrypted target or receipt', function (string $failure) {
    $f = mvwFixture($this);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://api.monday.com/v2' => function ($call) use ($failure) {
        $data = mvwPage($call['variables']['page']);
        if ($failure === 'expiry') {
            $this->travel(301)->seconds();
        } else {
            $data['docs'][0]['blocks'] = [];
        }

        return Http::response(['data' => $data], 200, ['API-Version' => '2026-10']);
    }]);
    expect(fn () => app(MondayWorkdocs::class)->next($f['request']))->toThrow(HttpException::class);
    expect(Item::count())->toBe(1)->and(ImportWorkdoc::sole()->state)->toBe('pending')
        ->and(Operation::where('result_target_type', 'vault.item')->count())->toBe(1);
})->with(['expiry', 'missing-blocks']);

it('rechecks authorization after Ledger acceptance and rolls back its item receipt and activation', function () {
    $f = mvwFixture($this);
    Event::listen('eloquent.updated: '.Operation::class, function (Operation $operation) use ($f): void {
        if ($operation->state === 'verified' && $operation->batch_id === $f['plan']['run_id']) {
            $f['user']->revokePermissionTo('vault.read');
        }
    });
    try {
        expect(fn () => app(MondayWorkdocs::class)->next($f['request']))->toThrow(HttpException::class);
    } finally {
        Event::forget('eloquent.updated: '.Operation::class);
    }
    expect(Item::count())->toBe(1)->and(ImportWorkdoc::sole()->state)->toBe('pending')
        ->and(Operation::where('batch_id', $f['plan']['run_id'])->count())->toBe(0);
});

it('rolls back a native item and receipt when the grant expires during the encrypted write', function () {
    $f = mvwFixture($this);
    Event::listen('eloquent.created: '.Item::class, fn () => $this->travel(301)->seconds());
    try {
        expect(fn () => app(MondayWorkdocs::class)->next($f['request']))->toThrow(HttpException::class);
    } finally {
        Event::forget('eloquent.created: '.Item::class);
    }
    expect(Item::count())->toBe(1)->and(ImportWorkdoc::sole()->state)->toBe('pending')->and(ImportWorkdoc::sole()->operation_id)->toBeNull();
});

it('reads back actual database ciphertext and refuses mismatched accepted bytes atomically', function () {
    $f = mvwFixture($this);
    Event::listen('eloquent.created: '.Item::class, function (Item $item): void {
        $sealed = app(Cipher::class)->encrypt(json_encode(['fields' => [], 'notes' => 'Fictional mismatch']), 'item:'.$item->id);
        DB::table('vault_items')->where('id', $item->id)->update(['encrypted_payload' => $sealed['ciphertext'], 'key_version' => $sealed['key_version']]);
    });
    try {
        expect(fn () => app(MondayWorkdocs::class)->next($f['request']))->toThrow(HttpException::class);
    } finally {
        Event::forget('eloquent.created: '.Item::class);
    }
    expect(Item::count())->toBe(1)->and(ImportWorkdoc::sole()->state)->toBe('pending')->and(ImportWorkdoc::sole()->operation_id)->toBeNull();
});

it('replays a committed receipt after a lost acknowledgement and refuses later user edits', function () {
    $f = mvwFixture($this);
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0 && ImportWorkdoc::where('state', 'committed')->exists()) {
            throw new RuntimeException('Fictional lost acknowledgement.');
        }
    });
    try {
        expect(fn () => app(MondayWorkdocs::class)->next($f['request']))->toThrow(RuntimeException::class);
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    expect(app(MondayWorkdocs::class)->next($f['request'])['replayed'])->toBeTrue();
    $item = Item::findOrFail(ImportWorkdoc::sole()->item_id);
    $item->update(['title' => 'Fictional user edit']);
    expect(fn () => app(MondayWorkdocs::class)->next($f['request']))->toThrow(HttpException::class);
    expect($item->fresh()->title)->toBe('Fictional user edit')->and(Item::count())->toBe(2);
    Http::assertSentCount(3);
});

it('outputs no TXT bytes after expiry or parent access revocation before streaming', function (string $failure) {
    $f = mvwFixture($this);
    $result = app(MondayWorkdocs::class)->next($f['request']);
    $response = app(MondayWorkdocs::class)->export($f['request'], $result['item_id']);
    if ($failure === 'expiry') {
        $this->travel(300)->seconds();
    } else {
        $f['company']->update(['created_by' => User::factory()->create()->id]);
        $f['user']->revokePermissionTo('identities.manage');
    }
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
})->with(['expiry', 'parent']);
