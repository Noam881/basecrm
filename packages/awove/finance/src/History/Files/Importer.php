<?php

namespace Awove\Finance\History\Files;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Metadata;
use Awove\Core\Imports\Operation;
use Awove\Core\Imports\Runtime;
use Awove\Core\Services\Documents;
use Awove\Finance\History\Access;
use Awove\Finance\History\Entry;
use Awove\Finance\History\Fingerprints;
use Awove\Finance\History\Records;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Finance files only. First transaction commits ownership before filesystem I/O; no source transport. */
class Importer
{
    private const MAP = ['boundary', 'provider', 'source_account', 'board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key', 'target_type', 'target_id'];

    public function __construct(private Ledger $ledger, private Sources $sources, private Storage $storage, private Documents $documents) {}

    /** A source adapter must authorize the exact Finance occurrence before it fetches any bytes. */
    public function authorizeSource($actor, string $boundary, string $batchId, array $input): void
    {
        $this->outside();
        $source = Source::from($input);
        DB::transaction(fn () => $this->context($actor, $boundary, $batchId, $source, true, true));
    }

    public function import($actor, string $boundary, string $batchId, string $requestKey, array $input, UploadedFile $file): array
    {
        $this->outside();
        $source = Source::from($input);
        $requestKey = Metadata::uuid($requestKey);
        $this->authorizeSource($actor, $boundary, $batchId, $source->data);
        $info = $this->documents->inspectFile($file);
        try {
            $reservation = DB::transaction(function () use ($actor, $boundary, $batchId, $requestKey, $source, $info): array {
                $context = $this->context($actor, $boundary, $batchId, $source, true, true);
                $identity = $this->sourceIdentity($source);
                $fingerprint = $this->sourceFingerprint($context, $source, $info);
                $prior = Operation::where('batch_id', $batchId)->where('request_key', $requestKey)->first();
                $mapping = Mapping::where(['boundary' => $boundary, 'provider' => $context['batch']->provider, 'source_account' => $context['batch']->source_account, ...$identity])->first();
                $retained = $mapping?->target_id === null ? null : $this->retained($actor, $mapping);
                if ($prior?->state === 'verified') {
                    abort_unless($retained && $prior->mapping_id === $mapping->id && hash_equals($prior->source_fingerprint, $fingerprint), 409, 'Finance file replay changed.');
                    $receipt = $this->ledger->finish($actor, $boundary, $prior->id, $requestKey, $this->verifiedResult($retained));
                    $this->verifyCommitted($actor, $retained);
                    $this->context($actor, $boundary, $batchId, $source, true, true);

                    return $this->result($receipt, $retained, false);
                }
                $receipt = $this->ledger->claim($actor, $boundary, $batchId, $identity, ['request_key' => $requestKey, 'revision' => 1,
                    'source_fingerprint' => $fingerprint, 'target_fingerprint' => $retained ? $this->fingerprint($retained) : null, 'previous_receipt_id' => null]);
                if ($receipt['state'] !== 'claimed' || $prior !== null) {
                    $row = $retained ?? (isset($receipt['id']) ? Attachment::where('operation_id', $receipt['id'])->first() : null);
                    if ($receipt['state'] === 'verified') {
                        abort_unless($row !== null, 409, 'Finance file evidence is unavailable.');
                        $this->verifyCommitted($actor, $row);
                    }
                    $this->context($actor, $boundary, $batchId, $source, true, true);

                    return $this->result($receipt, $row, false);
                }
                $id = (string) Str::uuid();
                $row = Attachment::create(['id' => $id, 'operation_id' => $receipt['id'], 'record_id' => $context['record']->id, 'entry_id' => $context['entry']->id,
                    'record_mapping_id' => $source->data['item_mapping_id'], 'entry_mapping_id' => $source->data['entry_mapping_id'],
                    'boundary' => $boundary, 'storage_boundary' => $this->storage->boundary(), 'private_path' => $this->storage->path($id, $info['extension']),
                    'original_name' => $info['name'], 'mime_type' => $info['mime'], 'size_bytes' => $info['size'], 'sha256' => $info['sha256'],
                    'source_metadata' => $source->data, 'state' => 'reserved', 'created_at' => now()]);
                $this->context($actor, $boundary, $batchId, $source, true, true);

                return $this->result($receipt, $row, true);
            });
            if (! $reservation['new_transfer']) {
                return $reservation;
            }

            // No transaction retry, source request or deletion around this non-transactional write.
            return DB::transaction(function () use ($actor, $reservation, $file): array {
                $row = $this->locked($actor, $reservation['transfer_id'], true);
                $info = $this->documents->inspectFile($file);
                abort_unless($row->original_name === $info['name'] && $row->mime_type === $info['mime'] && $row->size_bytes === $info['size']
                    && hash_equals($row->sha256, $info['sha256']), 409, 'Finance source file changed.');
                $this->storage->copyExclusive($row, $file);
                $bytes = $this->storage->inspect($row);
                abort_unless($bytes['exists'] && $bytes['bytes_match'], 409, 'Finance file byte verification failed.');
                $this->locked($actor, $row->id, true);
                $row->update(['state' => 'committed', 'committed_at' => now()]);
                $row->refresh();
                $operation = Operation::findOrFail($row->operation_id);
                $receipt = $this->ledger->finish($actor, $row->boundary, $operation->id, $operation->request_key, $this->verifiedResult($row));
                abort_unless($receipt['state'] === 'verified', 409, 'Finance file import needs reconciliation.');
                $row->refresh();
                $this->verifyCommitted($actor, $row);
                $this->locked($actor, $row->id, false);
                abort_unless($operation->reservation_expires_at->greaterThan(now()), 409, 'Finance file reservation expired.');

                return $this->result($receipt, $row, false);
            });
        } catch (AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            // COMMIT can have succeeded even when its acknowledgement failed. The journal owns all bytes.
            throw new \RuntimeException('The financial file import could not be completed safely. Inspect its retained transfer before retrying.');
        }
    }

