<?php

use App\Models\User;
use Awove\Core\Imports\Batch;
use Awove\Core\Imports\DocumentImporter;
use Awove\Core\Imports\DocumentSource;
use Awove\Core\Imports\DocumentSourcePolicy;
use Awove\Core\Imports\DocumentSources;
use Awove\Core\Imports\DocumentTransfer;
use Awove\Core\Imports\GeneratedDocumentSourcePolicy;
use Awove\Core\Imports\GeneratedHistorySourcePolicy;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\LegacyHistory;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Operation;
use Awove\Core\Imports\ReferenceSourcePolicy;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Document;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Documents;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Exceptions\StreamedResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

// Deliberately outside Feature/RefreshDatabase: durable reservations must really commit.
beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test')->and(DB::transactionLevel())->toBe(0);
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('base_test');
    $this->withoutVite();
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    $this->artisan('crm:install')->assertSuccessful();
    Storage::fake('local');
    $GLOBALS['durable_document_files'] = [];
    $GLOBALS['durable_document_dirs'] = [];
});

afterEach(function () {
    $this->travelBack();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($GLOBALS['durable_document_files'] ?? [] as $path) {
        if (is_file($path) && ! is_link($path)) {
            unlink($path);
        }
    }
    foreach ($GLOBALS['durable_document_dirs'] ?? [] as $directory) {
        (new Filesystem)->deleteDirectory($directory);
    }
    // Only the explicitly guarded base_test database; do not use unsupported migration downs.
    expect(DB::selectOne('SELECT current_database() AS name')->name)->toBe('base_test');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    RefreshDatabaseState::$migrated = false;
});

function durableDocumentFile(string $name = 'Original proposal.pdf', ?string $bytes = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'basecrm-durable-document-');
    file_put_contents($path, $bytes ?? "%PDF-1.4\n% Fictional ordinary document.\n%%EOF");
    $GLOBALS['durable_document_files'][] = $path;

    return new UploadedFile($path, $name, null, null, true);
}

