<?php

use App\Models\User;
use Awove\Core\Imports\Batch;
use Awove\Core\Imports\DocumentImporter;
use Awove\Core\Imports\DocumentSource;
use Awove\Core\Imports\DocumentSourcePolicy;
use Awove\Core\Imports\DocumentSources;
use Awove\Core\Imports\DocumentTransfer;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\LegacyHistory;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Operation;
use Awove\Core\Imports\ReferenceSourcePolicy;
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

function generatedReferenceFixture(): array
{
    $f = durableDocumentFixture();
    app(DocumentSources::class)->register('fixture-documents', 'ordinary-reference', new class implements ReferenceSourcePolicy
    {
        public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void
        {
            abort_unless($source->data['role'] === 'ordinary-reference', 403);
        }

        public function authorizeFields($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source, array $columnIds): void
        {
            abort_unless($columnIds === ['amount'], 403);
        }
    });
    $f['source'] = [...$f['source'], 'role' => 'ordinary-reference', 'entity_type' => 'item', 'source_id' => 'source-item', 'kind' => 'generated-reference', 'column_id' => null, 'source_index' => null,
        'parent_item_id' => null, 'reference_revision' => 1, 'review_flags' => ['approved_separate_party_requires_review'], 'reference_columns' => ['amount']];
    $f['payload'] = ['cells' => [['id' => 'amount', 'label' => 'מחיר מקור', 'type' => 'numbers', 'representation' => 'json-string', 'available' => true, 'value' => '123456789012345678901234567890.123400']]];

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