    /** Inspection is read-only by default. Only this reservation's importing actor may retire failed bytes. */
    public function reconcile($actor, string $boundary, string $id, bool $cleanup = false): array
    {
        $this->outside();

        return DB::transaction(function () use ($actor, $boundary, $id, $cleanup): array {
            $row = $this->locked($actor, Metadata::uuid($id), false);
            abort_unless(hash_equals($row->boundary, Metadata::digest($boundary)), 409, 'Finance file runtime changed.');
            $operation = Operation::lockForUpdate()->findOrFail($row->operation_id);
            if ($row->state === 'committed') {
                $this->verifyCommitted($actor, $row);

                return ['transfer_id' => $row->id, 'state' => 'committed', 'operation_state' => $operation->state, 'can_cleanup' => false];
            }
            $referenced = Attachment::where('private_path', $row->private_path)->where('state', 'committed')->exists()
                || DB::table('crm_documents')->where('private_path', $row->private_path)->exists();
            abort_unless(! $referenced && $operation->state !== 'verified', 409, 'Finance file needs reconciliation.');
            $bytes = $this->storage->inspect($row);
            $owner = $operation->actor_id === Access::actor($actor, true)->id;
            if (! $cleanup || $row->state === 'cleaned') {
                return ['transfer_id' => $row->id, 'state' => $row->state, 'operation_state' => $operation->state,
                    'reservation_expired' => $operation->reservation_expires_at->lessThanOrEqualTo(now()), ...$bytes, 'can_cleanup' => $owner && $row->state === 'reserved'];
            }
            abort_unless($owner && $row->state === 'reserved', 403);
            if ($operation->state === 'claimed') {
                $receipt = $this->ledger->finish($actor, $boundary, $operation->id, $operation->request_key, ['state' => 'failed', 'error_code' => 'operation_failed']);
                abort_unless($receipt['state'] !== 'verified', 409, 'A committed Finance file cannot be cleaned.');
            }
            $this->locked($actor, $row->id, false);
            $this->storage->cleanup($row);
            $row->update(['state' => 'cleaned', 'reconciled_by' => Access::actor($actor, true)->id, 'reconciled_at' => now()]);
            $this->locked($actor, $row->id, false);

            return ['transfer_id' => $row->id, 'state' => 'cleaned', 'operation_state' => $operation->fresh()->state, 'can_cleanup' => false];
        });
    }

