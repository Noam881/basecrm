<?php

namespace Awove\Marketing\Imports;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\LegacyPayload;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Metadata;
use Awove\Core\Imports\Operation;
use Awove\Core\Imports\OriginalFields;
use Awove\Core\Imports\Runtime;
use Awove\Core\Models\Company;
use Awove\Core\Models\Lead;
use Awove\Marketing\Access;
use Awove\Marketing\Models\History;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Marketing-owned immutable source facts; no Core payload, fake Lead or frozen snapshot writes. */
class Originals
{
    public function __construct(private Ledger $ledger, private Sources $sources) {}

    public function authorizeSource($actor, string $boundary, string $batchId, int $parentId): array
    {
        [$user, $batch, $parent] = $this->context($actor, $boundary, $batchId, $parentId);
        $columns = $this->sources->policy($user, $batch, $parent, 'preserve')->columns($batch, $parent);
        abort_unless(array_is_list($columns) && count($columns) <= 250 && count(array_unique($columns)) === count($columns), 422, 'Invalid Marketing source columns.');
        foreach ($columns as $column) {
            Metadata::opaque($column);
        }

        return $columns;
    }

    public function preserve($actor, string $boundary, string $batchId, int $parentId, array $input, array $fields): array
    {
        Metadata::exact($input, ['request_key', 'revision', 'previous_receipt_id', 'author', 'source_created_at', 'source_updated_at']);
        Metadata::uuid($input['request_key']);
        abort_unless(is_int($input['revision']) && $input['revision'] >= 1 && $input['revision'] <= 1000000
            && ($input['previous_receipt_id'] === null || is_int($input['previous_receipt_id']) && $input['previous_receipt_id'] > 0), 422, 'Invalid Marketing source revision.');
        $attribution = LegacyPayload::validate('source-created', ['body_html' => null, ...array_intersect_key($input, array_flip(['author', 'source_created_at', 'source_updated_at']))]);
        $payload = [...array_intersect_key($attribution, array_flip(['author', 'source_created_at', 'source_updated_at'])), ...OriginalFields::validate($fields)];
        try {
            return DB::transaction(function () use ($actor, $boundary, $batchId, $parentId, $input, $payload): array {
                [$user, $batch, $parent, $target] = $this->context($actor, $boundary, $batchId, $parentId, true);
                $columns = $this->authorizeSource($user, $boundary, $batchId, $parentId);
                $actual = array_column($payload['cells'], 'id');
                sort($actual);
                sort($columns);
                abort_unless($actual === $columns, 403, 'Marketing original column ownership changed.');
                $family = self::digest('original-family', [$parent->only(['boundary', 'provider', 'source_account', 'board_id', 'entity_id', 'target_role', 'relation_key'])]);
                $kind = $target instanceof Lead ? 'source-lead' : 'source-company';
                $query = History::where('kind', $kind)->where('record_id', $target->id)->where('details->family_key', $family);
                $same = (clone $query)->where('details->source_revision', $input['revision'])->get();
                abort_unless($same->count() <= 1, 409, 'Marketing originals need reconciliation.');
                $existing = $same->first();
                $previous = (clone $query)->where('details->source_revision', '<', $input['revision'])->orderByDesc('id')->first();
                abort_unless($input['revision'] === 1 ? $input['previous_receipt_id'] === null && $previous === null
                    : $previous && ($previous->details['source_revision'] ?? null) === $input['revision'] - 1 && ($previous->details['operation_id'] ?? null) === $input['previous_receipt_id'], 409, 'Marketing original predecessor changed.');
                if ($previous) {
                    $this->entry($user, $kind, $target->id, $previous->id);
                    abort_unless(self::digest('original-content', $previous->details['payload']) !== self::digest('original-content', $payload), 409, 'Unchanged originals must reuse their revision.');
                }
                abort_unless(! (clone $query)->where('details->source_revision', '>', $input['revision'])->exists(), 409, 'Marketing original revision is stale.');
                $source = ['board_id' => $parent->board_id, 'entity_type' => 'item', 'entity_id' => $parent->entity_id, 'target_role' => 'marketing-original', 'relation_key' => self::digest('original-occurrence', [$family, $input['revision']])];
                $fingerprint = self::digest('original-source', [$family, $input['revision'], $payload]);
                if ($existing) {
                    $this->entry($user, $kind, $target->id, $existing->id);
                    abort_unless(self::digest('original-source', [$family, $input['revision'], $existing->details['payload']]) === $fingerprint, 409, 'Marketing original source changed.');

                    return ['state' => 'verified', 'history_id' => $existing->id, 'receipt_id' => $existing->details['operation_id']];
                }
                $receipt = $this->ledger->claim($user, $boundary, $batchId, $source, ['request_key' => $input['request_key'], 'revision' => 1, 'source_fingerprint' => $fingerprint, 'target_fingerprint' => null, 'previous_receipt_id' => null]);
                if ($receipt['state'] !== 'claimed') {
                    return ['state' => $receipt['state'], 'history_id' => null];
                }
                $sequence = (int) History::where('kind', $kind)->where('record_id', $target->id)->max('version') + 1;
                $history = History::create(['kind' => $kind, 'record_id' => $target->id, 'actor_id' => $user->id, 'event' => 'source.original-retained', 'version' => $sequence,
                    'details' => ['schema' => 1, 'family_key' => $family, 'parent_mapping_id' => $parent->id, 'source_mapping_id' => $receipt['mapping_id'], 'operation_id' => $receipt['id'],
                        'source_revision' => $input['revision'], 'previous_history_id' => $previous?->id, 'payload' => $payload]]);
                $history->refresh();
                $finished = $this->ledger->finish($user, $boundary, $receipt['id'], $input['request_key'], ['state' => 'verified', 'target_type' => 'marketing.history', 'target_id' => (string) $history->id, 'result_fingerprint' => self::fingerprint($history)]);
                abort_unless($finished['state'] === 'verified', 409, 'Marketing original needs reconciliation.');
                $this->context($user, $boundary, $batchId, $parentId, true);
                $this->entry($user, $kind, $target->id, $history->id);

                return ['state' => 'verified', 'history_id' => $history->id, 'receipt_id' => $finished['id']];
            });
        } catch (AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            throw new \RuntimeException('Marketing originals could not be retained safely.');
        }
    }

