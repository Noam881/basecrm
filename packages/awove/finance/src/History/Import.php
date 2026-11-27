<?php

namespace Awove\Finance\History;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Metadata;
use Awove\Core\Imports\Operation;
use Awove\Core\Imports\Runtime;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Finance\Access as FinanceAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Source references only. No native charges/payments, customer automations, files or source I/O. */
class Import
{
    public function __construct(private Ledger $ledger) {}

    public function create($actor, string $boundary, string $batchId, array $input, array $payload): array
    {
        Access::actor($actor, true);
        Metadata::exact($input, ['board_id', 'source_id', 'kind', 'owner_id', 'placement', 'associations', 'request_key', 'entry_request_key']);
        Metadata::opaque($input['board_id']);
        Metadata::opaque($input['source_id']);
        $input['request_key'] = Metadata::uuid($input['request_key']);
        $input['entry_request_key'] = Metadata::uuid($input['entry_request_key']);
        abort_unless($input['request_key'] !== $input['entry_request_key'] && in_array($input['kind'], Records::KINDS, true) && in_array($input['placement'], Records::PLACEMENTS, true)
            && ($input['owner_id'] === null || is_int($input['owner_id']) && $input['owner_id'] > 0)
            && is_array($input['associations']) && array_is_list($input['associations']) && count($input['associations']) <= 100
            && ($input['placement'] === 'linked' ? $input['associations'] !== [] : $input['associations'] === []), 422, 'Invalid historical finance placement.');
        $payload = Payload::validate('facts', $payload);

        return $this->guarded($actor, $boundary, $batchId, function ($user, Batch $batch) use ($boundary, $input, $payload): array {
            $source = ['board_id' => $input['board_id'], 'entity_type' => 'item', 'entity_id' => $input['source_id'], 'target_role' => 'finance-history-record', 'relation_key' => 'primary'];
            $family = Fingerprints::digest('record-family', [$batch->provider, $batch->source_account, $source]);
            $this->lock($family);
            $this->owner($user, $input['owner_id'], $input['placement']);
            $links = $this->associations($user, $batch, $input['associations']);
            $mapping = $this->mapping($batch, $source);
            $existing = $mapping?->target_id === null ? null : $this->record($user, $batch, $mapping);
            $sourceHash = Fingerprints::digest('record-source', [$family, $input['kind'], $input['owner_id'], $input['placement'], $links]);
            $claim = ['request_key' => $input['request_key'], 'revision' => 1, 'source_fingerprint' => $sourceHash,
                'target_fingerprint' => $existing ? Fingerprints::record($existing) : null, 'previous_receipt_id' => null];
            $reserved = Operation::where('batch_id', $batch->id)->where('request_key', $input['request_key'])->first();
            $receipt = $reserved?->state === 'verified'
                ? $this->replay($user, $boundary, $reserved, $sourceHash, $mapping, $existing?->id, $existing ? Fingerprints::record($existing) : null, 'finance.history-record')
                : $this->ledger->claim($user, $boundary, $batch->id, $source, $claim);
            $entryInput = ['entry_kind' => 'facts', 'source_id' => $input['source_id'], 'source_parent_id' => $input['source_id'], 'revision' => 1,
                'request_key' => $input['entry_request_key'], 'previous_receipt_id' => null, 'reply_to_mapping_id' => null];
            if ($receipt['state'] === 'verified') {
                abort_unless($existing !== null, 409, 'Historical finance target needs reconciliation.');
                $entry = $this->appendValidated($user, $boundary, $batch, $mapping->id, $entryInput, $payload);

                return ['record_id' => $existing->id, 'record_receipt' => $receipt, ...$entry];
            }
            if ($receipt['state'] !== 'claimed' || $reserved !== null) {
                return ['record_id' => null, 'record_receipt' => $receipt, 'entry_id' => null, 'entry_receipt' => null];
            }
            try {
                return DB::transaction(function () use ($user, $boundary, $batch, $input, $payload, $links, $receipt, $entryInput): array {
                    $this->guard($user, $boundary, $batch->id);
                    $record = Record::create(['kind' => $input['kind'], 'placement' => $input['placement'], 'owner_id' => $input['owner_id'],
                        'operation_id' => $receipt['id'], 'created_by' => $user->id, 'created_at' => now()]);
                    foreach ($links as $link) {
                        Identity::create(['record_id' => $record->id, ...$link]);
                    }
                    $record->refresh();
                    Access::view($user, $record->id);
                    $this->guard($user, $boundary, $batch->id);
                    $finished = $this->ledger->finish($user, $boundary, $receipt['id'], $input['request_key'],
                        ['state' => 'verified', 'target_type' => 'finance.history-record', 'target_id' => (string) $record->id, 'result_fingerprint' => Fingerprints::record($record)]);
                    if ($finished['state'] !== 'verified') {
                        throw new AppendRefused;
                    }
                    $entry = $this->appendValidated($user, $boundary, $batch, $receipt['mapping_id'], $entryInput, $payload);
                    if ($entry['entry_receipt']['state'] !== 'verified') {
                        throw new AppendRefused;
                    }
                    $this->guard($user, $boundary, $batch->id, $receipt['mapping_id']);

                    return ['record_id' => $record->id, 'record_receipt' => $finished, ...$entry];
                });
            } catch (AppendRefused) {
                return ['record_id' => null, 'record_receipt' => $this->ledger->claim($user, $boundary, $batch->id, $source, $claim), 'entry_id' => null, 'entry_receipt' => null];
            }
        });
    }