function durableDocumentFixture(): array
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo(['imports.manage', 'identities.manage', 'leads.assign', 'leads.view.all', 'leads.edit.all']);
    $identity = app(Identities::class)->create($user, 'contacts', ['name' => 'Noa Cohen']);
    $policy = new class implements DocumentSourcePolicy
    {
        public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void
        {
            abort_unless($batch->provider === 'fixture-documents' && $sourceItem->board_id === 'ordinary-board'
                && $source->data['role'] === 'ordinary-files' && str_starts_with($source->data['source_id'], 'asset-'), 403);
        }
    };
    app(DocumentSources::class)->register('fixture-documents', 'ordinary-files', $policy);
    $ledger = app(Ledger::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'fixture-documents', 'source_account' => 'fictional-account',
        'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)]);
    $key = (string) Str::uuid();
    $parent = $ledger->claim($user, $boundary, $batch['id'], ['board_id' => 'ordinary-board', 'entity_type' => 'item', 'entity_id' => 'source-item', 'target_role' => 'contact', 'relation_key' => 'primary'],
        ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => hash('sha256', 'Fictional identity source'), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $ledger->finish($user, $boundary, $parent['id'], $key, ['state' => 'verified', 'target_type' => 'core.contact', 'target_id' => (string) $identity->id, 'result_fingerprint' => hash('sha256', 'Fictional identity target')]);
    $source = ['role' => 'ordinary-files', 'entity_type' => 'asset', 'source_id' => 'asset-proposal', 'source_parent_id' => 'source-item',
        'source_item_mapping_id' => $parent['mapping_id'], 'kind' => 'file-column', 'column_id' => 'files', 'source_index' => 0,
        'update_id' => null, 'reply_id' => null, 'history_mapping_id' => null, 'author' => ['source_id' => '000077', 'name' => 'Yael Cohen'],
        'source_created_at' => '2020-02-29T09:30:52.123456789+02:00', 'source_updated_at' => null];

    return compact('user', 'identity', 'ledger', 'boundary', 'batch', 'parent', 'source') + ['key' => (string) Str::uuid(), 'file' => durableDocumentFile()];
}

function importDurableDocument(array $f, array $extra = []): array
{
    return app(DocumentImporter::class)->import($f['user'], $f['boundary'], $f['batch']['id'], $f['parent']['mapping_id'],
        $extra['key'] ?? $f['key'], $extra['source'] ?? $f['source'], $extra['file'] ?? $f['file']);
}

it('commits exact private bytes provenance and receipt together and replays only its retained journal', function () {
    $f = durableDocumentFixture();
    $result = importDurableDocument($f);
    $document = Document::sole();
    $transfer = DocumentTransfer::sole();
    expect($result['receipt']['state'])->toBe('verified')->and($result['document_id'])->toBe($document->id)
        ->and($transfer->state)->toBe('committed')->and($transfer->document_id)->toBe($document->id)
        ->and($document->private_path)->toBe($transfer->private_path)->and($document->sha256)->toBe(hash_file('sha256', $f['file']->getRealPath()))
        ->and(file_get_contents(Storage::disk('local')->path($document->private_path)))->toBe(file_get_contents($f['file']->getRealPath()))
        ->and(Storage::disk('local')->getVisibility($document->private_path))->toBe('private');
    $replay = importDurableDocument($f, ['source' => array_reverse($f['source'], true)]);
    $alias = importDurableDocument($f, ['key' => (string) Str::uuid()]);
    expect($replay['document_id'])->toBe($document->id)->and($alias['document_id'])->toBe($document->id)
        ->and(Document::count())->toBe(1)->and(DocumentTransfer::count())->toBe(1)->and(Storage::disk('local')->allFiles())->toBe([$document->private_path]);
    $response = $this->actingAs($f['user'])->getJson('/people/contacts/'.$f['identity']->id.'/documents')->assertOk()
        ->assertJsonPath('documents.0.source.author.name', 'Yael Cohen')
        ->assertJsonPath('documents.0.source.source_created_at', $f['source']['source_created_at'])
        ->assertJsonPath('documents.0.source.context', null)->assertJsonMissingPath('documents.0.source.author.source_id');
    expect($response->getContent())->not->toContain('source_metadata', 'private_path', 'sha256', 'mapping_id', 'ordinary-board');
    expect($transfer->toArray())->not->toHaveKeys(['source_metadata', 'private_path', 'sha256', 'identity_mapping_id']);
    $this->get('/people/contacts/'.$f['identity']->id.'/documents/'.$document->id.'/download')->assertOk()->assertDownload()
        ->assertHeader('Content-Type', 'application/octet-stream');
    expect(fn () => $transfer->update(['original_name' => 'Changed.pdf']))->toThrow(LogicException::class);
});

it('rejects ambient transactions before reserving or writing a path', function () {
    $f = durableDocumentFixture();
    DB::beginTransaction();
    try {
        expect(fn () => importDurableDocument($f))->toThrow(HttpException::class, 'committed transaction boundary');
    } finally {
        DB::rollBack();
    }
    expect(DocumentTransfer::count())->toBe(0)->and(Document::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('retains its durable reservation after the document transaction rolls back and only explicit cleanup quarantines it', function () {
    $f = durableDocumentFixture();
    Event::listen('eloquent.creating: '.Document::class, fn () => throw new RuntimeException('Fictional private failure must not surface.'));
    try {
        expect(fn () => importDurableDocument($f))->toThrow(RuntimeException::class, 'Inspect its retained transfer');
    } finally {
        Event::forget('eloquent.creating: '.Document::class);
    }
    $transfer = DocumentTransfer::sole();
    $operation = Operation::findOrFail($transfer->operation_id);
    expect(Document::count())->toBe(0)->and($transfer->state)->toBe('reserved')->and($operation->state)->toBe('claimed')
        ->and(Storage::disk('local')->exists($transfer->private_path))->toBeTrue()->and(DB::transactionLevel())->toBe(0);
    $status = app(DocumentImporter::class)->reconcile($f['user'], $f['boundary'], $transfer->id);
    expect($status['exists'])->toBeTrue()->and($status['bytes_match'])->toBeTrue()->and($transfer->fresh()->state)->toBe('reserved');
    $retry = importDurableDocument($f);
    expect($retry['receipt']['state'])->toBe('claimed')->and($retry['document_id'])->toBeNull()->and(DocumentTransfer::count())->toBe(1);
    $cleaned = app(DocumentImporter::class)->reconcile($f['user'], $f['boundary'], $transfer->id, true);
    expect($cleaned['state'])->toBe('cleaned')->and($operation->fresh()->state)->toBe('failed')->and(Storage::disk('local')->allFiles())->toBe([]);
    $new = importDurableDocument($f, ['key' => (string) Str::uuid()]);
    expect($new['receipt']['state'])->toBe('conflict')->and($new['receipt']['error_code'])->toBe('prior_unresolved_operation')->and(Document::count())->toBe(0);
});

it('survives actual process death after byte transfer without committing a document or losing ownership evidence', function () {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('This process-death regression requires the POSIX CLI extensions.');
    }
    $f = durableDocumentFixture();
    $pid = pcntl_fork();
    expect($pid)->toBeGreaterThanOrEqual(0);
    if ($pid === 0) {
        DB::purge();
        Event::listen('eloquent.creating: '.Document::class, fn () => posix_kill(getmypid(), SIGKILL));
        importDurableDocument($f);
        exit(91);
    }
    pcntl_waitpid($pid, $status);
    DB::purge();
    expect(pcntl_wifsignaled($status))->toBeTrue()->and(pcntl_wtermsig($status))->toBe(SIGKILL);
    $transfer = DocumentTransfer::sole();
    expect(Document::count())->toBe(0)->and($transfer->state)->toBe('reserved')->and(Operation::findOrFail($transfer->operation_id)->state)->toBe('claimed')
        ->and(Storage::disk('local')->exists($transfer->private_path))->toBeTrue();
    app(DocumentImporter::class)->reconcile($f['user'], $f['boundary'], $transfer->id, true);
    expect(Storage::disk('local')->allFiles())->toBe([])->and($transfer->fresh()->state)->toBe('cleaned');
});

it('keeps uncommitted bytes when profile authority is revoked during explicit cleanup receipt handling', function () {
    $f = durableDocumentFixture();
    Event::listen('eloquent.creating: '.Document::class, fn () => throw new RuntimeException('Fictional interrupted transfer.'));
    try {
        expect(fn () => importDurableDocument($f))->toThrow(RuntimeException::class);
    } finally {
        Event::forget('eloquent.creating: '.Document::class);
    }
    $transfer = DocumentTransfer::sole();
    Event::listen('eloquent.updated: '.Operation::class, function (Operation $operation) use ($f): void {
        if ($operation->state === 'failed') {
            $f['user']->syncRoles([]);
            $f['user']->revokePermissionTo('identities.manage');
        }
    });
    try {
        expect(fn () => app(DocumentImporter::class)->reconcile($f['user'], $f['boundary'], $transfer->id, true))->toThrow(AuthorizationException::class);
    } finally {
        Event::forget('eloquent.updated: '.Operation::class);
    }
    expect(Storage::disk('local')->exists($transfer->private_path))->toBeTrue()
        ->and($transfer->fresh()->state)->toBe('reserved')
        ->and(Operation::findOrFail($transfer->operation_id)->state)->toBe('claimed')->and(Document::count())->toBe(0);
});

it('retains accepted bytes when a committed transaction loses its caller acknowledgment', function () {
    $f = durableDocumentFixture();
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
        if ($event->connection->transactionLevel() === 0 && DocumentTransfer::where('state', 'committed')->exists()) {
            throw new RuntimeException('Fictional lost COMMIT acknowledgment.');
        }
    });
    try {
        expect(fn () => importDurableDocument($f))->toThrow(RuntimeException::class, 'Inspect its retained transfer');
    } finally {
        Event::forget(TransactionCommitted::class);
    }
    $transfer = DocumentTransfer::sole();
    $document = Document::sole();
    expect($transfer->state)->toBe('committed')->and(Operation::findOrFail($transfer->operation_id)->state)->toBe('verified')
        ->and(Storage::disk('local')->get($document->private_path))->toBe(file_get_contents($f['file']->getRealPath()));
    $replay = importDurableDocument($f);
    expect($replay['document_id'])->toBe($document->id)->and(Document::count())->toBe(1)->and(DocumentTransfer::count())->toBe(1);
});

it('serializes competing committed callers without transferring or accepting the same occurrence twice', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('This concurrency regression requires the POSIX CLI extensions.');
    }
    $f = durableDocumentFixture();
    $start = tempnam(sys_get_temp_dir(), 'basecrm-document-start-');
    unlink($start);
    $GLOBALS['durable_document_files'][] = $start;
    $children = [];
    foreach ([0, 1] as $index) {
        $output = tempnam(sys_get_temp_dir(), 'basecrm-document-result-');
        $GLOBALS['durable_document_files'][] = $output;
        $pid = pcntl_fork();
        expect($pid)->toBeGreaterThanOrEqual(0);
        if ($pid === 0) {
            DB::purge();
            DB::statement("SET lock_timeout = '10s'");
            DB::statement("SET statement_timeout = '15s'");
            for ($attempt = 0; ! is_file($start) && $attempt < 500; $attempt++) {
                usleep(10000);
                clearstatcache(true, $start);
            }
            try {
                if (! is_file($start)) {
                    exit(90);
                }
                $result = importDurableDocument($f);
                file_put_contents($output, $result['receipt']['state'], LOCK_EX);
                exit(0);
            } catch (Throwable) {
                exit(91);
            }
        }
        $children[] = [$pid, $output];
    }
    DB::purge();
    file_put_contents($start, 'Fictional synchronized callers.');
    $states = [];
    foreach ($children as [$pid, $output]) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
        $states[] = file_get_contents($output);
    }
    DB::purge();
    expect($states)->toContain('verified');
    foreach ($states as $state) {
        expect($state)->toBeIn(['claimed', 'verified']);
    }
    expect(Document::count())->toBe(1)->and(DocumentTransfer::count())->toBe(1)
        ->and(DocumentTransfer::sole()->state)->toBe('committed')
        ->and(Storage::disk('local')->allFiles())->toBe([Document::sole()->private_path]);
    expect(importDurableDocument($f)['receipt']['state'])->toBe('verified');
});

it('checks byte equality again after the document and receipt callbacks before accepting the transaction', function () {
    $f = durableDocumentFixture();
    Event::listen('eloquent.created: '.Document::class, fn (Document $document) => Storage::disk('local')->put($document->private_path, 'Fictional corruption after initial readback.'));
    try {
        expect(fn () => importDurableDocument($f))->toThrow(RuntimeException::class, 'Inspect its retained transfer');
    } finally {
        Event::forget('eloquent.created: '.Document::class);
    }
    $transfer = DocumentTransfer::sole();
    expect($transfer->state)->toBe('reserved')->and(Document::count())->toBe(0)->and(Operation::findOrFail($transfer->operation_id)->state)->toBe('claimed');
    $status = app(DocumentImporter::class)->reconcile($f['user'], $f['boundary'], $transfer->id);
    expect($status['exists'])->toBeTrue()->and($status['bytes_match'])->toBeFalse();
});

