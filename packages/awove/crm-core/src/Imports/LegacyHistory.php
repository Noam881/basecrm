<?php

namespace Awove\Core\Imports;

use Awove\Core\Models\Activity;
use Awove\Core\Models\Lead;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Atomic ordinary history writer. Immutable source revisions are separate retained occurrences. */
class LegacyHistory
{
    public const EVENT = Activity::LEGACY_EVENT;

    public const PREVIEW_BYTES = 8192;

    public const PAGE_SIZE = 10;

    public function __construct(private Ledger $ledger) {}

    /** No caller-supplied target ID or fingerprint and no source I/O inside the transaction. */
    public function append($actor, string $boundary, string $batchId, int $parentMappingId, array $input, array $payload): array
    {
        $this->actor($actor, true);
        Metadata::digest($boundary);
        Metadata::uuid($batchId);
        Metadata::exact($input, ['kind', 'source_id', 'source_parent_id', 'revision', 'request_key', 'previous_receipt_id', 'reply_to_mapping_id']);
        abort_unless(in_array($input['kind'], LegacyPayload::KINDS, true) && is_int($input['revision']) && $input['revision'] >= 1 && $input['revision'] <= 1000000
            && ($input['previous_receipt_id'] === null || is_int($input['previous_receipt_id']) && $input['previous_receipt_id'] > 0)
            && ($input['reply_to_mapping_id'] === null || is_int($input['reply_to_mapping_id']) && $input['reply_to_mapping_id'] > 0), 422, 'Invalid legacy history context.');
        Metadata::opaque($input['source_id']);
        Metadata::opaque($input['source_parent_id']);
        Metadata::uuid($input['request_key']);
        $payload = LegacyPayload::validate($input['kind'], $payload);

        try {
            return DB::transaction(function () use ($actor, $boundary, $batchId, $parentMappingId, $input, $payload): array {
                $user = $this->actor($actor, true);
                $this->boundary($boundary);
                $batch = Batch::lockForUpdate()->findOrFail($batchId);
                abort_unless($batch->boundary === $boundary && $batch->state === 'open', 409, 'Import batch is unavailable.');
                $parent = Mapping::findOrFail($parentMappingId);
                $this->parent($parent, $batch);
                $family = $this->digest('family', [$parent->only(['boundary', 'provider', 'source_account', 'board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key']), $input['kind'], $input['source_id']]);
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['core-legacy-family:'.$family]);
                $parent = Mapping::lockForUpdate()->findOrFail($parentMappingId);
                $this->parent($parent, $batch);
                abort_unless($input['source_parent_id'] === $parent->entity_id, 409, 'Source parent item changed.');
                abort_unless(hash_equals($family, $this->digest('family', [$parent->only(['boundary', 'provider', 'source_account', 'board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key']), $input['kind'], $input['source_id']])), 409, 'Legacy source identity changed.');
                $parentIdentity = $parent->getAttributes();
                $lead = $this->lead($user, $parent);
                $reply = $this->reply($batch, $parent, $lead, $input);
                abort_unless($input['kind'] !== 'source-created' || $input['source_id'] === $parent->entity_id, 409, 'Source record identity changed.');
                $entries = Activity::where('lead_id', $lead->id)->where('event', self::EVENT)->where('details->family_key', $family);
                $same = (clone $entries)->where('details->source_revision', $input['revision'])->limit(2)->get();
                abort_unless($same->count() <= 1, 409, 'Legacy history needs reconciliation.');
                $existing = $same->first();
                $previous = $existing
                    ? (($existing->details['previous_activity_id'] ?? null) ? (clone $entries)->find($existing->details['previous_activity_id']) : null)
                    : (clone $entries)->orderByDesc('id')->first();
                $this->predecessor($batch, $parent, $input, $previous, $existing);
                $retained = $existing ?? $previous;
                abort_unless($input['kind'] !== 'reply' || $retained === null || ($retained->details['reply_to_activity_id'] ?? null) === $reply?->id, 409, 'Reply source association is immutable.');
                $source = ['board_id' => $parent->board_id, 'entity_type' => $input['kind'] === 'source-created' ? 'item' : $input['kind'],
                    'entity_id' => $input['source_id'], 'target_role' => 'legacy-history', 'relation_key' => $this->digest('occurrence', [$family, $input['revision']])];
                $mapping = Mapping::where(['boundary' => $boundary, 'provider' => $batch->provider, 'source_account' => $batch->source_account, ...$source])->first();
                if ($mapping?->target_id !== null) {
                    abort_unless($existing && $mapping->target_type === 'core.activity' && $mapping->target_id === (string) $existing->id
                        && ($existing->details['source_mapping_id'] ?? null) === $mapping->id, 409, 'Legacy history target needs reconciliation.');
                } else {
                    abort_unless($existing === null, 409, 'Legacy history target needs reconciliation.');
                }
                $sourceFingerprint = $this->digest('source', [$family, $input['revision'], $previous?->id, $reply?->id, $payload]);
                $claim = ['request_key' => $input['request_key'], 'revision' => 1, 'source_fingerprint' => $sourceFingerprint,
                    'target_fingerprint' => $existing ? $this->fingerprint($existing) : null, 'previous_receipt_id' => null];
                $reserved = Operation::where('batch_id', $batchId)->where('request_key', $input['request_key'])->first();
                if ($reserved?->state === 'verified') {
                    abort_unless($existing && $reserved->mapping_id === $mapping->id && hash_equals($reserved->source_fingerprint, $sourceFingerprint), 409, 'Legacy request conflicts with retained history.');
                    // Replay the original finish, not its original pre-write null observation.
                    $receipt = $this->ledger->finish($user, $boundary, $reserved->id, $input['request_key'],
                        ['state' => 'verified', 'target_type' => 'core.activity', 'target_id' => (string) $existing->id, 'result_fingerprint' => $this->fingerprint($existing)]);
                } else {
                    $receipt = $this->ledger->claim($user, $boundary, $batchId, $source, $claim);
                }
                $result = ['receipt' => $receipt, 'activity_id' => $receipt['state'] === 'verified' ? $existing?->id : null];
                if ($receipt['state'] === 'claimed' && $reserved === null) {
                    try {
                        $result = DB::transaction(function () use ($user, $boundary, $batchId, $parent, $lead, $input, $payload, $family, $previous, $reply, $receipt): array {
                            $this->guard($user, $boundary, $batchId, $parent);
                            $entry = Activity::create(['lead_id' => $lead->id, 'actor_id' => null, 'event' => self::EVENT, 'created_at' => now(),
                                'details' => ['schema' => 1, 'family_key' => $family, 'parent_mapping_id' => $parent->id,
                                    'source_mapping_id' => $receipt['mapping_id'], 'operation_id' => $receipt['id'], 'source_revision' => $input['revision'],
                                    'previous_activity_id' => $previous?->id, 'reply_to_activity_id' => $reply?->id,
                                    'kind' => $input['kind'], 'payload' => $payload]]);
                            $entry->refresh();
                            $this->guard($user, $boundary, $batchId, $parent);
                            $finished = $this->ledger->finish($user, $boundary, $receipt['id'], $input['request_key'],
                                ['state' => 'verified', 'target_type' => 'core.activity', 'target_id' => (string) $entry->id, 'result_fingerprint' => $this->fingerprint($entry)]);
                            if ($finished['state'] !== 'verified') {
                                throw new LegacyAppendRefused;
                            }

                            return ['receipt' => $finished, 'activity_id' => $entry->id];
                        });
                    } catch (LegacyAppendRefused) {
                        // Roll back the Activity and finish together, then retain only reservation status.
                        $result = ['receipt' => $this->ledger->claim($user, $boundary, $batchId, $source, $claim), 'activity_id' => null];
                    }
                }
                $this->guard($user, $boundary, $batchId, $parent);
                abort_unless(Mapping::findOrFail($parentMappingId)->getAttributes() === $parentIdentity, 409, 'Legacy history parent changed.');

                return $result;
            });
        } catch (AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            // No backend exception, source text, private context or chained payload reaches reports.
            throw new \RuntimeException('The legacy history could not be retained safely.');
        }
    }

    public function page($actor, Lead $lead, ?int $before = null): array
    {
        $user = $this->actor($actor);
        $lead = $lead->fresh();
        abort_unless($lead !== null, 404);
        Gate::forUser($user)->authorize('view', $lead);
        abort_unless($before === null || $before > 0, 422, 'Invalid history cursor.');
        $entries = Activity::where('lead_id', $lead->id)->where('event', self::EVENT)->when($before, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::PAGE_SIZE + 1)->get();
        $more = $entries->count() > self::PAGE_SIZE;
        $entries = $entries->take(self::PAGE_SIZE);

        return ['entries' => $entries->map(fn (Activity $entry) => $this->dto($entry, false))->values()->all(), 'next_cursor' => $more ? $entries->last()->id : null];
    }

    public function entry($actor, Lead $lead, int $id): array
    {
        $user = $this->actor($actor);
        $lead = $lead->fresh();
        abort_unless($lead !== null, 404);
        Gate::forUser($user)->authorize('view', $lead);
        $entry = Activity::where('lead_id', $lead->id)->where('event', self::EVENT)->findOrFail($id);

        return ['entry' => $this->dto($entry, true)];
    }

    private function dto(Activity $entry, bool $full): array
    {
        $details = $entry->details;
        abort_unless(is_array($details) && ($details['schema'] ?? null) === 1 && in_array($details['kind'] ?? null, LegacyPayload::KINDS, true), 503, 'Legacy history is unavailable.');
        $payload = $details['payload'] ?? null;
        abort_unless(is_array($payload) && is_string($payload['text'] ?? null) && mb_check_encoding($payload['text'], 'UTF-8')
            && strlen($payload['text']) <= LegacyPayload::MAX_TEXT_BYTES, 503, 'Legacy history is unavailable.');
        $operation = Operation::findOrFail($details['operation_id'] ?? 0);
        abort_unless($operation->state === 'verified' && $operation->mapping_id === ($details['source_mapping_id'] ?? null)
            && $operation->result_target_type === 'core.activity' && $operation->result_target_id === (string) $entry->id
            && hash_equals($operation->result_fingerprint, $this->fingerprint($entry)), 503, 'Legacy history is unavailable.');
        $actor = config('auth.providers.users.model')::find($operation->actor_id);
        $hasMore = ! $full && strlen($payload['text']) > self::PREVIEW_BYTES;

        return ['id' => $entry->id, 'event' => self::EVENT, 'actor' => null, 'created_at' => $entry->created_at->toISOString(),
            'legacy' => ['kind' => $details['kind'], 'text' => $hasMore ? mb_strcut($payload['text'], 0, self::PREVIEW_BYTES, 'UTF-8') : $payload['text'],
                'author' => $payload['author'], 'source_created_at' => $payload['source_created_at'], 'source_updated_at' => $payload['source_updated_at'],
                'parent_activity_id' => $details['reply_to_activity_id'], 'revision' => $details['source_revision'],
                'ingested_at' => $operation->finished_at->toISOString(), 'ingested_by' => $actor?->only(['id', 'name']),
                'source_media_present' => $payload['source_media_present'] ?? false,
                'has_more_text' => $hasMore, 'full_text_url' => $hasMore ? '/leads/'.$entry->lead_id.'/legacy-history/'.$entry->id : null]];
    }

    private function parent(Mapping $parent, Batch $batch): void
    {
        abort_unless($parent->boundary === $batch->boundary && $parent->provider === $batch->provider && $parent->source_account === $batch->source_account
            && $parent->state === 'verified' && $parent->entity_type === 'item' && $parent->target_type === 'core.lead'
            && is_string($parent->target_id) && ctype_digit($parent->target_id) && (string) (int) $parent->target_id === $parent->target_id && (int) $parent->target_id > 0
            && Operation::where('mapping_id', $parent->id)->where('state', 'verified')->where('result_target_type', 'core.lead')->where('result_target_id', $parent->target_id)->exists(), 409, 'A verified source-item lead mapping is required.');
    }

    private function lead($actor, Mapping $parent): Lead
    {
        $lead = Lead::lockForUpdate()->findOrFail((int) $parent->target_id);
        Gate::forUser($actor)->authorize('update', $lead);

        return $lead;
    }

    private function reply(Batch $batch, Mapping $parent, Lead $lead, array $input): ?Activity
    {
        if ($input['kind'] !== 'reply') {
            abort_unless($input['reply_to_mapping_id'] === null, 422, 'Only replies have a parent update.');

            return null;
        }
        abort_unless($input['reply_to_mapping_id'] !== null, 422, 'A reply requires its retained source update.');
        $mapping = Mapping::findOrFail($input['reply_to_mapping_id']);
        abort_unless($mapping->boundary === $batch->boundary && $mapping->provider === $batch->provider && $mapping->source_account === $batch->source_account
            && $mapping->board_id === $parent->board_id && $mapping->entity_type === 'update' && $mapping->target_role === 'legacy-history'
            && $mapping->state === 'verified' && $mapping->target_type === 'core.activity', 409, 'Reply source association changed.');
        $entry = Activity::where('lead_id', $lead->id)->where('event', self::EVENT)->findOrFail((int) $mapping->target_id);
        abort_unless($mapping->target_id === (string) $entry->id && ($entry->details['source_mapping_id'] ?? null) === $mapping->id
            && ($entry->details['parent_mapping_id'] ?? null) === $parent->id && ($entry->details['kind'] ?? null) === 'update', 409, 'Reply source association changed.');
        $this->dto($entry, false);

        // Replies belong to the update identity, independently of later edits to its body.
        $entry = Activity::where('lead_id', $lead->id)->where('event', self::EVENT)->where('details->family_key', $entry->details['family_key'])->where('details->source_revision', 1)->sole();
        $this->dto($entry, false);

        return $entry;
    }

    private function predecessor(Batch $batch, Mapping $parent, array $input, ?Activity $previous, ?Activity $existing): void
    {
        if ($input['revision'] === 1) {
            abort_unless($input['previous_receipt_id'] === null && ($existing !== null || $previous === null), 409, 'Legacy source revision changed.');

            return;
        }
        abort_unless($previous && ($previous->details['source_revision'] ?? null) === $input['revision'] - 1 && $input['previous_receipt_id'] !== null, 409, 'A verified previous source revision is required.');
        $receipt = Operation::findOrFail($input['previous_receipt_id']);
        $oldBatch = Batch::findOrFail($receipt->batch_id);
        $previousMapping = Mapping::findOrFail($previous->details['source_mapping_id']);
        abort_unless($receipt->state === 'verified' && $receipt->mapping_id === ($previous->details['source_mapping_id'] ?? null)
            && $previousMapping->state === 'verified' && $previousMapping->target_type === 'core.activity' && $previousMapping->target_id === (string) $previous->id
            && $receipt->result_target_type === 'core.activity' && $receipt->result_target_id === (string) $previous->id
            && hash_equals($receipt->result_fingerprint, $this->fingerprint($previous))
            && ($previous->details['parent_mapping_id'] ?? null) === $parent->id
            && $batch->only(['mapping_revision', 'code_revision', 'plan_revision']) === $oldBatch->only(['mapping_revision', 'code_revision', 'plan_revision']), 409, 'Legacy source revision context changed.');
    }

    private function guard($actor, string $boundary, string $batchId, Mapping $parent): void
    {
        $actor = $this->actor($actor, true);
        $this->boundary($boundary);
        $batch = Batch::findOrFail($batchId);
        abort_unless($batch->boundary === $boundary && $batch->state === 'open', 409, 'Import batch is unavailable.');
        $this->parent($parent->fresh(), $batch);
        $this->lead($actor, $parent);
    }

    private function actor($actor, bool $write = false)
    {
        $user = $actor?->fresh();
        abort_unless($user && $user->active && $user->hasVerifiedEmail() && (! $write || $user->can('imports.manage')), 403);

        return $user;
    }

    private function boundary(string $boundary): void
    {
        abort_unless(hash_equals(Runtime::current()['boundary'], $boundary), 409, 'Import runtime boundary changed.');
    }

    private function fingerprint(Activity $entry): string
    {
        return $this->digest('target', [$entry->id, $entry->lead_id, $entry->actor_id, $entry->event, $entry->created_at->toISOString(), $entry->details]);
    }

    private function digest(string $purpose, array $data): string
    {
        return hash_hmac('sha256', 'core-legacy-history:v1:'.$purpose."\0".json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), app('encrypter')->getKey());
    }
}

/** @internal Causes only the business-write savepoint to roll back, never accepts an outcome. */
final class LegacyAppendRefused extends \RuntimeException {}
