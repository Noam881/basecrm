<?php

namespace Awove\Core\Imports;

use Awove\Core\Models\Activity;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Document;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Documents;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Ordinary documents only. The first committed transaction is the durable filesystem ownership journal. */
class DocumentImporter
{
    private const MAPPING_FIELDS = ['boundary', 'provider', 'source_account', 'board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key', 'target_type', 'target_id'];

    public function __construct(private Ledger $ledger, private Documents $documents, private DocumentSources $sources, private DocumentTransferStorage $storage) {}

    /** Future owning adapters must call this before fetching bytes, with no SQL transaction held. */
    public function authorizeSource($actor, string $boundary, string $batchId, int $identityMappingId, array $source): void
    {
        $this->outsideTransaction();
        $source = DocumentSource::from($source);
        DB::transaction(fn () => $this->context($actor, $boundary, $batchId, $identityMappingId, $source));
    }

    /** No target ID, path, checksum, fingerprint or successful outcome is accepted from a caller. */
    public function import($actor, string $boundary, string $batchId, int $identityMappingId, string $requestKey, array $source, UploadedFile $file): array
    {
        $this->outsideTransaction();
        $source = DocumentSource::from($source);
        $requestKey = Metadata::uuid($requestKey);
        $this->authorizeSource($actor, $boundary, $batchId, $identityMappingId, $source->data);
        $info = $this->documents->inspectFile($file);
        try {
            $reservation = DB::transaction(function () use ($actor, $boundary, $batchId, $identityMappingId, $requestKey, $source, $info): array {
                $context = $this->context($actor, $boundary, $batchId, $identityMappingId, $source);
                $user = $context['actor'];
                $sourceIdentity = $this->sourceIdentity($context, $source);
                $fingerprint = $this->sourceFingerprint($context, $source, $info);
                $prior = Operation::where('batch_id', $batchId)->where('request_key', $requestKey)->first();
                $mapping = Mapping::where(['boundary' => $boundary, 'provider' => $context['batch']->provider, 'source_account' => $context['batch']->source_account, ...$sourceIdentity])->first();
                $retained = $mapping?->target_id === null ? null : $this->retained($user, $mapping);
                if ($prior?->state === 'verified') {
                    abort_unless($retained && $prior->mapping_id === $mapping->id && hash_equals($prior->source_fingerprint, $fingerprint), 409, 'Document replay conflicts with retained evidence.');
                    $receipt = $this->ledger->finish($user, $boundary, $prior->id, $requestKey, $this->verifiedResult($retained));
                    $this->verifyCommitted($user, $retained);
                    $this->context($user, $boundary, $batchId, $identityMappingId, $source);

                    return $this->result($receipt, $retained, false);
                }
                $receipt = $this->ledger->claim($user, $boundary, $batchId, $sourceIdentity, ['request_key' => $requestKey, 'revision' => 1,
                    'source_fingerprint' => $fingerprint, 'target_fingerprint' => $retained ? $this->fingerprint($retained->document_id, $retained) : null,
                    'previous_receipt_id' => null]);
                if ($receipt['state'] !== 'claimed' || $prior !== null) {
                    $transfer = $retained ?? (isset($receipt['id']) ? DocumentTransfer::where('operation_id', $receipt['id'])->first() : null);
                    if ($receipt['state'] === 'verified' && $transfer !== null) {
                        $this->verifyCommitted($user, $transfer);
                    }
                    $this->context($user, $boundary, $batchId, $identityMappingId, $source);

                    return $this->result($receipt, $transfer, false);
                }
                $id = (string) Str::uuid();
                $identity = $context['identity'];
                $transfer = DocumentTransfer::create(['id' => $id, 'operation_id' => $receipt['id'], 'boundary' => $boundary,
                    'storage_boundary' => $this->storage->boundary(), 'identity_mapping_id' => $identityMappingId,
                    'source_item_mapping_id' => $source->data['source_item_mapping_id'], 'history_mapping_id' => $source->data['history_mapping_id'],
                    'contact_id' => $identity instanceof Contact ? $identity->id : null, 'company_id' => $identity instanceof Company ? $identity->id : null,
                    'private_path' => $this->storage->path($id, $info['extension']), 'original_name' => $info['name'], 'mime_type' => $info['mime'],
                    'size_bytes' => $info['size'], 'sha256' => $info['sha256'], 'source_metadata' => $source->data, 'state' => 'reserved']);
                $this->context($user, $boundary, $batchId, $identityMappingId, $source);

                return $this->result($receipt, $transfer, true);
            });
            if (! $reservation['new_transfer']) {
                return $reservation;
            }

            // No automatic transaction retries: filesystem writes cannot be replayed as SQL closures.
            return DB::transaction(function () use ($actor, $reservation, $file): array {
                $transfer = $this->locked($actor, $reservation['transfer_id'], true);
                $document = $this->documents->uploadReserved($actor, $transfer, $file);
                $transfer->update(['state' => 'committed', 'document_id' => $document->id]);
                $transfer->refresh();
                $operation = Operation::findOrFail($transfer->operation_id);
                $receipt = $this->ledger->finish($actor, $transfer->boundary, $operation->id, $operation->request_key, $this->verifiedResult($transfer));
                abort_unless($receipt['state'] === 'verified', 409, 'Document import requires reconciliation.');
                $document->refresh();
                $this->verifyReceipt($transfer, $document);
                $this->documents->verifyStored($document);
                $this->locked($actor, $transfer->id, false);
                abort_unless($operation->reservation_expires_at->greaterThan(now()), 409, 'Document import reservation expired.');

                return $this->result($receipt, $transfer, false);
            });
        } catch (AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            // The durable journal owns this path even when COMMIT status is unknown. Never delete here.
            throw new \RuntimeException('The document import could not be completed safely. Inspect its retained transfer before retrying.');
        }
    }