it('rolls back the document verified receipt and journal activation on late expiry or authority changes', function (string $change) {
    $f = durableDocumentFixture();
    Event::listen('eloquent.created: '.Document::class, function () use ($change, $f): void {
        match ($change) {
            'expiry' => $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds(),
            'permission' => $f['user']->revokePermissionTo('imports.manage'),
            'inactive' => $f['user']->forceFill(['active' => false])->save(),
            'paused' => Batch::findOrFail($f['batch']['id'])->update(['state' => 'paused']),
            'runtime' => config(['app.url' => 'https://fictional-changed.example.test']),
        };
    });
    try {
        expect(fn () => importDurableDocument($f))->toThrow(HttpException::class);
    } finally {
        Event::forget('eloquent.created: '.Document::class);
    }
    $transfer = DocumentTransfer::sole();
    expect($transfer->state)->toBe('reserved')->and(Document::count())->toBe(0)->and(Operation::findOrFail($transfer->operation_id)->state)->toBe('claimed');
})->with(['expiry', 'permission', 'inactive', 'paused', 'runtime']);

it('rejects corrupt readback and never overwrites accepted bytes or adopts a receipt without its journal', function () {
    $f = durableDocumentFixture();
    $accepted = importDurableDocument($f);
    $document = Document::sole();
    $original = Storage::disk('local')->get($document->private_path);
    expect(fn () => importDurableDocument($f, ['file' => durableDocumentFile('Original proposal.pdf', "%PDF-1.4\nChanged fictional bytes.\n%%EOF")]))->toThrow(HttpException::class);
    expect(Storage::disk('local')->get($document->private_path))->toBe($original)->and(Document::count())->toBe(1);
    Storage::disk('local')->put($document->private_path, 'Fictional corrupted stored bytes.');
    expect(fn () => importDurableDocument($f))->toThrow(HttpException::class, 'bytes require reconciliation');
    expect(fn () => app(DocumentImporter::class)->reconcile($f['user'], $f['boundary'], $accepted['transfer_id'], true))->toThrow(HttpException::class);
    Storage::disk('local')->put($document->private_path, $original);
    DB::table('crm_import_document_transfers')->where('id', $accepted['transfer_id'])->delete();
    expect(fn () => importDurableDocument($f))->toThrow(HttpException::class, 'target requires reconciliation');
    expect(Document::count())->toBe(1)->and(Storage::disk('local')->get($document->private_path))->toBe($original);
});

it('rechecks resource authority after initial or replay receipt writes', function (string $mode) {
    $f = durableDocumentFixture();
    if ($mode === 'replay') {
        importDurableDocument($f);
    }
    $event = $mode === 'replay' ? 'eloquent.created: '.Operation::class : 'eloquent.updated: '.Operation::class;
    Event::listen($event, function (Operation $operation) use ($f): void {
        if ($operation->state === 'verified' && $operation->result_target_type === 'core.document') {
            // Keep imports.manage; only actual client-write authority disappears.
            $f['user']->syncRoles([]);
            $f['user']->revokePermissionTo('identities.manage');
        }
    });
    try {
        expect(fn () => importDurableDocument($f, $mode === 'replay' ? ['key' => (string) Str::uuid()] : []))->toThrow(AuthorizationException::class);
    } finally {
        Event::forget($event);
    }
    expect(Document::count())->toBe($mode === 'replay' ? 1 : 0)->and(DocumentTransfer::count())->toBe(1)
        ->and(DocumentTransfer::sole()->state)->toBe($mode === 'replay' ? 'committed' : 'reserved')
        ->and(Operation::count())->toBe(2);
})->with(['initial', 'replay']);

it('binds storage root separately from the runtime and refuses symlinked owned paths', function (string $change) {
    $f = durableDocumentFixture();
    $originalDisk = Storage::disk('local');
    $outside = tempnam(sys_get_temp_dir(), 'basecrm-document-outside-');
    file_put_contents($outside, 'Fictional retained outside bytes.');
    $GLOBALS['durable_document_files'][] = $outside;
    Event::listen('eloquent.created: '.DocumentTransfer::class, function (DocumentTransfer $transfer) use ($change, $outside, $originalDisk): void {
        if ($change === 'root') {
            $root = dirname($outside).'/basecrm-document-other-'.Str::uuid();
            $GLOBALS['durable_document_dirs'][] = $root;
            Storage::set('local', Storage::build(['driver' => 'local', 'root' => $root]));
        } else {
            $parent = dirname($originalDisk->path($transfer->private_path));
            mkdir($parent, 0700, true);
            symlink($outside, $originalDisk->path($transfer->private_path));
        }
    });
    try {
        expect(fn () => importDurableDocument($f))->toThrow(HttpException::class);
    } finally {
        Event::forget('eloquent.created: '.DocumentTransfer::class);
        Storage::set('local', $originalDisk);
    }
    $transfer = DocumentTransfer::sole();
    if ($change === 'symlink') {
        expect(fn () => app(DocumentImporter::class)->reconcile($f['user'], $f['boundary'], $transfer->id, true))->toThrow(HttpException::class);
        unlink($originalDisk->path($transfer->private_path));
    }
    expect(file_get_contents($outside))->toBe('Fictional retained outside bytes.')->and(Document::count())->toBe(0)->and($transfer->state)->toBe('reserved');
})->with(['root', 'symlink']);

