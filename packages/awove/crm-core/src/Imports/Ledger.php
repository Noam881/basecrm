<?php

namespace Awove\Core\Imports;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Metadata reservations and operator-attested receipts only: no business writers, snapshots,
 * customer authorizations, Vault unlocks, automation suppression or recovery guarantees.
 * Source/target fingerprints must be keyed digests prepared by the reviewed owning adapter.
 */
class Ledger
{
    public const RESERVATION_SECONDS = 300;

    public function boundary($actor): string
    {
        $this->actor($actor);
        $runtime = Runtime::current();
        $this->actor($actor);

        return $runtime['boundary'];
    }

    public function start($actor, string $boundary, array $metadata): array
    {
        $this->actor($actor);
        $data = Metadata::batch($metadata);

        return $this->guarded($actor, $boundary, function ($user, array $runtime) use ($data): array {
            $context = [...$data, ...$runtime];
            Batch::firstOrCreate(['id' => $data['id']], [...$context, 'created_by' => $user->id]);
            $batch = Batch::lockForUpdate()->findOrFail($data['id']);
            $this->same($batch, $context);

            return $this->batchMetadata($batch);
        });
    }

    /** A repeated request key returns its original reservation/receipt without extending its deadline. */
    public function claim($actor, string $boundary, string $batchId, array $source, array $metadata): array
    {
        $this->actor($actor);
        $source = Metadata::source($source);
        $data = Metadata::claim($metadata);

        return $this->guarded($actor, $boundary, function ($user) use ($boundary, $batchId, $source, $data): array {
            $batch = $this->batch($batchId, $boundary);
            abort_unless($batch->state === 'open', 409, 'Import batch is paused.');
            $identity = ['boundary' => $boundary, 'provider' => $batch->provider, 'source_account' => $batch->source_account, ...$source];
            $existing = Operation::where('batch_id', $batch->id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $this->same(Mapping::findOrFail($existing->mapping_id), $identity);
                $this->same($existing, $data);
            }
            $mapping = Mapping::firstOrCreate($identity, ['created_by' => $user->id]);
            $mapping = Mapping::lockForUpdate()->findOrFail($mapping->id);
            if ($existing) {
                $existing = Operation::lockForUpdate()->findOrFail($existing->id);
                $this->expire($user, $mapping, $existing);
                abort_unless($existing->state !== 'claimed' || $existing->actor_id === $user->id, 409, 'Import reservation belongs to another operator.');

                return $this->receipt($existing);
            }
            if ($data['previous_receipt_id'] !== null) {
                abort_unless(Operation::whereKey($data['previous_receipt_id'])->where('mapping_id', $mapping->id)->where('state', 'verified')->exists(), 409, 'Import revision reference changed.');
            }
            $active = Operation::where('mapping_id', $mapping->id)->where('state', 'claimed')->lockForUpdate()->first();
            if ($active) {
                $this->expire($user, $mapping, $active);
                if ($active->state === 'claimed') {
                    return ['state' => 'busy', 'operation_id' => $active->id, 'mapping_id' => $mapping->id,
                        'reservation_expires_at' => $active->reservation_expires_at->toISOString()];
                }
            }
            if (Operation::where('mapping_id', $mapping->id)->whereIn('state', ['failed', 'conflict', 'reconciliation_required'])->exists()) {
                return $this->terminal($user, $batch, $mapping, $data, 'conflict', 'prior_unresolved_operation');
            }
            $accepted = Operation::where('mapping_id', $mapping->id)->where('state', 'verified')->orderByDesc('revision')->orderByDesc('id')->first();
            if ($accepted) {
                if ($data['revision'] === $accepted->revision) {
                    $error = match (true) {
                        $data['source_fingerprint'] !== $accepted->source_fingerprint => 'source_changed',
                        $data['observed_target_fingerprint'] !== $accepted->result_fingerprint => 'target_changed',
                        ! $this->sameRevisions($batch, Batch::findOrFail($accepted->batch_id)) => 'mapping_context_changed',
                        default => null,
                    };
                    if ($error !== null) {
                        return $this->terminal($user, $batch, $mapping, $data, 'conflict', $error);
                    }

                    return $this->terminal($user, $batch, $mapping, $data, 'verified', null, $accepted);
                }
                if ($data['revision'] !== $accepted->revision + 1 || $data['previous_receipt_id'] !== $accepted->id) {
                    return $this->terminal($user, $batch, $mapping, $data, 'conflict', 'revision_conflict');
                }
                if ($data['observed_target_fingerprint'] !== $accepted->result_fingerprint) {
                    return $this->terminal($user, $batch, $mapping, $data, 'conflict', 'target_changed');
                }
            } elseif ($data['revision'] !== 1 || $data['previous_receipt_id'] !== null) {
                return $this->terminal($user, $batch, $mapping, $data, 'conflict', 'revision_conflict');
            }
            $operation = Operation::create([...$this->operationData($user, $batch, $mapping, $data), 'state' => 'claimed']);
            $mapping->update(['state' => 'claimed']);

            return $this->receipt($operation);
        });
    }

