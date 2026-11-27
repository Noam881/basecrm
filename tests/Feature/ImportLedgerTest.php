<?php

use App\Models\User;
use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Operation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->artisan('crm:install')->assertSuccessful();
});

function ledgerActor(): User
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'active' => true]);
    $user->givePermissionTo('imports.manage');

    return $user;
}

function ledgerBatch(array $changes = []): array
{
    return [...['batch_key' => (string) Str::uuid(), 'provider' => 'sample', 'source_account' => '000100',
        'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)], ...$changes];
}

function ledgerSource(array $changes = []): array
{
    return [...['board_id' => '000200', 'entity_type' => 'item', 'entity_id' => '000300', 'target_role' => 'primary-contact', 'relation_key' => 'primary'], ...$changes];
}

function ledgerClaim(array $changes = []): array
{
    return [...['request_key' => (string) Str::uuid(), 'revision' => 1, 'source_fingerprint' => str_repeat('1', 64),
        'target_fingerprint' => null, 'previous_receipt_id' => null], ...$changes];
}

function ledgerResult(array $changes = []): array
{
    return [...['state' => 'verified', 'target_type' => 'core.contact', 'target_id' => '000999', 'result_fingerprint' => str_repeat('2', 64)], ...$changes];
}

function ledgerFixture(): array
{
    $user = ledgerActor();
    $service = app(Ledger::class);
    $boundary = $service->boundary($user);
    $metadata = ledgerBatch();
    $batch = $service->start($user, $boundary, $metadata);

    return [$user, $service, $boundary, $metadata, $batch];
}

it('replays batch and reservation requests without duplicate work or extending a metadata reservation', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    expect($service->start($user, $boundary, $metadata))->toBe($batch)->and(Batch::count())->toBe(1);
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $this->travel(20)->seconds();
    expect($service->claim($user, $boundary, $batch['id'], ledgerSource(), $input))->toBe($first);
    $second = $service->start($user, $boundary, ledgerBatch());
    expect($service->claim($user, $boundary, $second['id'], ledgerSource(), ledgerClaim())['state'])->toBe('busy')
        ->and(Operation::count())->toBe(1)->and(Mapping::count())->toBe(1);
    $accepted = $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult());
    expect($service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult()))->toBe($accepted)
        ->and($accepted['actor_id'])->toBe($user->id)->and($accepted['target'])->toBe(['type' => 'core.contact', 'id' => '000999'])
        ->and(Operation::count())->toBe(1)->and(Mapping::sole()->target_id)->toBe('000999');
    $progress = $service->progress($user, $boundary, $batch['id']);
    expect($progress['receipt_counts'])->toBe(['claimed' => 0, 'verified' => 1, 'failed' => 0, 'conflict' => 0, 'reconciliation_required' => 0])
        ->and($progress['is_migration_completion_proof'])->toBeFalse()
        ->and(Contact::count())->toBe(0)->and(Company::count())->toBe(0)->and(Lead::count())->toBe(0);
});

it('retains cross-batch matching receipts without rewriting the accepted target', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult());
    $before = Operation::findOrFail($first['id'])->getAttributes();
    $next = $service->start($user, $boundary, ledgerBatch());
    $retry = ledgerClaim(['target_fingerprint' => str_repeat('2', 64)]);
    $replay = $service->claim($user, $boundary, $next['id'], ledgerSource(), $retry);
    expect($replay['state'])->toBe('verified')->and($replay['replayed_from_id'])->toBe($first['id'])
        ->and($service->claim($user, $boundary, $next['id'], ledgerSource(), $retry))->toBe($replay)
        ->and(Operation::findOrFail($first['id'])->getAttributes())->toBe($before)
        ->and($service->progress($user, $boundary, $next['id'])['replayed_receipts'])->toBe(1);
});