it('requires server-registered ordinary source ownership and fresh identity mappings before any reservation', function () {
    $f = durableDocumentFixture();
    $source = $f['source'];
    $source['role'] = 'vault-files';
    expect(fn () => importDurableDocument($f, ['source' => $source]))->toThrow(HttpException::class);
    $source = [...$f['source'], 'is_vault' => false];
    expect(fn () => importDurableDocument($f, ['source' => $source]))->toThrow(ValidationException::class);
    $parent = Mapping::findOrFail($f['parent']['mapping_id']);
    DB::table('crm_import_mappings')->where('id', $parent->id)->update(['source_account' => 'another-fictional-account']);
    expect(fn () => importDurableDocument($f))->toThrow(HttpException::class);
    expect(DocumentTransfer::count())->toBe(0)->and(Document::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('binds update attachments to verified retained history and exposes only authorized context links', function () {
    $f = durableDocumentFixture();
    $lead = app(Leads::class)->create($f['user'], ['contact_id' => $f['identity']->id, 'owner_id' => $f['user']->id, 'status_id' => 'new']);
    $key = (string) Str::uuid();
    $parent = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], ['board_id' => 'ordinary-board', 'entity_type' => 'item', 'entity_id' => 'source-item', 'target_role' => 'lead', 'relation_key' => 'primary'],
        ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => hash('sha256', 'Fictional lead source'), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $f['ledger']->finish($f['user'], $f['boundary'], $parent['id'], $key, ['state' => 'verified', 'target_type' => 'core.lead', 'target_id' => (string) $lead->id, 'result_fingerprint' => hash('sha256', 'Fictional lead target')]);
    $history = app(LegacyHistory::class)->append($f['user'], $f['boundary'], $f['batch']['id'], $parent['mapping_id'],
        ['kind' => 'update', 'source_id' => 'source-update', 'source_parent_id' => 'source-item', 'revision' => 1, 'request_key' => (string) Str::uuid(), 'previous_receipt_id' => null, 'reply_to_mapping_id' => null],
        ['body_html' => '<p>Fictional ordinary conversation.</p>', 'author' => ['source_id' => '000077', 'name' => 'Yael Cohen'], 'source_created_at' => '2020-02-29T09:30:52Z', 'source_updated_at' => null]);
    $source = [...$f['source'], 'kind' => 'update', 'source_item_mapping_id' => $parent['mapping_id'], 'column_id' => null, 'source_index' => null,
        'update_id' => 'source-update', 'history_mapping_id' => $history['receipt']['mapping_id']];
    $imported = importDurableDocument($f, ['source' => $source]);
    $metadata = app(Documents::class)->forIdentity($f['user'], 'contacts', $f['identity']->id)['documents'][0];
    expect($metadata['source']['context'])->toBe(['kind' => 'update', 'lead_id' => $lead->id, 'activity_id' => $history['activity_id'], 'url' => '/leads/'.$lead->id]);
    $reader = User::factory()->create(['name' => 'Uri Ben David', 'active' => true]);
    $reader->assignRole('Employee');
    $reader->givePermissionTo('identities.view.all');
    expect($reader->can('imports.manage'))->toBeFalse();
    $readOnly = app(Documents::class)->forIdentity($reader, 'contacts', $f['identity']->id);
    expect($readOnly['can_upload'])->toBeFalse()->and($readOnly['documents'][0]['source']['author']['name'])->toBe('Yael Cohen')
        ->and($readOnly['documents'][0]['source']['context'])->toBeNull();
    $stranger = User::factory()->create(['name' => 'Tamar Erez', 'active' => true]);
    $stranger->assignRole('Employee');
    expect(fn () => app(DocumentImporter::class)->sourceMetadata($stranger, Document::sole()))->toThrow(AuthorizationException::class);
    $aliasKey = (string) Str::uuid();
    $alias = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], ['board_id' => 'ordinary-board', 'entity_type' => 'update', 'entity_id' => 'source-update', 'target_role' => 'legacy-history', 'relation_key' => 'fictional-alias'],
        ['request_key' => $aliasKey, 'revision' => 1, 'source_fingerprint' => hash('sha256', 'Metadata is not trusted source proof'), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $f['ledger']->finish($f['user'], $f['boundary'], $alias['id'], $aliasKey, ['state' => 'verified', 'target_type' => 'core.activity', 'target_id' => (string) $history['activity_id'], 'result_fingerprint' => hash('sha256', 'Fictional attestation')]);
    $falseParent = [...$source, 'history_mapping_id' => $alias['mapping_id']];
    expect(fn () => importDurableDocument($f, ['source' => $falseParent, 'key' => (string) Str::uuid()]))->toThrow(HttpException::class, 'history association changed');
    $source['update_id'] = 'another-source-update';
    expect(fn () => importDurableDocument($f, ['source' => $source, 'key' => (string) Str::uuid()]))->toThrow(HttpException::class);
    expect(Document::count())->toBe(1)->and(DocumentTransfer::sole()->document_id)->toBe($imported['document_id']);
});

function generatedReferenceFixture(string $column = 'amount', string $type = 'numbers'): array
{
    $f = durableDocumentFixture();
    app(DocumentSources::class)->register('fixture-documents', 'ordinary-reference', new class($column) implements ReferenceSourcePolicy
    {
        public function __construct(private string $column) {}

        public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void
        {
            abort_unless($source->data['role'] === 'ordinary-reference', 403);
        }

        public function authorizeFields($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source, array $columnIds): void
        {
            abort_unless($columnIds === [$this->column], 403);
        }
    });
    $f['source'] = [...$f['source'], 'role' => 'ordinary-reference', 'entity_type' => 'item', 'source_id' => 'source-item', 'kind' => 'generated-reference', 'column_id' => null, 'source_index' => null,
        'parent_item_id' => null, 'reference_revision' => 1, 'review_flags' => ['approved_separate_party_requires_review'], 'reference_columns' => [$column]];
    $f['payload'] = ['cells' => [['id' => $column, 'label' => $column === 'amount' ? 'מחיר מקור' : 'Original note', 'type' => $type, 'representation' => 'json-string', 'available' => true, 'value' => '123456789012345678901234567890.123400']]];

    return $f;
}
function importGeneratedReference(array $f): array
{
    return app(DocumentImporter::class)->importReference($f['user'], $f['boundary'], $f['batch']['id'], $f['parent']['mapping_id'], $f['key'], $f['source'], $f['payload']);
}
it('stores truthful generated references with exact original JSON strings and immutable revision replay', function () {
    $f = generatedReferenceFixture();
    $r = importGeneratedReference($f);
    $doc = Document::sole();
    $bytes = Storage::disk('local')->get($doc->private_path);
    expect($bytes)->toContain('Generated source reference', '123456789012345678901234567890.123400', 'מחיר מקור')->and($doc->mime_type)->toBe('text/plain')->and($doc->original_name)->toContain('review notes');
    expect(importGeneratedReference($f)['document_id'])->toBe($r['document_id']);
    $this->actingAs($f['user'])->getJson('/people/contacts/'.$f['identity']->id.'/documents')->assertOk()->assertJsonPath('documents.0.source.kind', 'generated-reference')->assertJsonPath('documents.0.source.item_id', 'source-item')->assertJsonPath('documents.0.source.review_flags.0', 'approved_separate_party_requires_review');
    $f['source']['reference_revision'] = 2;
    $f['key'] = (string) Str::uuid();
    expect(fn () => importGeneratedReference($f))->toThrow(HttpException::class, 'Unchanged original');
    expect(Document::count())->toBe(1);
    $f['payload']['cells'][0]['value'] = '0.000100';
    $next = importGeneratedReference($f);
    expect($next['document_id'])->not->toBe($r['document_id'])->and(Document::count())->toBe(2);
});
it('refuses disguised uploads and cross-owner cells for generated references', function () {
    $f = generatedReferenceFixture();
    expect(fn () => importDurableDocument($f))->toThrow(HttpException::class, 'generated reference writer');
    $f['payload']['cells'][0]['id'] = 'marketing-consent';
    $f['source']['reference_columns'] = ['marketing-consent'];
    expect(fn () => importGeneratedReference($f))->toThrow(HttpException::class)->and(Document::count())->toBe(0)->and(DocumentTransfer::count())->toBe(0);
});
it('requires source Lead access for generated reference listing download and source metadata on a shared profile', function () {
    $f = generatedReferenceFixture();
    $reader = User::factory()->create(['name' => 'Tamar Levi', 'active' => true]);
    $reader->assignRole('Employee');
    $reader->givePermissionTo(['leads.view.own', 'leads.edit.own']);
    $lead = app(Leads::class)->create($f['user'], ['contact_id' => $f['identity']->id, 'owner_id' => $f['user']->id, 'status_id' => 'new', 'source' => 'Website']);
    app(Leads::class)->create($f['user'], ['contact_id' => $f['identity']->id, 'owner_id' => $reader->id, 'status_id' => 'new', 'source' => 'Website']);
    $key = (string) Str::uuid();
    $map = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], ['board_id' => 'ordinary-board', 'entity_type' => 'item', 'entity_id' => 'source-item', 'target_role' => 'lead', 'relation_key' => 'primary'], ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => str_repeat('a', 64), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $f['ledger']->finish($f['user'], $f['boundary'], $map['id'], $key, ['state' => 'verified', 'target_type' => 'core.lead', 'target_id' => (string) $lead->id, 'result_fingerprint' => str_repeat('b', 64)]);
    $f['source']['source_item_mapping_id'] = $map['mapping_id'];
    $r = importGeneratedReference($f);
    $this->actingAs($reader)->getJson('/people/contacts/'.$f['identity']->id.'/documents')->assertOk()->assertJsonCount(0, 'documents');
    $this->get('/people/contacts/'.$f['identity']->id.'/documents/'.$r['document_id'].'/download')->assertForbidden();
    expect(fn () => app(DocumentImporter::class)->sourceMetadata($reader, Document::find($r['document_id'])))->toThrow(AuthorizationException::class);
});

it('retains child source lineage through an explicit parent Lead context without creating another Lead', function () {
    $f = generatedReferenceFixture();
    $lead = app(Leads::class)->create($f['user'], ['contact_id' => $f['identity']->id, 'owner_id' => $f['user']->id, 'status_id' => 'new']);
    $map = function (string $role) use ($f, $lead): int {
        $key = (string) Str::uuid();
        $receipt = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], ['board_id' => 'ordinary-child-board', 'entity_type' => 'item', 'entity_id' => 'source-child', 'target_role' => $role, 'relation_key' => 'parent-context'], ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => str_repeat('a', 64), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
        $f['ledger']->finish($f['user'], $f['boundary'], $receipt['id'], $key, ['state' => 'verified', 'target_type' => 'core.lead', 'target_id' => (string) $lead->id, 'result_fingerprint' => str_repeat('b', 64)]);

        return $receipt['mapping_id'];
    };
    $f['source'] = [...$f['source'], 'source_id' => 'source-child', 'source_parent_id' => 'source-child', 'parent_item_id' => 'source-item', 'source_item_mapping_id' => $map('primary-lead')];
    expect(fn () => importGeneratedReference($f))->toThrow(HttpException::class, 'lineage changed');
    $f['source']['source_item_mapping_id'] = $map('subitem-context');
    $result = importGeneratedReference($f);
    $source = app(DocumentImporter::class)->sourceMetadata($f['user'], Document::findOrFail($result['document_id']));
    expect($source['item_id'])->toBe('source-child')->and($source['parent_item_id'])->toBe('source-item')->and(Lead::count())->toBe(1);
    expect(DocumentTransfer::sole()->sourceContext()->data['source_id'])->toBe('source-child');
});