    /** Shared current Finance scope and every associated Core identity, plus immutable actual source proof. */
    public function readable($actor, Attachment $row): Attachment
    {
        $row = Attachment::findOrFail($row->id);
        $operation = Operation::findOrFail($row->operation_id);
        $context = $this->context($actor, $row->boundary, $operation->batch_id, $row->source(), false, false);
        $mapping = Mapping::findOrFail($operation->mapping_id);
        abort_unless($row->record_id === $context['record']->id && $row->entry_id === $context['entry']->id
            && $row->record_mapping_id === $context['record_mapping']->id && $row->entry_mapping_id === $context['entry_mapping']->id
            && $mapping->only(array_keys($this->sourceIdentity($row->source()))) === $this->sourceIdentity($row->source())
            && $mapping->provider === $context['batch']->provider && $mapping->source_account === $context['batch']->source_account
            && hash_equals($operation->source_fingerprint, $this->sourceFingerprint($context, $row->source(), $this->info($row))), 409, 'Finance file parent changed.');
        $this->verifyReceipt($row);

        return $row;
    }

    private function locked($actor, string $id, bool $write): Attachment
    {
        $snapshot = Attachment::findOrFail($id);
        $operation = Operation::findOrFail($snapshot->operation_id);
        $context = $this->context($actor, $snapshot->boundary, $operation->batch_id, $snapshot->source(), true, true);
        $operation = Operation::lockForUpdate()->findOrFail($snapshot->operation_id);
        $row = Attachment::lockForUpdate()->findOrFail($id);
        abort_unless($row->getAttributes() === $snapshot->getAttributes() && $row->record_id === $context['record']->id && $row->entry_id === $context['entry']->id
            && $row->record_mapping_id === $context['record_mapping']->id && $row->entry_mapping_id === $context['entry_mapping']->id
            && hash_equals($operation->source_fingerprint, $this->sourceFingerprint($context, $row->source(), $this->info($row)))
            && hash_equals($row->storage_boundary, $this->storage->boundary()), 409, 'Finance transfer evidence changed.');
        $mapping = Mapping::findOrFail($operation->mapping_id);
        abort_unless($mapping->only(array_keys($this->sourceIdentity($row->source()))) === $this->sourceIdentity($row->source())
            && $mapping->boundary === $row->boundary && $mapping->provider === $context['batch']->provider && $mapping->source_account === $context['batch']->source_account, 409, 'Finance file occurrence changed.');
        if ($write) {
            abort_unless($row->state === 'reserved' && $operation->state === 'claimed' && $operation->actor_id === Access::actor($actor, true)->id
                && $operation->reservation_expires_at->greaterThan(now()), 409, 'Finance file needs reconciliation.');
        }

        return $row;
    }

    private function context($actor, string $boundary, string $batchId, Source $source, bool $write, bool $lock): array
    {
        $before = $this->proofContext($actor, $boundary, $batchId, $source, $write, $lock);
        if ($write) {
            $this->sources->authorize($before['actor'], $before['batch'], $before['record_mapping'], $before['entry_mapping'], $source);
        }
        $after = $this->proofContext($actor, $boundary, $batchId, $source, $write, $lock);
        abort_unless(hash_equals($before['proof'], $after['proof']), 409, 'Finance file context changed.');

        return $after;
    }