it('enforces one inflight reservation at the database boundary as well as the locked service path', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $operation = Operation::sole();
    $before = $operation->getAttributes();
    expect(fn () => $operation->update(['source_fingerprint' => str_repeat('d', 64)]))->toThrow(LogicException::class);
    $duplicate = [...$before, 'id' => $operation->id + 10000, 'request_key' => (string) Str::uuid()];
    expect(fn () => DB::transaction(fn () => DB::table('crm_import_operations')->insert($duplicate)))->toThrow(QueryException::class);
    expect(Operation::sole()->getAttributes())->toBe($before);
    expect(fn () => $service->start($user, $boundary, [...$metadata, 'plan_revision' => str_repeat('d', 64)]))->toThrow(HttpException::class);
    expect(Batch::sole()->plan_revision)->toBe($metadata['plan_revision']);
});

it('separates provider accounts target roles and explicit attachment occurrences without name matching', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $keys = [ledgerSource(), ledgerSource(['target_role' => 'primary-company']),
        ledgerSource(['entity_type' => 'asset', 'relation_key' => str_repeat('d', 64)]),
        ledgerSource(['entity_type' => 'asset', 'relation_key' => str_repeat('e', 64)]),
        ledgerSource(['entity_id' => str_repeat('x', 255)])];
    foreach ($keys as $key) {
        expect($service->claim($user, $boundary, $batch['id'], $key, ledgerClaim())['state'])->toBe('claimed');
    }
    $otherAccount = $service->start($user, $boundary, ledgerBatch(['source_account' => '000101']));
    $service->claim($user, $boundary, $otherAccount['id'], ledgerSource(), ledgerClaim());
    expect(Mapping::count())->toBe(6)->and(Mapping::where('entity_id', str_repeat('x', 255))->sole()->entity_id)->toBe(str_repeat('x', 255))
        ->and(Mapping::where('source_account', '000100')->firstOrFail()->entity_id)->toBe('000300');
});

it('records conflicts instead of replacing verified context or silently applying source and target changes', function (string $kind, string $error) {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult());
    $before = Operation::findOrFail($first['id'])->getAttributes();
    $newBatch = $service->start($user, $boundary, ledgerBatch($kind === 'context' ? ['plan_revision' => str_repeat('d', 64)] : []));
    $changes = match ($kind) {
        'source' => ['source_fingerprint' => str_repeat('3', 64)],
        'target' => ['target_fingerprint' => str_repeat('3', 64)],
        'revision' => ['revision' => 2],
        default => [],
    };
    $receipt = $service->claim($user, $boundary, $newBatch['id'], ledgerSource(), ledgerClaim(['target_fingerprint' => str_repeat('2', 64), ...$changes]));
    expect($receipt['state'])->toBe('conflict')->and($receipt['error_code'])->toBe($error)
        ->and(Operation::findOrFail($first['id'])->getAttributes())->toBe($before)
        ->and(Mapping::sole()->target_id)->toBe('000999');
})->with([['source', 'source_changed'], ['target', 'target_changed'], ['revision', 'revision_conflict'], ['context', 'mapping_context_changed']]);

it('requires an explicit next revision and current receipt before recording another outcome while keeping target identity fixed', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult());
    $secondInput = ledgerClaim(['revision' => 2, 'source_fingerprint' => str_repeat('3', 64), 'target_fingerprint' => str_repeat('2', 64), 'previous_receipt_id' => $first['id']]);
    $second = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $secondInput);
    expect($second['state'])->toBe('claimed');
    $service->finish($user, $boundary, $second['id'], $secondInput['request_key'], ledgerResult(['result_fingerprint' => str_repeat('4', 64)]));
    $thirdInput = ledgerClaim(['revision' => 3, 'source_fingerprint' => str_repeat('5', 64), 'target_fingerprint' => str_repeat('4', 64), 'previous_receipt_id' => $second['id']]);
    $third = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $thirdInput);
    $conflict = $service->finish($user, $boundary, $third['id'], $thirdInput['request_key'], ledgerResult(['target_id' => '001000']));
    $retained = Operation::findOrFail($third['id'])->getAttributes();
    expect($service->finish($user, $boundary, $third['id'], $thirdInput['request_key'], ledgerResult(['target_id' => '001000'])))->toBe($conflict);
    expect(fn () => $service->finish($user, $boundary, $third['id'], $thirdInput['request_key'], ledgerResult(['target_id' => '001001'])))->toThrow(HttpException::class, 'Import finish submission conflicts with retained history.');
    expect($conflict['state'])->toBe('conflict')->and($conflict['error_code'])->toBe('target_identity_conflict')
        ->and(Mapping::sole()->target_id)->toBe('000999')->and(Operation::where('state', 'verified')->count())->toBe(2)
        ->and(Operation::findOrFail($third['id'])->getAttributes())->toBe($retained);
});