it('fails closed when a generated reference journal is disguised or missing', function () {
    $f = generatedReferenceFixture();
    $result = importGeneratedReference($f);
    $transfer = DocumentTransfer::sole();
    $source = $f['source'];
    foreach (['parent_item_id', 'reference_revision', 'review_flags', 'reference_columns'] as $key) {
        unset($source[$key]);
    }
    $source = [...$source, 'entity_type' => 'asset', 'kind' => 'file-column', 'source_id' => 'source-file', 'column_id' => 'files', 'source_index' => 0];
    DB::table('crm_import_document_transfers')->where('id', $transfer->id)->update(['source_metadata' => json_encode($source)]);
    $url = '/people/contacts/'.$f['identity']->id.'/documents/'.$result['document_id'].'/download';
    $this->actingAs($f['user'])->get($url)->assertStatus(409);
    DB::table('crm_import_document_transfers')->where('id', $transfer->id)->delete();
    $this->get($url)->assertStatus(409);
});

function internalHistoryDocumentFixture(): array
{
    $f = durableDocumentFixture();
    $f['identity'] = app(Identities::class)->create($f['user'], 'companies', ['name' => 'Mymisrad internal fixture']);
    $key = (string) Str::uuid();
    $f['parent'] = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], ['board_id' => 'ordinary-board', 'entity_type' => 'item', 'entity_id' => '880001', 'target_role' => 'internal-reference-context', 'relation_key' => 'primary'], ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => str_repeat('a', 64), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $f['ledger']->finish($f['user'], $f['boundary'], $f['parent']['id'], $key, ['state' => 'verified', 'target_type' => 'core.company', 'target_id' => (string) $f['identity']->id, 'result_fingerprint' => str_repeat('b', 64)]);
    app(DocumentSources::class)->register('fixture-documents', 'internal-history', new class implements GeneratedHistorySourcePolicy
    {
        public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void
        {
            abort_unless($batch->provider === 'fixture-documents' && $batch->source_account === 'fictional-account' && $sourceItem->board_id === 'ordinary-board'
                && $sourceItem->entity_id === '880001' && $sourceItem->target_type === 'core.company' && $identity->target_type === 'core.company', 403);
        }
    });
    $f['source'] = [...$f['source'], 'role' => 'internal-history', 'entity_type' => 'update', 'source_id' => '990001', 'source_parent_id' => '880001', 'source_item_mapping_id' => $f['parent']['mapping_id'], 'kind' => 'generated-history', 'column_id' => null, 'source_index' => null, 'update_id' => '990001', 'reply_id' => null, 'history_mapping_id' => null, 'reply_to_document_mapping_id' => null];
    $f['payload'] = ['body_html' => '<p>Fictional internal discussion.</p><script>Original source only</script>', ...array_intersect_key($f['source'], array_flip(['author', 'source_created_at', 'source_updated_at']))];

    return $f;
}
function importInternalHistoryDocument(array $f): array
{
    return app(DocumentImporter::class)->importGeneratedHistory($f['user'], $f['boundary'], $f['batch']['id'], $f['parent']['mapping_id'], $f['key'], $f['source'], $f['payload']);
}
it('retains internal update and reply bodies as truthful generated history Documents without fake Leads or source columns', function () {
    $f = internalHistoryDocumentFixture();
    $update = importInternalHistoryDocument($f);
    $document = Document::findOrFail($update['document_id']);
    $bytes = Storage::disk('local')->get($document->private_path);
    expect($bytes)->toContain('Generated source history reference', '990001', 'Fictional internal discussion.')->not->toContain('"cells"');
    $original = json_decode(substr($bytes, strpos($bytes, '{')), true, 64, JSON_THROW_ON_ERROR);
    expect($original['body_html'])->toBe($f['payload']['body_html']);
    expect($document->mime_type)->toBe('text/plain')->and(importInternalHistoryDocument($f)['document_id'])->toBe($update['document_id']);
    $this->actingAs($f['user'])->getJson('/people/companies/'.$f['identity']->id.'/documents')->assertOk()->assertJsonPath('documents.0.source.kind', 'generated-history')->assertJsonPath('documents.0.source.item_id', '880001')->assertJsonPath('documents.0.source.update_id', '990001');
    $f['key'] = (string) Str::uuid();
    $f['source'] = [...$f['source'], 'entity_type' => 'reply', 'source_id' => '990002', 'reply_id' => '990002', 'reply_to_document_mapping_id' => $update['receipt']['mapping_id']];
    $f['payload']['body_html'] = '<p>Fictional internal reply.</p>';
    $reply = importInternalHistoryDocument($f);
    expect(Document::count())->toBe(2)->and(Lead::count())->toBe(0)->and(Activity::count())->toBe(0);
    $meta = app(DocumentImporter::class)->sourceMetadata($f['user'], Document::findOrFail($reply['document_id']));
    expect($meta['update_id'])->toBe('990001')->and($meta['reply_id'])->toBe('990002')->and($meta['context'])->toBeNull();
});
it('requires dedicated internal history authorization and matching original attribution', function () {
    $f = internalHistoryDocumentFixture();
    expect(fn () => importDurableDocument($f))->toThrow(HttpException::class, 'generated reference writer');
    $f['payload']['source_created_at'] = '2020-01-01T00:00:00Z';
    expect(fn () => importInternalHistoryDocument($f))->toThrow(HttpException::class, 'attribution changed');
    $f['payload']['source_created_at'] = $f['source']['source_created_at'];
    app()->instance(DocumentSources::class, new DocumentSources);
    expect(fn () => importInternalHistoryDocument($f))->toThrow(HttpException::class);
    expect(Document::count())->toBe(0)->and(DocumentTransfer::count())->toBe(0);
});
it('rejects wrong generated history reply parents and changed immutable bodies without duplicate Documents', function () {
    $f = internalHistoryDocumentFixture();
    $update = importInternalHistoryDocument($f);
    $f['payload']['body_html'] = '<p>Changed source body.</p>';
    expect(fn () => importInternalHistoryDocument($f))->toThrow(HttpException::class, 'replay conflicts');
    $f['key'] = (string) Str::uuid();
    $f['source'] = [...$f['source'], 'entity_type' => 'reply', 'source_id' => '990002', 'reply_id' => '990002', 'reply_to_document_mapping_id' => $f['parent']['mapping_id']];
    expect(fn () => importInternalHistoryDocument($f))->toThrow(HttpException::class, 'reply parent changed');
    expect(Document::count())->toBe(1)->and(DocumentTransfer::sole()->document_id)->toBe($update['document_id']);
});

