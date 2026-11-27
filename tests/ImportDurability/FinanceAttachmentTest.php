<?php

use App\Models\User;
use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Operation;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Document;
use Awove\Finance\History\Entry;
use Awove\Finance\History\Files\Attachment;
use Awove\Finance\History\Files\Files;
use Awove\Finance\History\Files\Importer;
use Awove\Finance\History\Files\Source;
use Awove\Finance\History\Files\SourcePolicy;
use Awove\Finance\History\Files\Sources;
use Awove\Finance\History\HistoryCapability;
use Awove\Finance\History\Import;
use Awove\Finance\History\Record;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Exceptions\StreamedResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

// Deliberately outside RefreshDatabase: the first path ownership transaction must actually commit.
beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test')->and(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('base_test');
    $this->withoutVite();
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $manifest = Installation::manifest();
    $manifest['modules'] = ['finance'];
    $manifest['capabilities']['finance:history'] = true;
    $manifest['capabilities']['sales'] = true;
    $path = tempnam(sys_get_temp_dir(), 'finance-files-manifest-');
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    config(['core.manifest' => $path]);
    $this->artisan('crm:install')->assertSuccessful();
    Storage::fake('local');
    $GLOBALS['finance_file_inputs'] = [];
});
afterEach(function () {
    $this->travelBack();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($GLOBALS['finance_file_inputs'] ?? [] as $path) {
        if (is_file($path) && ! is_link($path)) {
            unlink($path);
        }
    }
    @unlink(config('core.manifest'));
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('base_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    RefreshDatabaseState::$migrated = false;
});

function ffFile(string $name = 'Financial reference.pdf', string $bytes = "%PDF-1.4\n% Fictional Finance reference.\n%%EOF"): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'basecrm-finance-file-');
    file_put_contents($path, $bytes);
    $GLOBALS['finance_file_inputs'][] = $path;

    return new UploadedFile($path, $name, null, null, true);
}
function ffPayload(array $extra = []): array
{
    return ['label' => 'Carmel advisory reference', 'facts' => [], 'body_html' => null, 'author' => ['source_id' => '77', 'name' => 'Yael Cohen'],
        'source_created_at' => '2020-02-29T09:30:52.123456789+02:00', 'source_updated_at' => null, ...$extra];
}
function ffFixture(bool $register = true, bool $linked = false): array
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo(['imports.manage', 'identities.manage', 'leads.view.all', ...(new HistoryCapability)->permissions()]);
    $ledger = app(Ledger::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'fixture-finance-files', 'source_account' => 'fictional-account',
        'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)]);
    $links = [];
    $identities = [];
    if ($linked) {
        $other = User::factory()->create(['name' => 'Tamar Levy']);
        foreach ([Contact::class, Company::class] as $i => $class) {
            $identity = $class::create(['name' => $i === 0 ? 'Noa Cohen' : 'Cedar Studio', 'created_by' => $i === 0 ? $user->id : $other->id, 'search_key' => 'fictional']);
            $key = (string) Str::uuid();
            $mapping = $ledger->claim($user, $boundary, $batch['id'], ['board_id' => 'identity-board', 'entity_type' => 'item', 'entity_id' => 'identity-'.$i,
                'target_role' => 'identity', 'relation_key' => 'primary'], ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => str_repeat('d', 64), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
            $ledger->finish($user, $boundary, $mapping['id'], $key, ['state' => 'verified', 'target_type' => $i === 0 ? 'core.contact' : 'core.company', 'target_id' => (string) $identity->id, 'result_fingerprint' => str_repeat('e', 64)]);
            $links[] = ['mapping_id' => $mapping['mapping_id'], 'source_board_id' => 'identity-board', 'source_item_id' => 'identity-'.$i];
            $identities[] = $identity;
        }
    }
    $history = app(Import::class)->create($user, $boundary, $batch['id'], ['board_id' => 'finance-board', 'source_id' => 'finance-item', 'kind' => 'income',
        'owner_id' => $linked ? $user->id : null, 'placement' => $linked ? 'linked' : 'internal', 'associations' => $links,
        'request_key' => (string) Str::uuid(), 'entry_request_key' => (string) Str::uuid()], ffPayload());
    $policy = new class implements SourcePolicy
    {
        public bool $enabled = true;

        public function authorize($actor, Batch $batch, Mapping $recordMapping, Mapping $entryMapping, Source $source): void
        {
            abort_unless($this->enabled && $batch->provider === 'fixture-finance-files' && $recordMapping->board_id === 'finance-board'
                && $recordMapping->entity_id === 'finance-item' && $source->data['asset_id'] === 'asset-finance', 403);
        }
    };
    if ($register) {
        app(Sources::class)->register('fixture-finance-files', $policy);
    }
    $source = ['role' => 'finance-history-files', 'board_id' => 'finance-board', 'asset_id' => 'asset-finance',
        'item_mapping_id' => $history['record_receipt']['mapping_id'], 'item_id' => 'finance-item', 'entry_mapping_id' => $history['entry_receipt']['mapping_id'],
        'kind' => 'file-column', 'column_id' => 'files', 'source_index' => 0, 'update_id' => null, 'reply_id' => null,
        'author' => ['source_id' => '000077', 'name' => 'Yael Cohen'], 'source_created_at' => '2020-02-29', 'source_updated_at' => null];

    return compact('user', 'ledger', 'boundary', 'batch', 'history', 'source', 'policy', 'identities') + ['key' => (string) Str::uuid(), 'file' => ffFile()];
}
function ffImport(array $f, array $extra = []): array
{
    return app(Importer::class)->import($f['user'], $f['boundary'], $extra['batch'] ?? $f['batch']['id'], $extra['key'] ?? $f['key'], $extra['source'] ?? $f['source'], $extra['file'] ?? $f['file']);
}
function ffDuring(string $class, string $event, callable $hook, callable $work): mixed
{
    $dispatcher = $class::getEventDispatcher();
    $class::setEventDispatcher(clone $dispatcher);
    $class::$event($hook);
    try {
        return $work();
    } finally {
        $class::setEventDispatcher($dispatcher);
    }
}

it('commits exact Finance-owned bytes attribution and receipts without duplicating financial facts or Core documents', function () {
    $f = ffFixture();
    $saved = ffImport($f);
    $row = Attachment::sole();
    expect($saved['receipt']['state'])->toBe('verified')->and($saved['attachment_id'])->toBe($row->id)->and($row->state)->toBe('committed')
        ->and($row->source_metadata['source_created_at'])->toBe('2020-02-29')->and($row->private_path)->toStartWith('finance/history/attachments/')
        ->and(file_get_contents(Storage::disk('local')->path($row->private_path)))->toBe(file_get_contents($f['file']->getRealPath()))
        ->and(Storage::disk('local')->getVisibility($row->private_path))->toBe('private')->and(Document::count())->toBe(0)->and(Record::count())->toBe(1)->and(Entry::count())->toBe(1);
    expect($row->toArray())->not->toHaveKeys(['source_metadata', 'private_path', 'sha256', 'operation_id', 'boundary', 'storage_boundary']);
    $response = $this->actingAs($f['user'])->getJson('/finance/history/'.$row->record_id.'/files')->assertOk()
        ->assertJsonPath('files.0.source.created_at', '2020-02-29')->assertJsonPath('files.0.source.created_precision', 'date')->assertJsonPath('files.0.source.author.name', 'Yael Cohen');
    expect($response->getContent())->not->toContain('private_path', 'sha256', 'operation_id', 'item_mapping_id', 'source_metadata', 'T00:00');
    $this->get('/finance/history/'.$row->record_id)->assertInertia(fn (Assert $p) => $p->component('Finance/HistoryRecord')->has('files', 1));
    $download = $this->get('/finance/history/'.$row->record_id.'/files/'.$row->id.'/download')->assertOk();
    expect($download->streamedContent())->toBe(file_get_contents($f['file']->getRealPath()))->and($download->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

it('replays only its retained journal actual Finance parent and actual bytes across reviewed batches', function () {
    $f = ffFixture();
    $first = ffImport($f);
    expect(ffImport($f)['attachment_id'])->toBe($first['attachment_id']);
    $b = Batch::findOrFail($f['batch']['id']);
    $next = $f['ledger']->start($f['user'], $f['boundary'], ['batch_key' => (string) Str::uuid(), ...$b->only(['provider', 'source_account', 'mapping_revision', 'code_revision', 'plan_revision'])]);
    $again = ffImport($f, ['key' => (string) Str::uuid(), 'batch' => $next['id']]);
    expect($again['attachment_id'])->toBe($first['attachment_id'])->and(Attachment::count())->toBe(1)->and(Storage::disk('local')->allFiles())->toBe([Attachment::sole()->private_path]);
    Storage::disk('local')->put(Attachment::sole()->private_path, 'Fictional corruption.');
    expect(fn () => ffImport($f))->toThrow(HttpException::class);
    $this->actingAs($f['user'])->get('/finance/history/'.Attachment::sole()->record_id.'/files/'.Attachment::sole()->id.'/download')->assertStatus(409);
});

it('defaults source ownership to deny and refuses caller targets URLs nonfinancial roles and an outer transaction', function () {
    $f = ffFixture(false);
    expect(fn () => ffImport($f))->toThrow(HttpException::class)->and(Attachment::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    app(Sources::class)->register('fixture-finance-files', $f['policy']);
    foreach ([['role' => 'vault'], ['board_id' => 'other-board'], ['item_id' => 'other-item'], ['record_id' => $f['history']['record_id']], ['source_url' => 'https://private.example.test/file']] as $change) {
        expect(fn () => ffImport($f, ['source' => [...$f['source'], ...$change]]))->toThrow(Exception::class);
    }
    DB::beginTransaction();
    try {
        expect(fn () => ffImport($f))->toThrow(HttpException::class);
    } finally {
        DB::rollBack();
    }
    expect(Attachment::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('retains a durable reserved journal and partial bytes after a business rollback and only explicitly cleans its own failed attempt', function () {
    $f = ffFixture();
    expect(fn () => ffDuring(Attachment::class, 'updating', fn () => throw new RuntimeException('Fictional private exception must stay hidden.'), fn () => ffImport($f)))
        ->toThrow(RuntimeException::class, 'Inspect its retained transfer');
    $row = Attachment::sole();
    expect($row->state)->toBe('reserved')->and(Operation::find($row->operation_id)->state)->toBe('claimed')->and(Storage::disk('local')->exists($row->private_path))->toBeTrue();
    $status = app(Importer::class)->reconcile($f['user'], $f['boundary'], $row->id);
    expect($status['exists'])->toBeTrue()->and($status['bytes_match'])->toBeTrue()->and($status['can_cleanup'])->toBeTrue()->and($row->fresh()->state)->toBe('reserved');
    $other = User::factory()->create(['name' => 'Tamar Levi']);
    $other->assignRole('Employee');
    $other->givePermissionTo(['imports.manage', ...(new HistoryCapability)->permissions()]);
    expect(app(Importer::class)->reconcile($other, $f['boundary'], $row->id)['can_cleanup'])->toBeFalse();
    expect(fn () => app(Importer::class)->reconcile($other, $f['boundary'], $row->id, true))->toThrow(HttpException::class);
    $cleaned = app(Importer::class)->reconcile($f['user'], $f['boundary'], $row->id, true);
    expect($cleaned['state'])->toBe('cleaned')->and(Attachment::count())->toBe(1)->and(Storage::disk('local')->exists($row->private_path))->toBeFalse()->and(Record::count())->toBe(1)->and(Entry::count())->toBe(1);
});

it('retains accepted files after a successful COMMIT whose acknowledgement fails and never deletes them during inspection', function () {
    $f = ffFixture();
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0 && Attachment::where('state', 'committed')->exists()) {
            throw new RuntimeException('Fictional lost COMMIT acknowledgement.');
        }
    });
    try {
        expect(fn () => ffImport($f))->toThrow(RuntimeException::class, 'Inspect its retained transfer');
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    $row = Attachment::sole();
    expect($row->state)->toBe('committed')->and(Operation::find($row->operation_id)->state)->toBe('verified')->and(Storage::disk('local')->exists($row->private_path))->toBeTrue();
    expect(app(Importer::class)->reconcile($f['user'], $f['boundary'], $row->id, true)['can_cleanup'])->toBeFalse();
    expect(ffImport($f)['attachment_id'])->toBe($row->id);
});

it('keeps a committed reservation when its acknowledgement fails before any filesystem work', function () {
    $f = ffFixture();
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0 && Attachment::where('state', 'reserved')->exists()) {
            throw new RuntimeException('Fictional lost reservation acknowledgement.');
        }
    });
    try {
        expect(fn () => ffImport($f))->toThrow(RuntimeException::class, 'Inspect its retained transfer');
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    $row = Attachment::sole();
    expect($row->state)->toBe('reserved')->and(Storage::disk('local')->allFiles())->toBe([])->and(ffImport($f)['receipt']['state'])->toBe('claimed');
    $status = app(Importer::class)->reconcile($f['user'], $f['boundary'], $row->id);
    expect($status['exists'])->toBeFalse()->and($status['can_cleanup'])->toBeTrue();
});

it('survives actual process death after transfer with reserved ownership retained and no accepted attachment', function () {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('The process-death regression requires the POSIX CLI extensions.');
    }
    $f = ffFixture();
    $pid = pcntl_fork();
    expect($pid)->toBeGreaterThanOrEqual(0);
    if ($pid === 0) {
        DB::purge();
        Event::listen('eloquent.updating: '.Attachment::class, fn () => posix_kill(getmypid(), SIGKILL));
        ffImport($f);
        exit(91);
    }
    pcntl_waitpid($pid, $status);
    DB::purge();
    expect(pcntl_wifsignaled($status))->toBeTrue()->and(pcntl_wtermsig($status))->toBe(SIGKILL);
    $row = Attachment::sole();
    expect($row->state)->toBe('reserved')->and(Operation::find($row->operation_id)->state)->toBe('claimed')->and(Storage::disk('local')->exists($row->private_path))->toBeTrue();
    app(Importer::class)->reconcile($f['user'], $f['boundary'], $row->id, true);
    expect(Storage::disk('local')->allFiles())->toBe([])->and($row->fresh()->state)->toBe('cleaned');
});

it('does not extend expired reservations or overwrite orphan bytes after expiry or uncertain work', function () {
    $f = ffFixture();
    expect(fn () => ffDuring(Attachment::class, 'updating', fn () => $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds(), fn () => ffImport($f)))->toThrow(HttpException::class);
    $row = Attachment::sole();
    $deadline = Operation::find($row->operation_id)->reservation_expires_at->toISOString();
    expect($row->state)->toBe('reserved')->and(app(Importer::class)->reconcile($f['user'], $f['boundary'], $row->id)['reservation_expired'])->toBeTrue();
    $again = ffImport($f);
    expect($again['receipt']['state'])->toBe('reconciliation_required')->and(Operation::find($row->operation_id)->reservation_expires_at->toISOString())->toBe($deadline)
        ->and(Attachment::count())->toBe(1)->and(Storage::disk('local')->exists($row->private_path))->toBeTrue();
});

it('rolls business activation back for late authority runtime batch or byte changes while the durable journal keeps ownership', function (string $change) {
    $f = ffFixture();
    expect(fn () => ffDuring(Operation::class, 'updated', function (Operation $op) use ($f, $change): void {
        if ($op->state !== 'verified' || $op->result_target_type !== 'finance.history-attachment') {
            return;
        }
        match ($change) {
            'permission' => $f['user']->revokePermissionTo('finance.history.import'),
            'actor' => $f['user']->forceFill(['active' => false])->save(),
            'batch' => Batch::find($f['batch']['id'])->update(['state' => 'paused']),
            'runtime' => config(['app.url' => 'https://changed.example.test']),
            'policy' => $f['policy']->enabled = false,
            'bytes' => Storage::disk('local')->put(Attachment::sole()->private_path, 'Fictional late corruption.'),
        };
    }, fn () => ffImport($f)))->toThrow(Exception::class);
    $row = Attachment::sole();
    expect($row->state)->toBe('reserved')->and(Operation::find($row->operation_id)->state)->toBe('claimed')->and(Storage::disk('local')->exists($row->private_path))->toBeTrue();
})->with(['permission', 'actor', 'batch', 'runtime', 'policy', 'bytes']);

it('requires current Finance scope and every linked identity for lists profile panels and direct downloads', function () {
    $f = ffFixture(linked: true);
    $saved = ffImport($f);
    $recordId = $f['history']['record_id'];
    $this->actingAs($f['user'])->getJson('/finance/history/identities/contacts/'.$f['identities'][0]->id)->assertOk()->assertJsonCount(1, 'records.0.files');
    $manifest = Installation::manifest();
    file_put_contents(config('core.manifest'), json_encode([...$manifest, 'modules' => []]));
    $this->getJson('/finance/history/'.$recordId.'/files')->assertForbidden();
    $this->get('/finance/history/'.$recordId.'/files/'.$saved['attachment_id'].'/download')->assertForbidden();
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $f['user']->revokePermissionTo(['identities.manage', 'leads.view.all']);
    $this->getJson('/finance/history/'.$recordId.'/files')->assertNotFound();
    $this->get('/finance/history/'.$recordId.'/files/'.$saved['attachment_id'].'/download')->assertNotFound();
    $ordinary = User::factory()->create(['name' => 'Noam Erez']);
    $ordinary->assignRole('Employee');
    $ordinary->givePermissionTo('identities.manage');
    $this->actingAs($ordinary)->getJson('/finance/history/'.$recordId.'/files')->assertForbidden();
    $this->get('/finance/history/'.$recordId.'/files/'.$saved['attachment_id'].'/download')->assertForbidden();
});

it('refuses a public storage root before reserving a path or writing bytes', function () {
    $f = ffFixture();
    $disk = Storage::disk('local');
    $root = config('filesystems.disks.local.root');
    config(['filesystems.disks.local.root' => public_path()]);
    Storage::forgetDisk('local');
    try {
        expect(fn () => ffImport($f))->toThrow(HttpException::class)->and(Attachment::count())->toBe(0);
    } finally {
        config(['filesystems.disks.local.root' => $root]);
        Storage::set('local', $disk);
    }
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('emits zero bytes when authority or file bytes change after response construction but before streaming', function (string $change) {
    $f = ffFixture();
    $saved = ffImport($f);
    $row = Attachment::findOrFail($saved['attachment_id']);
    $response = app(Files::class)->download($f['user'], $row->record_id, $row->id);
    match ($change) {
        'permission' => $f['user']->revokePermissionTo(['finance.history.view.all', 'finance.history.view.own']),
        'module' => file_put_contents(config('core.manifest'), json_encode([...Installation::manifest(), 'modules' => []])),
        'bytes' => Storage::disk('local')->put($row->private_path, 'Fictional late corruption.'),
    };
    $denied = null;
    ob_start();
    try {
        $response->sendContent();
    } catch (StreamedResponseException $error) {
        $denied = $error->getInnerException();
    } finally {
        $emitted = ob_get_clean();
    }
    expect($denied)->toBeInstanceOf(HttpException::class)->and($emitted)->toBe('');
})->with(['permission', 'module', 'bytes']);

it('binds update and reply attachments to actual verified same-record update parents and retains fractional source timestamps', function () {
    $f = ffFixture();
    $input = ['entry_kind' => 'update', 'source_id' => 'update-1', 'source_parent_id' => 'finance-item', 'revision' => 1, 'request_key' => (string) Str::uuid(), 'previous_receipt_id' => null, 'reply_to_mapping_id' => null];
    $update = app(Import::class)->append($f['user'], $f['boundary'], $f['batch']['id'], $f['source']['item_mapping_id'], $input, ffPayload(['body_html' => '<p>Fictional discussion.</p>']));
    $reply = app(Import::class)->append($f['user'], $f['boundary'], $f['batch']['id'], $f['source']['item_mapping_id'], [...$input, 'entry_kind' => 'reply', 'source_id' => 'reply-1', 'request_key' => (string) Str::uuid(), 'reply_to_mapping_id' => $update['entry_receipt']['mapping_id']], ffPayload(['body_html' => '<p>Fictional reply.</p>']));
    $source = [...$f['source'], 'kind' => 'reply', 'column_id' => null, 'source_index' => null, 'entry_mapping_id' => $reply['entry_receipt']['mapping_id'],
        'update_id' => 'update-1', 'reply_id' => 'reply-1', 'source_created_at' => '2020-02-29T09:30:52.123456789+02:00'];
    expect(fn () => ffImport($f, ['source' => [...$source, 'update_id' => 'wrong-update']]))->toThrow(HttpException::class);
    expect(fn () => ffImport($f, ['source' => [...$source, 'entry_mapping_id' => $f['source']['entry_mapping_id']]]))->toThrow(HttpException::class);
    $saved = ffImport($f, ['source' => $source]);
    $this->actingAs($f['user'])->getJson('/finance/history/'.$f['history']['record_id'].'/files')->assertOk()
        ->assertJsonPath('files.0.source.created_at', $source['source_created_at'])->assertJsonPath('files.0.source.created_precision', 'timestamp')->assertJsonPath('files.0.source.reply_id', 'reply-1');
    expect($saved['receipt']['state'])->toBe('verified')->and(Entry::count())->toBe(3);
});