    public function context($actor, string $boundary, string $batchId, int $parentId, bool $lock = false): array
    {
        $user = Access::actor($actor);
        abort_unless($user->hasVerifiedEmail() && $user->can('imports.manage') && $user->can('marketing.attribution.edit') && $user->can('marketing.attribution.view'), 403);
        abort_unless(hash_equals(Runtime::current()['boundary'], Metadata::digest($boundary)), 409, 'Marketing import runtime changed.');
        $batch = Batch::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(Metadata::uuid($batchId));
        $parent = Mapping::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($parentId);
        abort_unless($batch->state === 'open' && $batch->boundary === $boundary && $parent->boundary === $boundary && $parent->provider === $batch->provider
            && $parent->source_account === $batch->source_account && $parent->entity_type === 'item' && $parent->state === 'verified' && $parent->target_id !== null
            && Operation::where('mapping_id', $parent->id)->where('state', 'verified')->where('result_target_type', $parent->target_type)->where('result_target_id', $parent->target_id)->exists(), 409, 'Verified Marketing parent required.');
        abort_unless(ctype_digit($parent->target_id) && (string) (int) $parent->target_id === $parent->target_id && (int) $parent->target_id > 0, 409);
        $model = match ($parent->target_type) {
            'core.lead' => Lead::class, 'core.company' => Company::class, default => abort(409, 'Unsupported Marketing parent.')
        };
        $target = $model::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail((int) $parent->target_id);
        Gate::forUser($user)->authorize('update', $target);
        $this->sources->policy($user, $batch, $parent, 'preserve');

        return [$user, $batch, $parent, $target];
    }