    public function append($actor, string $boundary, string $batchId, int $recordMappingId, array $input, array $payload): array
    {
        Access::actor($actor, true);
        $this->input($input);
        $payload = Payload::validate($input['entry_kind'], $payload);

        return $this->guarded($actor, $boundary, $batchId,
            fn ($user, Batch $batch) => $this->appendValidated($user, $boundary, $batch, $recordMappingId, $input, $payload));
    }

    private function appendValidated($actor, string $boundary, Batch $batch, int $recordMappingId, array $input, array $payload): array
    {
        $parent = Mapping::findOrFail($recordMappingId);
        $this->parent($parent, $batch);
        abort_unless($input['source_parent_id'] === $parent->entity_id && ($input['entry_kind'] !== 'facts' || $input['source_id'] === $parent->entity_id), 409, 'Financial source parent changed.');
        $family = Fingerprints::digest('entry-family', [$parent->only(['boundary', 'provider', 'source_account', 'board_id', 'entity_id', 'target_role', 'relation_key']), $input['entry_kind'], $input['source_id']]);
        $this->lock($family);
        $parent = Mapping::lockForUpdate()->findOrFail($recordMappingId);
        $this->parent($parent, $batch);
        abort_unless(hash_equals($family, Fingerprints::digest('entry-family', [$parent->only(['boundary', 'provider', 'source_account', 'board_id', 'entity_id', 'target_role', 'relation_key']), $input['entry_kind'], $input['source_id']])), 409, 'Financial source parent changed.');
        $record = $this->record($actor, $batch, $parent, true);
        abort_unless($input['source_parent_id'] === $parent->entity_id, 409, 'Financial source parent changed.');
        $reply = $this->reply($actor, $batch, $record, $input);
        $query = $record->entries()->where('family_key', $family);
        $same = (clone $query)->where('source_revision', $input['revision'])->limit(2)->get();
        abort_unless($same->count() <= 1, 409, 'Financial source history needs reconciliation.');
        $existing = $same->first();
        $previous = $existing ? ($existing->previous_entry_id ? (clone $query)->find($existing->previous_entry_id) : null) : (clone $query)->orderByDesc('id')->first();
        $this->predecessor($batch, $record, $input, $previous, $existing);
        $retained = $existing ?? $previous;
        abort_unless($input['entry_kind'] !== 'reply' || $retained === null || $retained->parent_entry_id === $reply?->id, 409, 'Financial reply parent is immutable.');
        $source = ['board_id' => $parent->board_id, 'entity_type' => $input['entry_kind'] === 'facts' ? 'item' : $input['entry_kind'],
            'entity_id' => $input['source_id'], 'target_role' => 'finance-history-entry', 'relation_key' => Fingerprints::digest('entry-occurrence', [$family, $input['revision']])];
        $mapping = $this->mapping($batch, $source);
        if ($mapping?->target_id !== null) {
            abort_unless($existing !== null && $mapping->target_type === 'finance.history-entry' && $mapping->target_id === (string) $existing->id && $existing->source_mapping_id === $mapping->id, 409, 'Financial entry target needs reconciliation.');
        } else {
            abort_unless($existing === null, 409, 'Financial entry target needs reconciliation.');
        }
        $sourceHash = Fingerprints::digest('entry-source', [$family, $input['revision'], $previous?->id, $reply?->id, $payload]);
        $claim = ['request_key' => $input['request_key'], 'revision' => 1, 'source_fingerprint' => $sourceHash,
            'target_fingerprint' => $existing ? Fingerprints::entry($existing) : null, 'previous_receipt_id' => null];
        $reserved = Operation::where('batch_id', $batch->id)->where('request_key', $input['request_key'])->first();
        $receipt = $reserved?->state === 'verified'
            ? $this->replay($actor, $boundary, $reserved, $sourceHash, $mapping, $existing?->id, $existing ? Fingerprints::entry($existing) : null, 'finance.history-entry')
            : $this->ledger->claim($actor, $boundary, $batch->id, $source, $claim);
        if ($receipt['state'] !== 'claimed' || $reserved !== null) {
            $this->guard($actor, $boundary, $batch->id, $recordMappingId);

            return ['entry_receipt' => $receipt, 'entry_id' => $receipt['state'] === 'verified' ? $existing?->id : null];
        }
        try {
            $result = DB::transaction(function () use ($actor, $boundary, $batch, $recordMappingId, $record, $input, $payload, $family, $previous, $reply, $receipt): array {
                $this->guard($actor, $boundary, $batch->id, $recordMappingId);
                $entry = Entry::create(['record_id' => $record->id, 'entry_kind' => $input['entry_kind'], 'family_key' => $family, 'source_revision' => $input['revision'],
                    'previous_entry_id' => $previous?->id, 'parent_entry_id' => $reply?->id, 'source_mapping_id' => $receipt['mapping_id'], 'operation_id' => $receipt['id'], 'payload' => $payload, 'created_at' => now()]);
                $entry->refresh();
                $this->guard($actor, $boundary, $batch->id, $recordMappingId);
                $finished = $this->ledger->finish($actor, $boundary, $receipt['id'], $input['request_key'],
                    ['state' => 'verified', 'target_type' => 'finance.history-entry', 'target_id' => (string) $entry->id, 'result_fingerprint' => Fingerprints::entry($entry)]);
                if ($finished['state'] !== 'verified') {
                    throw new AppendRefused;
                }

                return ['entry_receipt' => $finished, 'entry_id' => $entry->id];
            });
        } catch (AppendRefused) {
            $result = ['entry_receipt' => $this->ledger->claim($actor, $boundary, $batch->id, $source, $claim), 'entry_id' => null];
        }
        $this->guard($actor, $boundary, $batch->id, $recordMappingId);

        return $result;
    }