it('replays an actual finish submission that first encountered expiry without accepting later changed outcomes', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds();
    $expired = $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult());
    $retained = Operation::sole()->getAttributes();
    expect($expired['state'])->toBe('reconciliation_required')->and(strlen(Operation::sole()->finish_intent_fingerprint))->toBe(64)
        ->and($service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult()))->toBe($expired);
    expect(fn () => $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult(['target_id' => '001000'])))->toThrow(HttpException::class, 'Import finish submission conflicts with retained history.');
    expect(Operation::sole()->getAttributes())->toBe($retained)->and(Mapping::sole()->target_id)->toBeNull();
});

it('reads back claim-expired reconciliation status without accepting a finish intent or destination result', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds();
    $expired = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $retained = Operation::sole()->getAttributes();
    expect(Operation::sole()->finish_intent_fingerprint)->toBeNull();
    expect($service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult()))->toBe($expired)
        ->and($service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult(['target_id' => '001000'])))->toBe($expired)
        ->and(Operation::sole()->getAttributes())->toBe($retained)->and(Operation::sole()->result_fingerprint)->toBeNull()
        ->and(Mapping::sole()->target_id)->toBeNull()->and(Operation::where('state', 'verified')->count())->toBe(0);
});

it('keeps a paused inflight reservation unchanged and never resets its deadline on resume', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $before = Operation::sole()->getAttributes();
    $service->pause($user, $boundary, $batch['id']);
    $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds();
    expect(fn () => $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult()))->toThrow(HttpException::class, 'Import reservation is unavailable.');
    expect(Operation::sole()->getAttributes())->toBe($before)->and(Operation::sole()->finish_intent_fingerprint)->toBeNull();
    $service->pause($user, $boundary, $batch['id'], false);
    $expired = $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult());
    expect($expired['state'])->toBe('reconciliation_required')->and($expired['reservation_expires_at'])->toBe($first['reservation_expires_at'])
        ->and(Mapping::sole()->target_id)->toBeNull();
});

it('never takes over an expired or failed reservation when a destination outcome could be unknown', function (bool $failed) {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    if ($failed) {
        $service->finish($user, $boundary, $first['id'], $input['request_key'], ['state' => 'failed', 'error_code' => 'transient_failure']);
    } else {
        $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds();
        expect($service->progress($user, $boundary, $batch['id'])['overdue_reservations'])->toBe(1);
        $expired = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
        expect($expired['state'])->toBe('reconciliation_required')->and($expired['error_code'])->toBe('reservation_expired')
            ->and($expired['reservation_expires_at'])->toBe($first['reservation_expires_at']);
    }
    $blocked = $service->claim($user, $boundary, $batch['id'], ledgerSource(), ledgerClaim());
    expect($blocked['state'])->toBe('conflict')->and($blocked['error_code'])->toBe('prior_unresolved_operation')
        ->and(Operation::where('state', 'claimed')->count())->toBe(0)->and(Mapping::sole()->target_id)->toBeNull();
})->with([false, true]);