    public function page($actor, string $kind, int $id, ?int $before = null): array
    {
        $this->readParent($actor, $kind, $id);
        abort_unless($before === null || $before > 0, 422);
        $rows = History::where('kind', $kind)->where('record_id', $id)->when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(11)->get();
        $more = $rows->count() > 10;
        $rows = $rows->take(10);

        return ['entries' => $rows->map(function ($row) use ($actor, $kind, $id): array {
            $entry = $this->entry($actor, $kind, $id, $row->id);

            return ['id' => $row->id, 'source_revision' => $row->details['source_revision'], 'imported_at' => $row->created_at->toIso8601String(),
                'author' => $entry['author'], 'source_created_at' => $entry['source_created_at'], 'cell_count' => count($entry['cells']), 'source' => $entry['source']];
        })->values()->all(), 'next_before' => $more ? $rows->last()->id : null];
    }

    public function entry($actor, string $kind, int $id, int $historyId): array
    {
        $this->readParent($actor, $kind, $id);
        $row = History::where('kind', $kind)->where('record_id', $id)->findOrFail($historyId);
        $d = $row->details;
        abort_unless(is_array($d) && ($d['schema'] ?? null) === 1, 503, 'Marketing originals unavailable.');
        $op = Operation::findOrFail($d['operation_id']);
        $mapping = Mapping::findOrFail($d['source_mapping_id']);
        $parent = Mapping::findOrFail($d['parent_mapping_id']);
        $type = $kind === 'source-lead' ? 'core.lead' : 'core.company';
        abort_unless($op->state === 'verified' && $op->mapping_id === $mapping->id && $op->result_target_type === 'marketing.history' && $op->result_target_id === (string) $row->id
            && $mapping->state === 'verified' && $mapping->target_type === 'marketing.history' && $mapping->target_id === (string) $row->id && $mapping->target_role === 'marketing-original'
            && $mapping->boundary === Runtime::current()['boundary'] && $parent->boundary === $mapping->boundary && $parent->provider === $mapping->provider && $parent->source_account === $mapping->source_account
            && $parent->board_id === $mapping->board_id && $parent->entity_id === $mapping->entity_id && $parent->state === 'verified'
            && $parent->target_type === $type && $parent->target_id === (string) $id
            && Operation::where('mapping_id', $parent->id)->where('state', 'verified')->where('result_target_type', $type)->where('result_target_id', (string) $id)->exists() && hash_equals($op->result_fingerprint, self::fingerprint($row)), 503, 'Marketing original evidence changed.');
        $payload = $d['payload'];
        OriginalFields::validate(['cells' => $payload['cells']]);
        LegacyPayload::validate('source-created', ['body_html' => null, ...array_intersect_key($payload, array_flip(['author', 'source_created_at', 'source_updated_at']))]);
        $this->readParent($actor, $kind, $id);

        return [...$payload, 'source' => ['provider' => $mapping->provider, 'board_id' => $mapping->board_id, 'item_id' => $mapping->entity_id, 'revision' => $d['source_revision']]];
    }

    private function readParent($actor, string $kind, int $id): void
    {
        $user = Access::actor($actor);
        abort_unless($user->hasVerifiedEmail() && $user->can('marketing.attribution.view'), 403);
        $model = match ($kind) {
            'source-lead' => Lead::class, 'source-company' => Company::class, default => abort(404)
        };
        Gate::forUser($user)->authorize('view', $model::findOrFail($id));
    }

    public static function digest(string $purpose, array $value): string
    {
        return hash_hmac('sha256', 'marketing-import:v1:'.$purpose."\0".json_encode(self::ordered($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), app('encrypter')->getKey());
    }

    /** JSONB reorders object members; list order and all original JSON strings remain untouched. */
    private static function ordered(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::ordered($item);
            }
        }

        return $value;
    }

    private static function fingerprint(History $history): string
    {
        return self::digest('original-target', $history->getAttributes());
    }
}