    /** The caller attests its outcome; this foundation never reads or changes the destination resource. */
    public function finish($actor, string $boundary, int $operationId, string $requestKey, array $metadata): array
    {
        $this->actor($actor);
        $requestKey = Metadata::uuid($requestKey);
        $result = Metadata::result($metadata);

        return $this->guarded($actor, $boundary, function ($user) use ($boundary, $operationId, $requestKey, $result): array {
            $initial = Operation::findOrFail($operationId);
            $batch = $this->batch($initial->batch_id, $boundary);
            $mapping = Mapping::lockForUpdate()->findOrFail($initial->mapping_id);
            $this->same($mapping, ['boundary' => $boundary, 'provider' => $batch->provider, 'source_account' => $batch->source_account]);
            $operation = Operation::lockForUpdate()->findOrFail($operationId);
            abort_unless($operation->request_key === $requestKey, 409, 'Import request key changed.');
            $intent = $this->finishIntent($boundary, $operation->id, $result);
            if ($operation->state !== 'claimed') {
                if ($operation->finish_intent_fingerprint !== null) {
                    abort_unless(hash_equals($operation->finish_intent_fingerprint, $intent), 409, 'Import finish submission conflicts with retained history.');

                    return $this->receipt($operation);
                }
                if (in_array($operation->state, ['conflict', 'reconciliation_required'], true)) {
                    // claim() can refuse/expire a reservation before finish() was submitted.
                    // This is status readback only: no outcome is accepted or fingerprint added.
                    return $this->receipt($operation);
                }
                $this->same($operation, $result);

                return $this->receipt($operation);
            }
            abort_unless($batch->state === 'open' && $operation->actor_id === $user->id, 409, 'Import reservation is unavailable.');
            $this->expire($user, $mapping, $operation, $intent);
            if ($operation->state === 'reconciliation_required') {
                return $this->receipt($operation);
            }
            if ($result['state'] === 'verified' && $mapping->target_id !== null
                && ($mapping->target_type !== $result['result_target_type'] || $mapping->target_id !== $result['result_target_id'])) {
                $result = ['state' => 'conflict', 'result_target_type' => null, 'result_target_id' => null, 'result_fingerprint' => null, 'error_code' => 'target_identity_conflict'];
            }
            $operation->update([...$result, 'finish_intent_fingerprint' => $intent, 'finished_by' => $user->id, 'finished_at' => now()]);
            $mapping->update(['state' => $result['state'], ...($result['state'] === 'verified'
                ? ['target_type' => $result['result_target_type'], 'target_id' => $result['result_target_id']] : [])]);

            return $this->receipt($operation);
        });
    }

    public function pause($actor, string $boundary, string $batchId, bool $paused = true): array
    {
        return $this->guarded($actor, $boundary, function () use ($boundary, $batchId, $paused): array {
            $batch = $this->batch($batchId, $boundary);
            $batch->update(['state' => $paused ? 'paused' : 'open']);

            return $this->batchMetadata($batch);
        });
    }

    /** Counts describe retained ledger metadata, never complete source coverage or migration success. */
    public function progress($actor, string $boundary, string $batchId): array
    {
        return $this->guarded($actor, $boundary, function () use ($boundary, $batchId): array {
            $batch = $this->batch($batchId, $boundary);
            $operations = Operation::where('batch_id', $batch->id);
            $states = array_fill_keys(['claimed', 'verified', 'failed', 'conflict', 'reconciliation_required'], 0);
            foreach ((clone $operations)->select('state')->selectRaw('COUNT(*) AS aggregate')->groupBy('state')->get() as $row) {
                $states[$row->state] = (int) $row->getAttribute('aggregate');
            }

            return ['batch' => $this->batchMetadata($batch), 'receipt_counts' => $states,
                'replayed_receipts' => (clone $operations)->whereNotNull('replayed_from_id')->count(),
                'overdue_reservations' => (clone $operations)->where('state', 'claimed')->where('reservation_expires_at', '<=', now())->count(),
                'touched_mappings' => (clone $operations)->distinct()->count('mapping_id'), 'is_migration_completion_proof' => false];
        });
    }