it('rechecks permission active status and verified email for every operation despite stale actor objects', function (string $action, string $revocation) {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    expect($user->can('imports.manage'))->toBeTrue();
    $fresh = $user->fresh();
    match ($revocation) {
        'permission' => $fresh->revokePermissionTo('imports.manage'),
        'inactive' => $fresh->forceFill(['active' => false])->save(),
        'unverified' => $fresh->forceFill(['email_verified_at' => null])->save(),
    };
    $before = Operation::sole()->getAttributes();
    $call = match ($action) {
        'boundary' => fn () => $service->boundary($user),
        'start' => fn () => $service->start($user, $boundary, ledgerBatch()),
        'claim' => fn () => $service->claim($user, $boundary, $batch['id'], ledgerSource(), ledgerClaim()),
        'finish' => fn () => $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult()),
        'pause' => fn () => $service->pause($user, $boundary, $batch['id']),
        'progress' => fn () => $service->progress($user, $boundary, $batch['id']),
    };
    try {
        $call();
        test()->fail('Revoked operators must be refused.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(403);
    }
    expect(Operation::sole()->getAttributes())->toBe($before)->and(Batch::count())->toBe(1);
})->with(['boundary', 'start', 'claim', 'finish', 'pause', 'progress'])->with(['permission', 'inactive', 'unverified']);

it('rejects another installation boundary and actual database-role mismatches without exposing configuration', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $originalUrl = config('app.url');
    config(['app.url' => 'https://other.example.test']);
    try {
        $other = $service->boundary($user);
        expect($other)->not->toBe($boundary)->and(strlen($other))->toBe(64);
        expect(fn () => $service->progress($user, $boundary, $batch['id']))->toThrow(HttpException::class, 'Import runtime boundary changed.');
        expect(fn () => $service->progress($user, $other, $batch['id']))->toThrow(HttpException::class, 'Import metadata conflicts with retained history.');
    } finally {
        config(['app.url' => $originalUrl]);
    }
    $connection = DB::connection();
    $username = $connection->getConfig('username');
    $setUsername = function ($value): void {
        $this->config['username'] = $value;
    };
    try {
        $setUsername->call($connection, 'mismatched_expected_role');
        expect(fn () => $service->boundary($user))->toThrow(HttpException::class, 'Import runtime boundary changed.');
    } finally {
        $setUsername->call($connection, $username);
    }
    expect($service->boundary($user))->toBe($boundary)->and(Batch::count())->toBe(1)->and(Operation::count())->toBe(0);
});

it('rejects raw payloads URLs emails control characters and oversized identifiers before any mapping is stored', function (string $kind) {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $source = ledgerSource();
    $claim = ledgerClaim();
    $call = match ($kind) {
        'url' => fn () => $service->claim($user, $boundary, $batch['id'], ledgerSource(['entity_id' => 'https://example.test/private']), $claim),
        'email' => fn () => $service->claim($user, $boundary, $batch['id'], ledgerSource(['entity_id' => 'fictional@example.test']), $claim),
        'control' => fn () => $service->claim($user, $boundary, $batch['id'], ledgerSource(['relation_key' => "first\nsecond"]), $claim),
        'oversized' => fn () => $service->claim($user, $boundary, $batch['id'], ledgerSource(['entity_id' => str_repeat('x', 256)]), $claim),
        'payload' => fn () => $service->claim($user, $boundary, $batch['id'], [...$source, 'payload' => ['password' => 'fictional-never-store']], $claim),
        'raw fingerprint' => fn () => $service->claim($user, $boundary, $batch['id'], $source, ledgerClaim(['source_fingerprint' => '{"name":"fictional-never-store"}'])),
        'batch snapshot' => fn () => $service->start($user, $boundary, [...ledgerBatch(), 'before' => ['name' => 'fictional-never-store']]),
    };
    try {
        $call();
        test()->fail('Nonmetadata input must be refused.');
    } catch (ValidationException $error) {
        expect($error->errors())->toBe(['import' => ['Invalid import metadata.']]);
    }
    expect(Mapping::count())->toBe(0)->and(Operation::count())->toBe(0)->and(Batch::count())->toBe(1);
})->with(['url', 'email', 'control', 'oversized', 'payload', 'raw fingerprint', 'batch snapshot']);