    private function proofContext($actor, string $boundary, string $batchId, Source $source, bool $write, bool $lock): array
    {
        $user = Access::actor($actor, $write);
        abort_unless(hash_equals(Runtime::current()['boundary'], Metadata::digest($boundary)), 409, 'Finance file runtime changed.');
        $batch = Batch::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(Metadata::uuid($batchId));
        abort_unless($batch->boundary === $boundary && (! $write || $batch->state === 'open'), 409, 'Finance import batch is unavailable.');
        $recordMapping = Mapping::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($source->data['item_mapping_id']);
        $entryMapping = Mapping::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($source->data['entry_mapping_id']);
        foreach ([$recordMapping, $entryMapping] as $mapping) {
            abort_unless($mapping->boundary === $boundary && $mapping->provider === $batch->provider && $mapping->source_account === $batch->source_account
                && $mapping->board_id === $source->data['board_id'] && $mapping->state === 'verified' && $this->numeric($mapping->target_id), 409, 'Verified Financial source mappings are required.');
        }
        abort_unless($recordMapping->entity_type === 'item' && $recordMapping->entity_id === $source->data['item_id']
            && $recordMapping->target_role === 'finance-history-record' && $recordMapping->target_type === 'finance.history-record'
            && $entryMapping->target_role === 'finance-history-entry' && $entryMapping->target_type === 'finance.history-entry', 409, 'Finance file source parent changed.');
        $record = Access::view($user, (int) $recordMapping->target_id, $lock);
        $root = Operation::findOrFail($record->operation_id);
        $this->proofReceipt($root, $recordMapping, 'finance.history-record', (string) $record->id, Fingerprints::record($record));
        $entry = Entry::query()->when($lock, fn ($q) => $q->lockForUpdate())->where('record_id', $record->id)->findOrFail((int) $entryMapping->target_id);
        $kind = in_array($source->data['kind'], ['file-column', 'item-assets-all'], true) ? 'facts' : $source->data['kind'];
        $entityId = match ($kind) {
            'facts' => $source->data['item_id'], 'update' => $source->data['update_id'], default => $source->data['reply_id']
        };
        abort_unless($entry->source_mapping_id === $entryMapping->id && $entry->entry_kind === $kind
            && $entryMapping->entity_type === ($kind === 'facts' ? 'item' : $kind) && $entryMapping->entity_id === $entityId, 409, 'Finance file entry association changed.');
        app(Records::class)->entry($user, $record->id, $entry->id);
        $this->proofReceipt(Operation::findOrFail($entry->operation_id), $entryMapping, 'finance.history-entry', (string) $entry->id, Fingerprints::entry($entry));
        $replyProof = null;
        if ($kind === 'reply') {
            $parent = Entry::where('record_id', $record->id)->where('entry_kind', 'update')->findOrFail($entry->parent_entry_id);
            $parentMapping = Mapping::findOrFail($parent->source_mapping_id);
            abort_unless($parentMapping->boundary === $boundary && $parentMapping->provider === $batch->provider && $parentMapping->source_account === $batch->source_account
                && $parentMapping->board_id === $source->data['board_id'] && $parentMapping->entity_type === 'update'
                && $parentMapping->entity_id === $source->data['update_id'] && $parentMapping->target_role === 'finance-history-entry'
                && $parentMapping->state === 'verified' && $parentMapping->target_type === 'finance.history-entry' && $parentMapping->target_id === (string) $parent->id, 409, 'Finance file reply parent changed.');
            app(Records::class)->entry($user, $record->id, $parent->id);
            $this->proofReceipt(Operation::findOrFail($parent->operation_id), $parentMapping, 'finance.history-entry', (string) $parent->id, Fingerprints::entry($parent));
            $replyProof = Fingerprints::entry($parent);
        }
        $proof = Fingerprints::digest('file-context', [$recordMapping->only(self::MAP), $entryMapping->only(self::MAP), Fingerprints::record($record), Fingerprints::entry($entry), $replyProof]);

        return compact('user', 'batch', 'record', 'entry', 'proof') + ['actor' => $user, 'record_mapping' => $recordMapping, 'entry_mapping' => $entryMapping];
    }