it('keeps original HTML inside generated reference JSON strings exact without weakening native TXT MIME checks', function () {
    $f = generatedReferenceFixture('note', 'text');
    $f['payload']['cells'][0]['value'] = json_encode('<script>Fictional original text</script>', JSON_UNESCAPED_SLASHES);
    $result = importGeneratedReference($f);
    $doc = Document::findOrFail($result['document_id']);
    $bytes = Storage::disk('local')->get($doc->private_path);
    $original = json_decode(substr($bytes, strpos($bytes, '{')), true, 64, JSON_THROW_ON_ERROR);
    expect($doc->mime_type)->toBe('text/plain')->and($original['cells'][0]['value'])->toBe($f['payload']['cells'][0]['value'])
        ->and(importGeneratedReference($f)['document_id'])->toBe($result['document_id']);
});

function generatedDocumentMapping(array $f, string $entity, string $id, string $role, string $targetType, int $targetId, string $board = 'ordinary-board'): int
{
    $key = (string) Str::uuid();
    $receipt = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], ['board_id' => $board, 'entity_type' => $entity, 'entity_id' => $id, 'target_role' => $role, 'relation_key' => 'primary'],
        ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => hash('sha256', $id.$role), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $f['ledger']->finish($f['user'], $f['boundary'], $receipt['id'], $key, ['state' => 'verified', 'target_type' => $targetType, 'target_id' => (string) $targetId, 'result_fingerprint' => hash('sha256', $role.$id)]);

    return $receipt['mapping_id'];
}

function generatedDocumentFixture(bool $review = true): array
{
    $f = durableDocumentFixture();
    $scope = $review ? 'import-review' : 'profile';
    $f['identity_map'] = $f['parent']['mapping_id'];
    $parent = null;
    $parentMap = null;
    if ($review) {
        $f['identity'] = app(Identities::class)->create($f['user'], 'companies', ['name' => 'Rimon Internal Office']);
        $f['identity_map'] = generatedDocumentMapping($f, 'account', 'fictional-account', 'internal-company', 'core.company', $f['identity']->id);
        $parent = '880001';
        $parentMap = generatedDocumentMapping($f, 'item', $parent, 'internal-reference-context', 'core.company', $f['identity']->id);
        $itemMap = generatedDocumentMapping($f, 'item', '880002', 'subitem-context', 'core.company', $f['identity']->id, 'ordinary-child-board');
    } else {
        $itemMap = $f['parent']['mapping_id'];
    }
    app(DocumentSources::class)->register('fixture-documents', 'source-document', new class($scope) implements GeneratedDocumentSourcePolicy
    {
        public function __construct(private string $scope) {}

        public function accessScope(): string
        {
            return $this->scope;
        }

        public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void
        {
            abort_unless($batch->provider === 'fixture-documents' && $batch->source_account === 'fictional-account'
                && $source->data['source_id'] === '990001' && $source->data['column_id'] === 'original_doc', 403);
        }

        public function authorizeDocument($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source, array $payload): void
        {
            abort_unless(count($payload['blocks']) === 2, 403);
        }
    });
    $f['source'] = [...$f['source'], 'role' => 'source-document', 'entity_type' => $review ? 'workdoc' : 'item-description', 'source_id' => '990001',
        'source_parent_id' => $review ? '880002' : 'source-item', 'source_item_mapping_id' => $itemMap, 'kind' => 'generated-document', 'column_id' => 'original_doc', 'source_index' => null,
        'parent_item_id' => $parent, 'parent_board_id' => $review ? 'ordinary-board' : null, 'parent_item_mapping_id' => $parentMap, 'object_id' => $review ? '000011' : null, 'file_id' => $review ? 'file-0002' : null,
        'access_scope' => $scope, 'review_flags' => $review ? ['source_document_purpose_unresolved'] : [], 'document_revision' => 1,
        'author' => $review ? ['source_id' => '000077', 'name' => 'Yael Cohen'] : ['source_id' => null, 'name' => null], 'source_created_at' => null, 'source_updated_at' => $review ? '2020-02-29T09:30:52Z' : null];
    $f['payload'] = ['document_metadata' => $review ? ['id' => '990001', 'object_id' => '000011', 'name' => 'Fictional internal original', 'settings' => '{"retained":"1.00000"}',
        'created_by' => ['id' => '000077', 'name' => 'Yael Cohen'], 'created_at' => '2020-02-29', 'updated_at' => '2020-02-29T09:30:52Z'] : ['id' => '990001'],
        'attachment_metadata' => $review ? ['file_id' => 'file-0002', 'object_id' => '000011', 'creator_id' => '000088', 'created_at' => '2021-01-01'] : null,
        'blocks' => [['id' => '10001', 'doc_id' => '990001', 'parent_block_id' => null, 'type' => 'normal text', 'position' => 1.0,
            'content' => '{"deltaFormat":[{"insert":"<script>Original only</script>"}],"decimal":"12345678901234567890.000100"}', 'created_at' => '2020-02-29', 'updated_at' => null, 'created_by' => ['id' => '000099', 'name' => 'Tamar Cohen']],
            ['id' => '10002', 'doc_id' => '990001', 'parent_block_id' => '10001', 'type' => 'normal text', 'position' => 2,
                'content' => '{"deltaFormat":[{"insert":"Fictional second block"}]}', 'created_at' => null, 'updated_at' => null, 'created_by' => null]]];

    return $f;
}

function importGeneratedDocument(array $f): array
{
    return app(DocumentImporter::class)->importGeneratedDocument($f['user'], $f['boundary'], $f['batch']['id'], $f['identity_map'], $f['key'], $f['source'], $f['payload']);
}