it('retains immutable context and terminal history and protects pause and request-key ownership', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $first = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $other = ledgerActor();
    expect(fn () => $service->claim($other, $boundary, $batch['id'], ledgerSource(), $input))->toThrow(HttpException::class, 'another operator');
    expect(fn () => $service->claim($user, $boundary, $batch['id'], ledgerSource(['entity_id' => 'changed']), $input))->toThrow(HttpException::class);
    expect(fn () => $service->claim($user, $boundary, $batch['id'], ledgerSource(), [...$input, 'source_fingerprint' => str_repeat('f', 64)]))->toThrow(HttpException::class);
    $service->pause($user, $boundary, $batch['id']);
    expect(fn () => $service->claim($user, $boundary, $batch['id'], ledgerSource(), ledgerClaim()))->toThrow(HttpException::class, 'paused');
    $service->pause($user, $boundary, $batch['id'], false);
    $service->finish($user, $boundary, $first['id'], $input['request_key'], ledgerResult());
    expect(fn () => Batch::sole()->update(['plan_revision' => str_repeat('d', 64)]))->toThrow(LogicException::class);
    expect(fn () => Mapping::sole()->update(['entity_id' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => Mapping::sole()->update(['target_id' => 'changed']))->toThrow(LogicException::class);
    expect(fn () => Operation::sole()->update(['state' => 'claimed']))->toThrow(LogicException::class);
    foreach ([Batch::sole(), Mapping::sole(), Operation::sole()] as $record) {
        expect(fn () => $record->delete())->toThrow(LogicException::class);
    }
    expect(fn () => DB::transaction(fn () => DB::table('crm_import_batches')->where('id', $batch['id'])->delete()))->toThrow(QueryException::class);
    expect(Operation::count())->toBe(1)->and(Mapping::sole()->target_id)->toBe('000999');
});

it('refuses payloads and arbitrary error strings in outcome receipts without losing the inflight context', function () {
    [$user, $service, $boundary, $metadata, $batch] = ledgerFixture();
    $input = ledgerClaim();
    $operation = $service->claim($user, $boundary, $batch['id'], ledgerSource(), $input);
    $before = Operation::sole()->getAttributes();
    foreach ([
        [...ledgerResult(), 'snapshot' => ['name' => 'fictional-never-store']],
        ledgerResult(['target_id' => 'https://example.test/private']),
        ['state' => 'failed', 'error_code' => 'fictional raw customer data'],
    ] as $result) {
        expect(fn () => $service->finish($user, $boundary, $operation['id'], $input['request_key'], $result))->toThrow(ValidationException::class, 'Invalid import metadata.');
    }
    expect(Operation::sole()->getAttributes())->toBe($before)->and(Mapping::sole()->target_id)->toBeNull();
});

it('adds metadata tables without rewriting native client data and refuses destructive rollback', function () {
    $user = ledgerActor();
    $contact = Contact::create(['name' => 'Noa Cohen', 'created_by' => $user->id, 'search_key' => 'noacohen']);
    $before = $contact->fresh()->getAttributes();
    // Rebuild this empty metadata dependency graph only inside the isolated base_test transaction.
    foreach (['finance_history_entries', 'finance_history_identities', 'finance_history_records'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
        Schema::drop($table);
    }
    expect(DB::table('crm_import_document_transfers')->count())->toBe(0);
    Schema::drop('crm_import_document_transfers');
    expect(DB::table('crm_import_automation_holds')->count())->toBe(0);
    Schema::drop('crm_import_automation_holds');
    Schema::drop('crm_import_operations');
    Schema::drop('crm_import_mappings');
    Schema::drop('crm_import_batches');
    $migration = require base_path('packages/awove/crm-core/database/migrations/2026_11_27_001710_create_import_metadata_ledger.php');
    $migration->up();
    $holdMigration = require base_path('packages/awove/crm-core/database/migrations/2026_11_27_001720_create_import_automation_holds.php');
    $holdMigration->up();
    $documentTransferMigration = require base_path('packages/awove/crm-core/database/migrations/2026_11_27_001780_create_import_document_transfers.php');
    $documentTransferMigration->up();
    $historyMigration = require base_path('packages/awove/finance/database/migrations/2026_11_27_001800_create_finance_source_history.php');
    $historyMigration->up();
    expect($contact->fresh()->getAttributes())->toBe($before)->and(Batch::count())->toBe(0);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Restore a tested backup');
});