    /** @internal Documents' reserved hook reloads its journal and current authority, never cached row attributes. */
    public function writable($actor, DocumentTransfer $transfer): DocumentTransfer
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'A document import transaction is required.');

        return $this->locked($actor, $transfer->id, true);
    }

    /** Inspection is the default; explicit cleanup only retires this actor's own unreferenced attempt. */
    public function reconcile($actor, string $boundary, string $transferId, bool $cleanup = false): array
    {
        $this->outsideTransaction();

        return DB::transaction(function () use ($actor, $boundary, $transferId, $cleanup): array {
            $transfer = $this->locked($actor, Metadata::uuid($transferId), false);
            abort_unless(hash_equals($transfer->boundary, Metadata::digest($boundary)), 409, 'Import runtime boundary changed.');
            $operation = Operation::lockForUpdate()->findOrFail($transfer->operation_id);
            if ($transfer->state === 'committed') {
                $this->verifyCommitted($actor, $transfer);

                return ['transfer_id' => $transfer->id, 'state' => 'committed', 'operation_state' => $operation->state, 'document_id' => $transfer->document_id, 'can_cleanup' => false];
            }
            $referenced = Document::where('private_path', $transfer->private_path)->exists();
            abort_unless(! $referenced && $operation->state !== 'verified', 409, 'Document transfer requires reconciliation.');
            $bytes = $this->storage->inspect($transfer);
            $owner = $operation->actor_id === $this->actor($actor)->id;
            if (! $cleanup || $transfer->state === 'cleaned') {
                return ['transfer_id' => $transfer->id, 'state' => $transfer->state, 'operation_state' => $operation->state,
                    'reservation_expired' => $operation->reservation_expires_at->lessThanOrEqualTo(now()), ...$bytes,
                    'can_cleanup' => $owner && $transfer->state === 'reserved'];
            }
            abort_unless($owner && $transfer->state === 'reserved', 403);
            if ($operation->state === 'claimed') {
                $receipt = $this->ledger->finish($actor, $boundary, $operation->id, $operation->request_key, ['state' => 'failed', 'error_code' => 'operation_failed']);
                abort_unless($receipt['state'] !== 'verified', 409, 'A committed document cannot be cleaned.');
            }
            $this->locked($actor, $transfer->id, false);
            $this->storage->cleanup($transfer);
            $transfer->update(['state' => 'cleaned', 'reconciled_by' => $this->actor($actor)->id, 'reconciled_at' => now()]);
            $this->locked($actor, $transfer->id, false);

            return ['transfer_id' => $transfer->id, 'state' => 'cleaned', 'operation_state' => $operation->fresh()->state, 'can_cleanup' => false];
        });
    }

    /** Existing profile Gate remains authoritative; hidden journal metadata is never returned wholesale. */
    public function sourceMetadata($actor, Document $document): ?array
    {
        $transfer = $document->relationLoaded('importTransfer') ? $document->getRelation('importTransfer') : $document->importTransfer()->first();
        if (! $transfer) {
            return null;
        }
        $user = $this->actor($actor, false);
        $identity = $document->contact_id !== null ? Contact::findOrFail($document->contact_id) : Company::findOrFail($document->company_id);
        Gate::forUser($user)->authorize('view', $identity);
        $this->verifyReceipt($transfer, $document);
        $source = $transfer->sourceContext()->data;
        $context = null;
        if ($source['history_mapping_id'] !== null) {
            $mapping = Mapping::find($source['history_mapping_id']);
            $validMapping = $mapping && $mapping->state === 'verified' && $mapping->target_type === 'core.activity'
                && $mapping->target_role === 'legacy-history' && $mapping->boundary === $transfer->boundary
                && $mapping->entity_type === $source['kind'] && $mapping->entity_id === ($source['kind'] === 'reply' ? $source['reply_id'] : $source['update_id'])
                && is_string($mapping->target_id) && ctype_digit($mapping->target_id);
            $activity = $validMapping ? Activity::find($mapping->target_id) : null;
            $lead = $activity ? Lead::find($activity->lead_id) : null;
            if ($lead && Gate::forUser($user)->allows('view', $lead)
                && $activity->event === Activity::LEGACY_EVENT && ($activity->details['source_mapping_id'] ?? null) === $mapping->id
                && ($activity->details['parent_mapping_id'] ?? null) === $transfer->source_item_mapping_id
                && ($identity instanceof Contact ? $lead->contact_id === $identity->id : $lead->company_id === $identity->id)) {
                try {
                    app(LegacyHistory::class)->entry($user, $lead, $activity->id);
                    $context = ['kind' => $source['kind'], 'lead_id' => $lead->id, 'activity_id' => $activity->id, 'url' => '/leads/'.$lead->id];
                } catch (HttpExceptionInterface $error) {
                    // An inaccessible or inconsistent history context does not grant a link through a client document.
                    if (! in_array($error->getStatusCode(), [403, 404, 503], true)) {
                        throw $error;
                    }
                }
            }
        }
        Gate::forUser($this->actor($actor, false))->authorize('view', $identity->fresh());

        return ['author' => ['name' => $source['author']['name']], 'source_created_at' => $source['source_created_at'], 'source_updated_at' => $source['source_updated_at'],
            'kind' => $source['kind'], 'context' => $context];
    }

    private function locked($actor, string $id, bool $write): DocumentTransfer
    {
        $snapshot = DocumentTransfer::findOrFail(Metadata::uuid($id));
        $initial = Operation::findOrFail($snapshot->operation_id);
        $source = $snapshot->sourceContext();
        $context = $this->context($actor, $snapshot->boundary, $initial->batch_id, $snapshot->identity_mapping_id, $source);
        $mapping = Mapping::lockForUpdate()->findOrFail($initial->mapping_id);
        $operation = Operation::lockForUpdate()->findOrFail($initial->id);
        $transfer = DocumentTransfer::lockForUpdate()->findOrFail($snapshot->id);
        abort_unless($transfer->getAttributes() === $snapshot->getAttributes() && $transfer->operation_id === $operation->id
            && $transfer->source_item_mapping_id === $source->data['source_item_mapping_id'] && $transfer->history_mapping_id === $source->data['history_mapping_id']
            && $operation->actor_id === $context['actor']->id && $mapping->only(array_keys($this->sourceIdentity($context, $source))) === $this->sourceIdentity($context, $source)
            && $mapping->boundary === $transfer->boundary && $mapping->provider === $context['batch']->provider && $mapping->source_account === $context['batch']->source_account,
            409, 'Document transfer context changed.');
        $info = $this->transferInfo($transfer);
        abort_unless(hash_equals($operation->source_fingerprint, $this->sourceFingerprint($context, $source, $info))
            && ($context['identity'] instanceof Contact ? $transfer->contact_id === $context['identity']->id && $transfer->company_id === null
                : $transfer->company_id === $context['identity']->id && $transfer->contact_id === null)
            && hash_equals($transfer->storage_boundary, $this->storage->boundary()), 409, 'Document transfer context changed.');
        if ($write) {
            abort_unless($transfer->state === 'reserved' && $operation->state === 'claimed' && $operation->reservation_expires_at->greaterThan(now())
                && ! Document::where('private_path', $transfer->private_path)->exists(), 409, 'Document transfer requires reconciliation.');
        }

        return $transfer;
    }

    private function context($actor, string $boundary, string $batchId, int $identityMappingId, DocumentSource $source): array
    {
        $user = $this->actor($actor);
        $this->boundary($boundary);
        $batch = Batch::lockForUpdate()->findOrFail(Metadata::uuid($batchId));
        abort_unless($batch->boundary === $boundary && $batch->state === 'open', 409, 'Import batch is unavailable.');
        $ids = array_values(array_unique(array_filter([$identityMappingId, $source->data['source_item_mapping_id'], $source->data['history_mapping_id']])));
        sort($ids);
        $maps = Mapping::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        abort_unless($identityMappingId > 0 && $maps->count() === count($ids), 409, 'Verified document source mappings are required.');
        foreach ($maps as $map) {
            $this->mapping($map, $batch);
        }
        $identityMapping = $maps[$identityMappingId];
        $identity = match ($identityMapping->target_type) {
            'core.contact' => Contact::lockForUpdate()->findOrFail($this->targetId($identityMapping)),
            'core.company' => Company::lockForUpdate()->findOrFail($this->targetId($identityMapping)),
            default => abort(409, 'A verified source identity mapping is required.'),
        };
        Gate::forUser($user)->authorize('update', $identity);
        $item = $maps[$source->data['source_item_mapping_id']];
        abort_unless($item->entity_type === 'item' && $item->entity_id === $source->data['source_parent_id'], 409, 'Document source parent changed.');
        $lead = null;
        if ($item->target_type === 'core.lead') {
            $lead = Lead::lockForUpdate()->findOrFail($this->targetId($item));
            Gate::forUser($user)->authorize('update', $lead);
            abort_unless($identity instanceof Contact ? $lead->contact_id === $identity->id : $lead->company_id === $identity->id, 409, 'Document identity association changed.');
        } else {
            abort_unless($item->target_type === $identityMapping->target_type && $item->target_id === $identityMapping->target_id, 409, 'Document identity association changed.');
        }
        $historyFingerprint = null;
        if ($source->data['history_mapping_id'] !== null) {
            $history = $maps[$source->data['history_mapping_id']];
            abort_unless($lead && $history->board_id === $item->board_id && $history->target_role === 'legacy-history' && $history->target_type === 'core.activity'
                && $history->entity_type === $source->data['kind'] && $history->entity_id === ($source->data['kind'] === 'reply' ? $source->data['reply_id'] : $source->data['update_id']), 409, 'Document history association changed.');
            $activity = Activity::where('lead_id', $lead->id)->where('event', Activity::LEGACY_EVENT)->lockForUpdate()->findOrFail($this->targetId($history));
            abort_unless(($activity->details['parent_mapping_id'] ?? null) === $item->id && ($activity->details['source_mapping_id'] ?? null) === $history->id
                && ($activity->details['kind'] ?? null) === $source->data['kind'], 409, 'Document history association changed.');
            app(LegacyHistory::class)->entry($user, $lead, $activity->id);
            if ($source->data['kind'] === 'reply') {
                $update = Activity::where('lead_id', $lead->id)->where('event', Activity::LEGACY_EVENT)->findOrFail($activity->details['reply_to_activity_id'] ?? 0);
                $updateMapping = Mapping::findOrFail($update->details['source_mapping_id'] ?? 0);
                $this->mapping($updateMapping, $batch);
                abort_unless($updateMapping->entity_type === 'update' && $updateMapping->entity_id === $source->data['update_id']
                    && ($update->details['parent_mapping_id'] ?? null) === $item->id, 409, 'Document reply parent changed.');
                app(LegacyHistory::class)->entry($user, $lead, $update->id);
            }
            $historyFingerprint = Operation::findOrFail($activity->details['operation_id'])->result_fingerprint;
        }
        $this->sources->authorize($user, $batch, $identityMapping, $item, $source);
        $user = $this->actor($user);
        $identity = $identity->fresh();
        abort_unless($identity !== null && $batch->fresh()?->state === 'open', 409, 'Import context changed.');
        Gate::forUser($user)->authorize('update', $identity);
        if ($lead !== null) {
            $lead = $lead->fresh();
            abort_unless($lead !== null, 409, 'Document source parent changed.');
            Gate::forUser($user)->authorize('update', $lead);
            abort_unless($identity instanceof Contact ? $lead->contact_id === $identity->id : $lead->company_id === $identity->id, 409, 'Document identity association changed.');
        }
        foreach ($maps as $map) {
            $fresh = $map->fresh();
            abort_unless($fresh && $fresh->only(self::MAPPING_FIELDS) === $map->only(self::MAPPING_FIELDS), 409, 'Document source mappings changed.');
            $this->mapping($fresh, $batch);
        }
        if (isset($activity)) {
            app(LegacyHistory::class)->entry($user, $lead, $activity->id);
        }
        $this->boundary($boundary);

        return ['actor' => $user, 'batch' => $batch, 'identity_mapping' => $identityMapping, 'source_item' => $item, 'identity' => $identity,
            'history_fingerprint' => $historyFingerprint];
    }

    private function mapping(Mapping $mapping, Batch $batch): void
    {
        abort_unless($mapping->boundary === $batch->boundary && $mapping->provider === $batch->provider && $mapping->source_account === $batch->source_account
            && $mapping->state === 'verified' && $mapping->target_id !== null && Operation::where('mapping_id', $mapping->id)->where('state', 'verified')
                ->where('result_target_type', $mapping->target_type)->where('result_target_id', $mapping->target_id)->exists(), 409, 'Verified document source mappings are required.');
    }

    private function retained($actor, Mapping $mapping): DocumentTransfer
    {
        $transfers = DocumentTransfer::whereIn('operation_id', Operation::where('mapping_id', $mapping->id)->select('id'))->where('state', 'committed')->limit(2)->get();
        abort_unless($mapping->target_type === 'core.document' && $transfers->count() === 1 && (string) $transfers->first()->document_id === $mapping->target_id, 409, 'Document target requires reconciliation.');
        $transfer = $transfers->first();
        $this->verifyCommitted($actor, $transfer);

        return $transfer;
    }

    private function verifyCommitted($actor, DocumentTransfer $transfer): void
    {
        $document = Document::findOrFail($transfer->document_id);
        $this->verifyReceipt($transfer, $document);
        abort_unless(hash_equals($transfer->storage_boundary, $this->storage->boundary()), 409, 'Document storage boundary changed.');
        $bytes = $this->storage->inspect($transfer);
        abort_unless($bytes['exists'] && $bytes['bytes_match'], 409, 'Retained document bytes require reconciliation.');
        $identity = $document->contact_id !== null ? Contact::findOrFail($document->contact_id) : Company::findOrFail($document->company_id);
        Gate::forUser($this->actor($actor))->authorize('update', $identity);
        $this->documents->verifyStored($document);
    }

    private function verifyReceipt(DocumentTransfer $transfer, Document $document): void
    {
        $operation = Operation::findOrFail($transfer->operation_id);
        $mapping = Mapping::findOrFail($operation->mapping_id);
        abort_unless($transfer->state === 'committed' && $transfer->document_id === $document->id && $document->private_path === $transfer->private_path
            && $document->contact_id === $transfer->contact_id && $document->company_id === $transfer->company_id && $document->original_name === $transfer->original_name
            && $document->mime_type === $transfer->mime_type && $document->size_bytes === $transfer->size_bytes && hash_equals($document->sha256, $transfer->sha256)
            && $document->uploaded_by === $operation->actor_id && $operation->state === 'verified' && $mapping->state === 'verified'
            && $mapping->target_type === 'core.document' && $mapping->target_id === (string) $document->id && $operation->result_target_type === 'core.document'
            && $operation->result_target_id === (string) $document->id && hash_equals($operation->result_fingerprint, $this->fingerprint($document->id, $transfer)),
            409, 'Document source evidence requires reconciliation.');
    }

    private function sourceIdentity(array $context, DocumentSource $source): array
    {
        $data = $source->data;

        return ['board_id' => $context['source_item']->board_id, 'entity_type' => $data['entity_type'], 'entity_id' => $data['source_id'], 'target_role' => 'identity-document',
            'relation_key' => $this->digest('occurrence', [$context['identity_mapping']->id, $context['source_item']->only(self::MAPPING_FIELDS),
                array_intersect_key($data, array_flip(['role', 'kind', 'column_id', 'source_index', 'update_id', 'reply_id', 'history_mapping_id']))])];
    }

    private function sourceFingerprint(array $context, DocumentSource $source, array $info): string
    {
        return $this->digest('source', [$context['identity_mapping']->only(self::MAPPING_FIELDS), $context['source_item']->only(self::MAPPING_FIELDS),
            $context['history_fingerprint'], $source->data, $info]);
    }

    private function transferInfo(DocumentTransfer $transfer): array
    {
        return ['name' => $transfer->original_name, 'extension' => strtolower(pathinfo($transfer->original_name, PATHINFO_EXTENSION)),
            'mime' => $transfer->mime_type, 'size' => $transfer->size_bytes, 'sha256' => $transfer->sha256];
    }

    private function fingerprint(int $documentId, DocumentTransfer $transfer): string
    {
        $document = Document::findOrFail($documentId);

        $attributes = $document->getAttributes();
        $attributes['created_at'] = CarbonImmutable::parse($document->created_at)->toISOString();

        return $this->digest('target', [$attributes, $transfer->only(['id', 'operation_id', 'boundary', 'storage_boundary', 'identity_mapping_id',
            'source_item_mapping_id', 'history_mapping_id', 'contact_id', 'company_id', 'private_path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'source_metadata'])]);
    }

    private function verifiedResult(DocumentTransfer $transfer): array
    {
        return ['state' => 'verified', 'target_type' => 'core.document', 'target_id' => (string) $transfer->document_id, 'result_fingerprint' => $this->fingerprint($transfer->document_id, $transfer)];
    }

    private function result(array $receipt, ?DocumentTransfer $transfer, bool $new): array
    {
        return ['receipt' => $receipt, 'transfer_id' => $transfer?->id, 'document_id' => $receipt['state'] === 'verified' ? $transfer?->document_id : null, 'new_transfer' => $new];
    }

    private function targetId(Mapping $mapping): int
    {
        abort_unless(is_string($mapping->target_id) && ctype_digit($mapping->target_id) && (string) (int) $mapping->target_id === $mapping->target_id && (int) $mapping->target_id > 0, 409, 'Document target mapping changed.');

        return (int) $mapping->target_id;
    }

    private function actor($actor, bool $write = true)
    {
        $user = $actor?->fresh();
        abort_unless($user && $user->active && $user->hasVerifiedEmail() && (! $write || $user->can('imports.manage')), 403);

        return $user;
    }

    private function boundary(string $boundary): void
    {
        abort_unless(hash_equals(Runtime::current()['boundary'], Metadata::digest($boundary)), 409, 'Import runtime boundary changed.');
    }

    private function outsideTransaction(): void
    {
        abort_unless(DB::transactionLevel() === 0, 409, 'Document import reservation requires a committed transaction boundary.');
    }

    private function digest(string $purpose, array $data): string
    {
        return hash_hmac('sha256', 'core-import-document:v1:'.$purpose."\0".json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), app('encrypter')->getKey());
    }
}