it('retains generated document originals with genuine hierarchy and exact JSON strings without inventing source times', function () {
    $f = generatedDocumentFixture();
    $result = importGeneratedDocument($f);
    $document = Document::findOrFail($result['document_id']);
    $bytes = Storage::disk('local')->get($document->private_path);
    $export = json_decode(substr($bytes, strpos($bytes, '{')), true, 64, JSON_THROW_ON_ERROR);
    expect($export['originals'])->toBe($f['payload'])->and($export['source']['entity_type'])->toBe('workdoc')
        ->and($export['source']['source_id'])->toBe('990001')->and($export['source']['source_parent_id'])->toBe('880002')
        ->and($export['source']['parent_item_id'])->toBe('880001')->and($export['source']['source_index'])->toBeNull()
        ->and($document->mime_type)->toBe('text/plain')->and($document->original_name)->toContain('Unclassified')
        ->and($bytes)->not->toContain('<script>')->and(Lead::count())->toBe(0)->and(Activity::count())->toBe(0);
    $meta = app(DocumentImporter::class)->sourceMetadata($f['user'], $document);
    expect($meta['source_kind'])->toBe('workdoc')->and($meta['access_scope'])->toBe('import-review')->and($meta['source_created_at'])->toBeNull()
        ->and($meta['author']['name'])->toBe('Yael Cohen')->and($export['originals']['attachment_metadata']['creator_id'])->toBe('000088');
    $response = app(Documents::class)->download($f['user'], 'companies', $f['identity']->id, $document);
    ob_start();
    try {
        $response->sendContent();
        $downloaded = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    expect($downloaded)->toBe($bytes);
    expect(importGeneratedDocument($f)['document_id'])->toBe($document->id);
    $f['key'] = (string) Str::uuid();
    expect(importGeneratedDocument($f)['document_id'])->toBe($document->id)->and(Document::count())->toBe(1)->and(DocumentTransfer::count())->toBe(1);
    DB::table('crm_import_mappings')->where('id', $f['source']['parent_item_mapping_id'])->update(['board_id' => 'changed-parent-board']);
    expect(fn () => app(DocumentImporter::class)->sourceMetadata($f['user'], $document))->toThrow(HttpException::class, 'parent context changed');
    DB::table('crm_import_mappings')->where('id', $f['source']['parent_item_mapping_id'])->update(['board_id' => 'ordinary-board']);
    DB::table('crm_import_mappings')->where('id', $f['identity_map'])->update(['board_id' => 'changed-account-board']);
    expect(fn () => app(DocumentImporter::class)->sourceMetadata($f['user'], $document))->toThrow(HttpException::class, 'source mapping changed');
});

it('hides generated import-review documents from ordinary profile readers and revoked import administrators', function () {
    $f = generatedDocumentFixture();
    $result = importGeneratedDocument($f);
    $reader = User::factory()->create(['name' => 'Noa Levi', 'active' => true]);
    $reader->assignRole('Employee');
    $reader->givePermissionTo('identities.manage');
    $url = '/people/companies/'.$f['identity']->id.'/documents';
    $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonCount(0, 'documents');
    $this->get($url.'/'.$result['document_id'].'/download')->assertForbidden();
    expect(fn () => app(DocumentImporter::class)->sourceMetadata($reader, Document::findOrFail($result['document_id'])))->toThrow(HttpException::class);
    $this->actingAs($f['user'])->getJson($url)->assertOk()->assertJsonPath('documents.0.source.access_scope', 'import-review');
    $f['user']->revokePermissionTo('imports.manage');
    $this->getJson($url)->assertOk()->assertJsonCount(0, 'documents');
    $this->get($url.'/'.$result['document_id'].'/download')->assertForbidden();
});

it('rejects generated document access-scope downgrade fake indexing and unregistered content ownership', function () {
    $f = generatedDocumentFixture();
    $f['source']['access_scope'] = 'profile';
    expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class, 'ownership is not registered');
    $f['source']['access_scope'] = 'import-review';
    expect(fn () => importDurableDocument($f))->toThrow(HttpException::class, 'generated reference writer');
    $f['source']['source_index'] = 0;
    expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class);
    $f['source']['source_index'] = null;
    app()->instance(DocumentSources::class, new DocumentSources);
    app(DocumentSources::class)->register('fixture-documents', 'source-document', new class implements DocumentSourcePolicy
    {
        public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void {}
    });
    expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class, 'ownership is not registered');
    expect(Document::count())->toBe(0)->and(DocumentTransfer::count())->toBe(0);
});

it('rejects generated document foreign parents and ordinary Company aliases for import review', function () {
    $f = generatedDocumentFixture();
    $f['source']['parent_item_id'] = '880099';
    expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class, 'parent context changed');
    $f['source']['parent_item_id'] = '880001';
    $f['identity_map'] = generatedDocumentMapping($f, 'item', '880003', 'ordinary-company', 'core.company', $f['identity']->id);
    expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class, 'approved internal Company');
    expect(Document::count())->toBe(0)->and(DocumentTransfer::count())->toBe(0);
});

it('requires the source Lead as well as its shared profile for generated item-description reads', function () {
    $f = generatedDocumentFixture(false);
    $reader = User::factory()->create(['name' => 'Tamar Levi', 'active' => true]);
    $reader->assignRole('Employee');
    $reader->givePermissionTo(['leads.view.own', 'leads.edit.own']);
    $lead = app(Leads::class)->create($f['user'], ['contact_id' => $f['identity']->id, 'owner_id' => $f['user']->id, 'status_id' => 'new']);
    app(Leads::class)->create($f['user'], ['contact_id' => $f['identity']->id, 'owner_id' => $reader->id, 'status_id' => 'new']);
    $f['source']['source_item_mapping_id'] = generatedDocumentMapping($f, 'item', 'source-item', 'primary-lead', 'core.lead', $lead->id);
    $result = importGeneratedDocument($f);
    $url = '/people/contacts/'.$f['identity']->id.'/documents';
    $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonCount(0, 'documents');
    $this->get($url.'/'.$result['document_id'].'/download')->assertForbidden();
    expect(fn () => app(DocumentImporter::class)->sourceMetadata($reader, Document::findOrFail($result['document_id'])))->toThrow(AuthorizationException::class);
    expect(DocumentTransfer::sole()->sourceContext()->data['source_id'])->toBe('990001')->and(Lead::count())->toBe(2);
});

it('conflicts on changed generated document originals and rejects arbitrary revisions without duplicate files', function () {
    $f = generatedDocumentFixture();
    importGeneratedDocument($f);
    $f['payload']['blocks'][0]['content'] = '{"deltaFormat":[{"insert":"Changed original"}]}';
    expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class, 'replay conflicts');
    $f['source']['document_revision'] = 2;
    expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class);
    expect(Document::count())->toBe(1)->and(DocumentTransfer::count())->toBe(1)->and(count(Storage::disk('local')->allFiles()))->toBe(1);
});