    private function associations($actor, Batch $batch, array $input): array
    {
        $ids = [];
        foreach ($input as $link) {
            abort_unless(is_array($link), 422, 'Invalid financial source association.');
            Metadata::exact($link, ['mapping_id', 'source_board_id', 'source_item_id']);
            abort_unless(is_int($link['mapping_id']) && $link['mapping_id'] > 0 && ! in_array($link['mapping_id'], $ids, true), 422, 'Invalid financial source association.');
            Metadata::opaque($link['source_board_id']);
            Metadata::opaque($link['source_item_id']);
            $ids[] = $link['mapping_id'];
        }
        $mappings = Mapping::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $links = [];
        foreach ($input as $link) {
            $mapping = $mappings->get($link['mapping_id']);
            abort_unless($mapping && $mapping->boundary === $batch->boundary && $mapping->provider === $batch->provider && $mapping->source_account === $batch->source_account
                && $mapping->board_id === $link['source_board_id'] && $mapping->entity_id === $link['source_item_id'] && $mapping->entity_type === 'item'
                && $mapping->state === 'verified' && in_array($mapping->target_type, ['core.contact', 'core.company'], true) && $this->numeric($mapping->target_id)
                && Operation::where('mapping_id', $mapping->id)->where('state', 'verified')->where('result_target_type', $mapping->target_type)->where('result_target_id', $mapping->target_id)->exists(), 409, 'A verified financial identity mapping is required.');
            $contact = $mapping->target_type === 'core.contact';
            $identity = ($contact ? Contact::class : Company::class)::whereKey((int) $mapping->target_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('view', $identity);
            $links[] = ['source_mapping_id' => $mapping->id, 'contact_id' => $contact ? $identity->id : null, 'company_id' => $contact ? null : $identity->id];
        }
        usort($links, fn ($a, $b) => $a['source_mapping_id'] <=> $b['source_mapping_id']);

        return $links;
    }

    private function owner($actor, ?int $id, string $placement): void
    {
        abort_unless(($id === $actor->id || $actor->can('finance.history.view.all')) && ($placement !== 'unresolved' || $actor->can('finance.history.view.all')), 403);
        if ($id !== null) {
            $owner = config('auth.providers.users.model')::findOrFail($id);
            abort_unless($owner->hasVerifiedEmail() && FinanceAccess::available($owner, 'history'), 403);
        }
    }

    private function record($actor, Batch $batch, Mapping $mapping, bool $lock = false): Record
    {
        $this->parent($mapping, $batch);
        $record = Access::view($actor, (int) $mapping->target_id, $lock);
        $receipt = Operation::findOrFail($record->operation_id);
        abort_unless($receipt->mapping_id === $mapping->id && $receipt->state === 'verified' && $receipt->result_target_type === 'finance.history-record'
            && $receipt->result_target_id === (string) $record->id && hash_equals($receipt->result_fingerprint, Fingerprints::record($record)), 409, 'Historical finance placement needs reconciliation.');

        return $record;
    }

    private function parent(Mapping $mapping, Batch $batch): void
    {
        abort_unless($mapping->boundary === $batch->boundary && $mapping->provider === $batch->provider && $mapping->source_account === $batch->source_account
            && $mapping->state === 'verified' && $mapping->entity_type === 'item' && $mapping->target_role === 'finance-history-record'
            && $mapping->target_type === 'finance.history-record' && $this->numeric($mapping->target_id), 409, 'A verified historical finance record is required.');
    }

    private function reply($actor, Batch $batch, Record $record, array $input): ?Entry
    {
        if ($input['entry_kind'] !== 'reply') {
            abort_unless($input['reply_to_mapping_id'] === null, 422, 'Only financial replies have a parent update.');

            return null;
        }
        abort_unless($input['reply_to_mapping_id'] !== null, 422, 'A financial reply requires its source update.');
        $mapping = Mapping::findOrFail($input['reply_to_mapping_id']);
        abort_unless($mapping->boundary === $batch->boundary && $mapping->provider === $batch->provider && $mapping->source_account === $batch->source_account
            && $mapping->entity_type === 'update' && $mapping->target_role === 'finance-history-entry' && $mapping->state === 'verified'
            && $mapping->target_type === 'finance.history-entry' && $this->numeric($mapping->target_id), 409, 'Financial reply source association changed.');
        $entry = $record->entries()->where('entry_kind', 'update')->findOrFail((int) $mapping->target_id);
        abort_unless($entry->source_mapping_id === $mapping->id, 409, 'Financial reply source association changed.');
        app(Records::class)->entry($actor, $record->id, $entry->id);
        $root = $record->entries()->where('family_key', $entry->family_key)->where('source_revision', 1)->sole();
        app(Records::class)->entry($actor, $record->id, $root->id);

        return $root;
    }

    private function predecessor(Batch $batch, Record $record, array $input, ?Entry $previous, ?Entry $existing): void
    {
        if ($input['revision'] === 1) {
            abort_unless($input['previous_receipt_id'] === null && ($existing !== null || $previous === null), 409, 'Financial source revision changed.');

            return;
        }
        abort_unless($previous && $previous->record_id === $record->id && $previous->source_revision === $input['revision'] - 1 && $input['previous_receipt_id'] !== null, 409, 'A verified previous financial source revision is required.');
        $receipt = Operation::findOrFail($input['previous_receipt_id']);
        $mapping = Mapping::findOrFail($previous->source_mapping_id);
        $oldBatch = Batch::findOrFail($receipt->batch_id);
        abort_unless($receipt->state === 'verified' && $receipt->mapping_id === $previous->source_mapping_id && $mapping->state === 'verified'
            && $receipt->result_target_type === 'finance.history-entry' && $receipt->result_target_id === (string) $previous->id
            && hash_equals($receipt->result_fingerprint, Fingerprints::entry($previous))
            && $batch->only(['mapping_revision', 'code_revision', 'plan_revision']) === $oldBatch->only(['mapping_revision', 'code_revision', 'plan_revision']), 409, 'Financial source revision context changed.');
    }

    private function replay($actor, string $boundary, Operation $operation, string $sourceHash, ?Mapping $mapping, ?int $id, ?string $targetHash, string $type): array
    {
        abort_unless($mapping && $id !== null && $targetHash !== null && $operation->mapping_id === $mapping->id && hash_equals($operation->source_fingerprint, $sourceHash), 409, 'Financial request conflicts with retained history.');

        return $this->ledger->finish($actor, $boundary, $operation->id, $operation->request_key,
            ['state' => 'verified', 'target_type' => $type, 'target_id' => (string) $id, 'result_fingerprint' => $targetHash]);
    }

    private function input(array $input): void
    {
        Metadata::exact($input, ['entry_kind', 'source_id', 'source_parent_id', 'revision', 'request_key', 'previous_receipt_id', 'reply_to_mapping_id']);
        Metadata::opaque($input['source_id']);
        Metadata::opaque($input['source_parent_id']);
        Metadata::uuid($input['request_key']);
        abort_unless(in_array($input['entry_kind'], ['facts', 'update', 'reply'], true) && is_int($input['revision']) && $input['revision'] >= 1 && $input['revision'] <= 1000000
            && ($input['previous_receipt_id'] === null || is_int($input['previous_receipt_id']) && $input['previous_receipt_id'] > 0)
            && ($input['reply_to_mapping_id'] === null || is_int($input['reply_to_mapping_id']) && $input['reply_to_mapping_id'] > 0), 422, 'Invalid financial source history context.');
    }

    private function guarded($actor, string $boundary, string $batchId, \Closure $work): array
    {
        Access::actor($actor, true);
        Metadata::digest($boundary);
        Metadata::uuid($batchId);
        try {
            return DB::transaction(function () use ($actor, $boundary, $batchId, $work): array {
                $user = $this->guard($actor, $boundary, $batchId);
                $batch = Batch::lockForUpdate()->findOrFail($batchId);
                $user = $this->guard($user, $boundary, $batchId);
                $result = $work($user, $batch);
                $this->guard($user, $boundary, $batchId);

                return $result;
            });
        } catch (AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            throw new \RuntimeException('The historical finance source could not be retained safely.');
        }
    }

    private function guard($actor, string $boundary, string $batchId, ?int $mappingId = null)
    {
        $user = Access::actor($actor, true);
        abort_unless(hash_equals(Runtime::current()['boundary'], $boundary), 409, 'Import runtime boundary changed.');
        $batch = Batch::findOrFail($batchId);
        abort_unless($batch->boundary === $boundary && $batch->state === 'open', 409, 'Import batch is unavailable.');
        if ($mappingId !== null) {
            $this->record($user, $batch, Mapping::findOrFail($mappingId));
        }

        return $user;
    }

    private function mapping(Batch $batch, array $source): ?Mapping
    {
        return Mapping::where(['boundary' => $batch->boundary, 'provider' => $batch->provider, 'source_account' => $batch->source_account, ...$source])->first();
    }

    private function lock(string $family): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['finance-history:'.$family]);
    }

    private function numeric(mixed $value): bool
    {
        return is_string($value) && ctype_digit($value) && (string) (int) $value === $value && (int) $value > 0;
    }
}

/** @internal Roll back a business savepoint before retaining reservation status. */
final class AppendRefused extends \RuntimeException {}