    private function proofReceipt(Operation $operation, Mapping $mapping, string $type, string $id, string $fingerprint): void
    {
        abort_unless($operation->mapping_id === $mapping->id && $operation->state === 'verified' && $operation->result_target_type === $type
            && $operation->result_target_id === $id && hash_equals($operation->result_fingerprint, $fingerprint), 409, 'Retained Financial evidence changed.');
    }

    private function retained($actor, Mapping $mapping): Attachment
    {
        abort_unless($mapping->target_type === 'finance.history-attachment' && is_string($mapping->target_id), 409, 'Finance file target needs reconciliation.');
        $row = Attachment::findOrFail(Metadata::uuid($mapping->target_id));
        $this->verifyCommitted($actor, $row);
        abort_unless(Operation::findOrFail($row->operation_id)->mapping_id === $mapping->id, 409, 'Finance file target changed.');

        return $row;
    }

    private function verifyCommitted($actor, Attachment $row): void
    {
        $row = $this->readable($actor, $row);
        $bytes = $this->storage->inspect($row);
        abort_unless($bytes['exists'] && $bytes['bytes_match'], 409, 'Finance file bytes need reconciliation.');
    }

    private function verifyReceipt(Attachment $row): void
    {
        $operation = Operation::findOrFail($row->operation_id);
        $mapping = Mapping::findOrFail($operation->mapping_id);
        abort_unless($row->state === 'committed' && $row->committed_at !== null && $operation->state === 'verified'
            && $mapping->state === 'verified' && $mapping->boundary === $row->boundary && $mapping->target_type === 'finance.history-attachment'
            && $mapping->target_id === $row->id && $operation->result_target_type === 'finance.history-attachment'
            && $operation->result_target_id === $row->id && hash_equals($operation->result_fingerprint, $this->fingerprint($row)), 409, 'Finance file evidence needs reconciliation.');
    }

    private function sourceIdentity(Source $source): array
    {
        return ['board_id' => $source->data['board_id'], 'entity_type' => 'asset', 'entity_id' => $source->data['asset_id'], 'target_role' => 'finance-history-attachment',
            'relation_key' => Fingerprints::digest('file-occurrence', [array_intersect_key($source->data, array_flip(['item_mapping_id', 'item_id', 'entry_mapping_id', 'kind', 'column_id', 'source_index', 'update_id', 'reply_id']))])];
    }

    private function sourceFingerprint(array $context, Source $source, array $info): string
    {
        return Fingerprints::digest('file-source', [$context['proof'], $source->data, $info]);
    }

    private function fingerprint(Attachment $row): string
    {
        return Fingerprints::digest('file-target', [$row->getAttributes()]);
    }

    private function verifiedResult(Attachment $row): array
    {
        return ['state' => 'verified', 'target_type' => 'finance.history-attachment', 'target_id' => $row->id, 'result_fingerprint' => $this->fingerprint($row)];
    }

    private function info(Attachment $row): array
    {
        return ['name' => $row->original_name, 'extension' => strtolower(pathinfo($row->original_name, PATHINFO_EXTENSION)), 'mime' => $row->mime_type, 'size' => $row->size_bytes, 'sha256' => $row->sha256];
    }

    private function result(array $receipt, ?Attachment $row, bool $new): array
    {
        return ['receipt' => $receipt, 'transfer_id' => $row?->id, 'attachment_id' => $receipt['state'] === 'verified' ? $row?->id : null, 'new_transfer' => $new];
    }

    private function numeric(?string $id): bool
    {
        return is_string($id) && ctype_digit($id) && (string) (int) $id === $id && (int) $id > 0;
    }

    private function outside(): void
    {
        abort_unless(DB::transactionLevel() === 0, 409, 'Finance file imports require a durable reservation outside another transaction.');
    }
}