it('refuses duplicate orphan cyclic or malformed original document blocks and forged attribution before reserving bytes', function () {
    $f = generatedDocumentFixture();
    foreach (['duplicate', 'orphan', 'cycle', 'json', 'foreign-doc', 'position'] as $case) {
        $bad = $f;
        match ($case) {
            'duplicate' => $bad['payload']['blocks'][1]['id'] = '10001',
            'orphan' => $bad['payload']['blocks'][1]['parent_block_id'] = '99999',
            'cycle' => $bad['payload']['blocks'][0]['parent_block_id'] = '10002',
            'json' => $bad['payload']['blocks'][0]['content'] = 'invalid-json',
            'foreign-doc' => $bad['payload']['blocks'][0]['doc_id'] = '990099',
            'position' => $bad['payload']['blocks'][0]['position'] = '1',
        };
        expect(fn () => importGeneratedDocument($bad))->toThrow(HttpException::class);
    }
    $f['source']['source_created_at'] = '2020-02-29T00:00:00Z';
    expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class, 'attribution changed');
    expect(Document::count())->toBe(0)->and(DocumentTransfer::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('retains generated document journal ownership when storage commit rolls back and supports explicit reconciliation', function () {
    $f = generatedDocumentFixture();
    Event::listen('eloquent.creating: '.Document::class, fn () => throw new RuntimeException('Fictional interrupted generated document'));
    try {
        expect(fn () => importGeneratedDocument($f))->toThrow(RuntimeException::class, 'Inspect its retained transfer');
    } finally {
        Event::forget('eloquent.creating: '.Document::class);
    }
    $transfer = DocumentTransfer::sole();
    expect($transfer->state)->toBe('reserved')->and(Document::count())->toBe(0)->and(Storage::disk('local')->exists($transfer->private_path))->toBeTrue();
    app(DocumentImporter::class)->reconcile($f['user'], $f['boundary'], $transfer->id, true);
    expect($transfer->fresh()->state)->toBe('cleaned')->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rolls back generated document completion when import authority is revoked by the final receipt event', function () {
    $f = generatedDocumentFixture();
    Event::listen('eloquent.updated: '.Operation::class, function (Operation $operation) use ($f): void {
        if ($operation->state === 'verified') {
            $f['user']->revokePermissionTo('imports.manage');
        }
    });
    try {
        expect(fn () => importGeneratedDocument($f))->toThrow(HttpException::class);
    } finally {
        Event::forget('eloquent.updated: '.Operation::class);
    }
    $transfer = DocumentTransfer::sole();
    expect(Document::count())->toBe(0)->and($transfer->state)->toBe('reserved')->and(Storage::disk('local')->exists($transfer->private_path))->toBeTrue();
});

it('keeps persisted pre-generated-document file and original-reference metadata keys fingerprints reads and replay unchanged', function () {
    $f = durableDocumentFixture();
    $oldKeys = ['role', 'entity_type', 'source_id', 'source_parent_id', 'source_item_mapping_id', 'kind', 'column_id', 'source_index',
        'update_id', 'reply_id', 'history_mapping_id', 'author', 'source_created_at', 'source_updated_at'];
    expect(array_keys(DocumentSource::from($f['source'])->data))->toBe($oldKeys);
    $file = importDurableDocument($f);
    $transfer = DocumentTransfer::findOrFail($file['transfer_id']);
    $legacyFile = $transfer->sourceContext()->data;
    $fingerprint = Operation::findOrFail($file['receipt']['id'])->source_fingerprint;
    DB::table('crm_import_document_transfers')->where('id', $transfer->id)->update(['source_metadata' => json_encode($legacyFile, JSON_THROW_ON_ERROR)]);
    expect(array_keys($transfer->fresh()->sourceContext()->data))->toBe($oldKeys)
        ->and(importDurableDocument($f)['document_id'])->toBe($file['document_id'])
        ->and(Operation::findOrFail($file['receipt']['id'])->source_fingerprint)->toBe($fingerprint);
    app(DocumentSources::class)->register('fixture-documents', 'legacy-reference', new class implements ReferenceSourcePolicy
    {
        public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void {}

        public function authorizeFields($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source, array $columnIds): void
        {
            abort_unless($columnIds === ['note'], 403);
        }
    });
    $f['key'] = (string) Str::uuid();
    $f['source'] = [...$f['source'], 'role' => 'legacy-reference', 'kind' => 'generated-reference', 'entity_type' => 'item', 'source_id' => 'source-item',
        'column_id' => null, 'source_index' => null, 'parent_item_id' => null, 'reference_revision' => 1, 'review_flags' => [], 'reference_columns' => ['note']];
    $f['payload'] = ['cells' => [['id' => 'note', 'label' => 'Fictional source note', 'type' => 'text', 'representation' => 'json-string', 'available' => true, 'value' => '"Original"']]];
    $reference = importGeneratedReference($f);
    $transfer = DocumentTransfer::findOrFail($reference['transfer_id']);
    $legacyReference = $transfer->sourceContext()->data;
    $fingerprint = Operation::findOrFail($reference['receipt']['id'])->source_fingerprint;
    $referenceKeys = [...$oldKeys, 'parent_item_id', 'reference_revision', 'review_flags', 'reference_columns', 'reference_digest'];
    expect(array_keys($legacyReference))->toBe($referenceKeys);
    DB::table('crm_import_document_transfers')->where('id', $transfer->id)->update(['source_metadata' => json_encode($legacyReference, JSON_THROW_ON_ERROR)]);
    expect(array_keys($transfer->fresh()->sourceContext()->data))->toBe($referenceKeys)
        ->and(importGeneratedReference($f)['document_id'])->toBe($reference['document_id'])
        ->and(Operation::findOrFail($reference['receipt']['id'])->source_fingerprint)->toBe($fingerprint);
    $url = '/people/contacts/'.$f['identity']->id.'/documents';
    $this->actingAs($f['user'])->getJson($url)->assertOk()->assertJsonCount(2, 'documents')
        ->assertJsonMissingPath('documents.0.source.access_scope')->assertJsonMissingPath('documents.1.source.access_scope');
    foreach ([$file, $reference] as $result) {
        $download = $this->get($url.'/'.$result['document_id'].'/download')->assertOk()->assertDownload();
        expect($download->streamedContent())->toBe(Storage::disk('local')->get(Document::findOrFail($result['document_id'])->private_path));
    }
    expect(Document::count())->toBe(2)->and(DocumentTransfer::count())->toBe(2);
});

function generatedDocumentDownloadHandle(Document $document): mixed
{
    $path = Storage::disk('local')->path($document->private_path);
    $handles = array_values(array_filter(get_resources('stream'), fn ($stream) => (stream_get_meta_data($stream)['uri'] ?? null) === $path));
    expect($handles)->toHaveCount(1);

    return $handles[0];
}

it('emits zero generated document bytes when import-review authority or hierarchy changes after response creation', function (string $change) {
    $f = generatedDocumentFixture();
    $result = importGeneratedDocument($f);
    $response = app(Documents::class)->download($f['user'], 'companies', $f['identity']->id, Document::findOrFail($result['document_id']));
    $stream = generatedDocumentDownloadHandle(Document::findOrFail($result['document_id']));
    match ($change) {
        'permission' => $f['user']->revokePermissionTo('imports.manage'),
        'parent' => DB::table('crm_import_mappings')->where('id', $f['source']['parent_item_mapping_id'])->update(['board_id' => 'changed-parent-board']),
        'account' => DB::table('crm_import_mappings')->where('id', $f['identity_map'])->update(['board_id' => 'changed-account-board']),
    };
    $error = null;
    ob_start();
    try {
        $response->sendContent();
    } catch (StreamedResponseException $failure) {
        $error = $failure->getInnerException();
    } finally {
        $output = ob_get_contents();
        ob_end_clean();
    }
    expect($error)->toBeInstanceOf(HttpException::class)->and($output)->toBe('')->and(is_resource($stream))->toBeFalse();
})->with(['permission', 'parent', 'account']);

it('emits zero generated item-description bytes after source Lead access is revoked on a still-readable shared profile', function () {
    $f = generatedDocumentFixture(false);
    $reader = User::factory()->create(['name' => 'Tamar Levi', 'active' => true]);
    $reader->assignRole('Employee');
    $reader->givePermissionTo(['leads.view.own', 'leads.edit.own']);
    $lead = app(Leads::class)->create($f['user'], ['contact_id' => $f['identity']->id, 'owner_id' => $reader->id, 'status_id' => 'new']);
    app(Leads::class)->create($f['user'], ['contact_id' => $f['identity']->id, 'owner_id' => $reader->id, 'status_id' => 'new']);
    $f['source']['source_item_mapping_id'] = generatedDocumentMapping($f, 'item', 'source-item', 'primary-lead', 'core.lead', $lead->id);
    $result = importGeneratedDocument($f);
    $response = app(Documents::class)->download($reader, 'contacts', $f['identity']->id, Document::findOrFail($result['document_id']));
    $stream = generatedDocumentDownloadHandle(Document::findOrFail($result['document_id']));
    $lead->update(['owner_id' => $f['user']->id]);
    $error = null;
    ob_start();
    try {
        $response->sendContent();
    } catch (StreamedResponseException $failure) {
        $error = $failure->getInnerException();
    } finally {
        $output = ob_get_contents();
        ob_end_clean();
    }
    expect($error)->toBeInstanceOf(AuthorizationException::class)->and($output)->toBe('')->and(is_resource($stream))->toBeFalse();
});