    private function guarded($actor, string $boundary, Closure $work): array
    {
        $this->actor($actor);
        Metadata::digest($boundary);

        return DB::transaction(function () use ($actor, $boundary, $work): array {
            $user = $this->actor($actor);
            $runtime = Runtime::current();
            abort_unless(hash_equals($runtime['boundary'], $boundary), 409, 'Import runtime boundary changed.');
            $result = $work($user, $runtime);
            $this->actor($user);
            abort_unless(hash_equals(Runtime::current()['boundary'], $boundary), 409, 'Import runtime boundary changed.');

            return $result;
        });
    }

    private function actor($actor)
    {
        $user = $actor?->fresh();
        abort_unless($user && $user->active && $user->email_verified_at !== null && $user->can('imports.manage'), 403);

        return $user;
    }

    private function batch(string $id, string $boundary): Batch
    {
        $batch = Batch::lockForUpdate()->findOrFail(Metadata::uuid($id));
        $this->same($batch, ['boundary' => $boundary]);

        return $batch;
    }

    private function same($record, array $expected): void
    {
        foreach ($expected as $field => $value) {
            abort_unless($record->getAttribute($field) === $value, 409, 'Import metadata conflicts with retained history.');
        }
    }

    private function sameRevisions(Batch $left, Batch $right): bool
    {
        return $left->only(['mapping_revision', 'code_revision', 'plan_revision']) === $right->only(['mapping_revision', 'code_revision', 'plan_revision']);
    }

    private function operationData($user, Batch $batch, Mapping $mapping, array $data): array
    {
        return [...$data, 'batch_id' => $batch->id, 'mapping_id' => $mapping->id, 'actor_id' => $user->id,
            'reservation_expires_at' => now()->addSeconds(self::RESERVATION_SECONDS)];
    }

    private function terminal($user, Batch $batch, Mapping $mapping, array $data, string $state, ?string $error, ?Operation $replay = null): array
    {
        $operation = Operation::create([...$this->operationData($user, $batch, $mapping, $data), 'state' => $state,
            'error_code' => $error, 'finished_by' => $user->id, 'finished_at' => now(),
            'result_target_type' => $replay?->result_target_type, 'result_target_id' => $replay?->result_target_id,
            'result_fingerprint' => $replay?->result_fingerprint, 'replayed_from_id' => $replay?->id]);
        $mapping->update(['state' => $state]);

        return $this->receipt($operation);
    }

    private function expire($user, Mapping $mapping, Operation $operation, ?string $finishIntent = null): void
    {
        if ($operation->state === 'claimed' && $operation->reservation_expires_at->lessThanOrEqualTo(now())) {
            $operation->update(['state' => 'reconciliation_required', 'error_code' => 'reservation_expired', 'finish_intent_fingerprint' => $finishIntent, 'finished_by' => $user->id, 'finished_at' => now()]);
            $mapping->update(['state' => 'reconciliation_required']);
        }
    }

    private function finishIntent(string $boundary, int $operationId, array $result): string
    {
        // Only validated opaque metadata/digests, never source/customer payloads.
        return hash_hmac('sha256', "crm-import-finish-intent:v1\0".json_encode([$boundary, $operationId, $result], JSON_THROW_ON_ERROR), app('encrypter')->getKey());
    }

    private function batchMetadata(Batch $batch): array
    {
        return $batch->only(['id', 'boundary', 'installation_id', 'provider', 'source_account', 'mapping_revision', 'code_revision', 'plan_revision', 'created_by', 'state']);
    }

    private function receipt(Operation $operation): array
    {
        return [...$operation->only(['id', 'batch_id', 'mapping_id', 'revision', 'state', 'actor_id', 'finished_by', 'error_code', 'replayed_from_id']),
            'reservation_expires_at' => $operation->reservation_expires_at->toISOString(),
            'target' => $operation->result_target_id === null ? null : ['type' => $operation->result_target_type, 'id' => $operation->result_target_id]];
    }
}
