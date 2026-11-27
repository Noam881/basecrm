<?php

namespace Awove\Marketing\Imports;

use Awove\Core\Imports\AutomationHolds;
use Awove\Core\Imports\Batch;
use Awove\Core\Imports\FieldConflict;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Metadata;
use Awove\Core\Imports\Operation;
use Awove\Core\Models\Lead;
use Awove\Marketing\Attributions;
use Awove\Marketing\LeadFields;
use Awove\Marketing\Models\Attribution;
use Awove\Marketing\Models\History;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Verified immutable originals feed only owning-module canonical deltas. No source I/O or Core attribution writes. */
class AttributionImporter
{
    public function __construct(private Originals $originals, private Sources $sources, private Ledger $ledger, private AutomationHolds $holds) {}

    /** Field input contains request/receipt metadata only; values come from the registered server converter. */
    public function apply($actor, string $boundary, string $batchId, int $historyId, array $fields): array
    {
        try {
            return DB::transaction(function () use ($actor, $boundary, $batchId, $historyId, $fields): array {
                $history = History::findOrFail($historyId);
                [$user, $batch, $parent, $lead] = $this->originals->context($actor, $boundary, $batchId, $history->details['parent_mapping_id']);
                abort_unless($lead instanceof Lead && $history->kind === 'source-lead' && $history->record_id === $lead->id, 409, 'Canonical attribution requires a verified Lead original.');
                $leadId = $lead->id;
                // Match native imports: the hold's advisory lock must precede any Lead row lock.
                $this->holds->placeNative($user, $boundary, $batchId, 'lead', $leadId);
                [$user, $batch, $parent, $lead] = $this->originals->context($user, $boundary, $batchId, $parent->id, true);
                abort_unless($lead instanceof Lead && $lead->id === $leadId, 409, 'Marketing import parent changed.');
                $payload = $this->originals->entry($user, 'source-lead', $lead->id, $history->id);
                $policy = $this->sources->policy($user, $batch, $parent, 'apply');
                $candidates = $policy->canonical($batch, $parent, $payload);
                abort_unless(array_diff(array_keys($candidates), array_keys(LeadFields::schema())) === [] && array_diff(array_keys($fields), array_keys($candidates)) === [], 422, 'Unsupported Marketing field.');
                $attribution = Attribution::where('lead_id', $lead->id)->lockForUpdate()->first();
                $expectedVersion = $attribution->version ?? 0;
                $plans = [];
                $patch = [];
                $statuses = [];
                $observed = [];
                ksort($fields);
                foreach ($fields as $key => $metadata) {
                    Metadata::exact($metadata, ['request_key', 'previous_receipt_id']);
                    Metadata::uuid($metadata['request_key']);
                    abort_unless($metadata['previous_receipt_id'] === null || is_int($metadata['previous_receipt_id']) && $metadata['previous_receipt_id'] > 0, 422);
                    $current = $this->current($attribution?->id, $key);
                    $currentHash = self::hash($key, $current);
                    $observed[$key] = $currentHash;
                    $candidate = $candidates[$key];
                    Metadata::exact($candidate, ['available', 'value']);
                    abort_unless(is_bool($candidate['available']) && ($candidate['available'] || $candidate['value'] === null), 422, 'Invalid Marketing conversion.');
                    // A server converter must distinguish unknown consent from an actual boolean/null.
                    if (! $candidate['available'] || $key === 'marketing_consent' && ! is_bool($candidate['value']) && $candidate['value'] !== null) {
                        $statuses[$key] = ['state' => 'unresolved'];

                        continue;
                    }
                    try {
                        $value = Attributions::canonicalPatch([$key => $candidate['value']])[$key];
                    } catch (ValidationException) {
                        $statuses[$key] = ['state' => 'unresolved'];

                        continue;
                    }
                    $source = ['board_id' => $parent->board_id, 'entity_type' => 'field', 'entity_id' => $parent->entity_id,
                        'target_role' => 'marketing-field', 'relation_key' => Originals::digest('field-role', [$parent->id, $key])];
                    $mapping = Mapping::where(['boundary' => $boundary, 'provider' => $batch->provider, 'source_account' => $batch->source_account, ...$source])->first();
                    $baseline = $mapping ? Operation::where('mapping_id', $mapping->id)->where('state', 'verified')->orderByDesc('revision')->first() : null;
                    if ($mapping?->target_id !== null) {
                        abort_unless($attribution && $mapping->target_type === 'marketing.attribution' && $mapping->target_id === (string) $attribution->id, 409, 'Marketing field target changed.');
                    }
                    $next = self::hash($key, ['present' => true, 'value' => $value]);
                    $prior = Operation::where('batch_id', $batchId)->where('request_key', $metadata['request_key'])->first();
                    if ($prior?->state === 'verified') {
                        abort_unless($mapping && $prior->mapping_id === $mapping->id && $prior->source_fingerprint === $next && $attribution
                            && $prior->result_target_type === 'marketing.attribution' && $prior->result_target_id === (string) $attribution->id, 409, 'Marketing field replay changed.');
                        $statuses[$key] = ['state' => $prior->result_fingerprint === $currentHash ? 'verified' : 'preserved_target_edit', 'receipt_id' => $prior->id];

                        continue;
                    }
                    if ($baseline) {
                        $decision = FieldConflict::decide(self::snapshot($baseline->source_fingerprint), self::snapshot($next), self::snapshot($baseline->result_fingerprint), self::snapshot($currentHash));
                        if ($decision === FieldConflict::UNCHANGED_SOURCE) {
                            $statuses[$key] = ['state' => 'unchanged_source', 'baseline_receipt_id' => $baseline->id];

                            continue;
                        }
                        abort_unless($metadata['previous_receipt_id'] === $baseline->id, 409, 'Marketing field baseline changed.');
                        $before = Batch::findOrFail($baseline->batch_id);
                        abort_unless($before->only(['mapping_revision', 'code_revision', 'plan_revision']) === $batch->only(['mapping_revision', 'code_revision', 'plan_revision']), 409, 'Marketing import revisions changed.');
                    } else {
                        abort_unless($metadata['previous_receipt_id'] === null, 409, 'Marketing baseline unavailable.');
                        $decision = $currentHash === $next ? FieldConflict::ALREADY_MATCHING : (! $current['present'] && ! isset($current['invalid_container']) ? FieldConflict::APPLY_SOURCE_CHANGE : FieldConflict::CONFLICT);
                    }
                    if (! in_array($decision, [FieldConflict::APPLY_SOURCE_CHANGE, FieldConflict::ALREADY_MATCHING], true)) {
                        $statuses[$key] = ['state' => 'conflict'];

                        continue;
                    }
                    $claim = ['request_key' => $metadata['request_key'], 'revision' => $baseline ? $baseline->revision + 1 : 1,
                        'source_fingerprint' => $next, 'target_fingerprint' => $attribution ? $currentHash : null, 'previous_receipt_id' => $baseline?->id];
                    $receipt = $this->ledger->claim($user, $boundary, $batchId, $source, $claim);
                    if ($receipt['state'] !== 'claimed' || $prior !== null) {
                        $statuses[$key] = ['state' => $receipt['state']];

                        continue;
                    }
                    $plans[$key] = ['receipt' => $receipt, 'request_key' => $metadata['request_key'], 'expected' => $next];
                    if ($decision === FieldConflict::APPLY_SOURCE_CHANGE) {
                        $patch[$key] = $value;
                    }
                }
                $this->verifyFields($lead->id, $attribution?->id, $observed);
                if ($patch !== []) {
                    $attribution = app(Attributions::class)->update($user, $lead->id, ['version' => $expectedVersion, 'fields' => $patch]);
                }
                foreach ($plans as $key => $plan) {
                    abort_unless($attribution && self::hash($key, $this->current($attribution->id, $key)) === $plan['expected'], 409, 'Marketing destination verification failed.');
                    $result = $this->ledger->finish($user, $boundary, $plan['receipt']['id'], $plan['request_key'], ['state' => 'verified', 'target_type' => 'marketing.attribution', 'target_id' => (string) $attribution->id, 'result_fingerprint' => $plan['expected']]);
                    abort_unless($result['state'] === 'verified', 409, 'Marketing field needs reconciliation.');
                    $statuses[$key] = ['state' => 'verified', 'receipt_id' => $result['id']];
                }
                $this->originals->context($user, $boundary, $batchId, $parent->id, true);
                $this->sources->policy($user, $batch, $parent, 'apply');
                $this->originals->entry($user, 'source-lead', $lead->id, $history->id);
                $expected = $observed;
                foreach ($plans as $key => $plan) {
                    $expected[$key] = $plan['expected'];
                }
                $this->verifyFields($lead->id, $attribution?->id, $expected);

                return ['fields' => $statuses, 'attribution_id' => $attribution?->id, 'original_history_id' => $history->id];
            });
        } catch (AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            throw new \RuntimeException('Marketing field import could not be applied safely.');
        }
    }

    /** Applied fields must retain their expected value; every skipped field retains its observed value. */
    private function verifyFields(int $leadId, ?int $attributionId, array $expected): void
    {
        $actual = Attribution::where('lead_id', $leadId)->first(['id']);
        abort_unless($actual?->id === $attributionId, 409, 'Marketing destination identity changed.');
        foreach ($expected as $key => $hash) {
            abort_unless(hash_equals($hash, self::hash($key, $this->current($attributionId, $key))), 409, 'Marketing field changed after receipt verification.');
        }
    }

    /** Read one JSON value without decoding/re-encoding large legacy numeric siblings. */
    private function current(?int $id, string $key): array
    {
        if ($id === null) {
            return ['present' => false];
        }
        $r = DB::table('marketing_attributions')->where('id', $id)->selectRaw('jsonb_typeof(payload) AS container, jsonb_exists(payload, ?) AS present, jsonb_typeof(payload -> ?) AS type, (payload -> ?)::text AS value', [$key, $key, $key])->first();
        abort_unless($r !== null, 409, 'Marketing target unavailable.');
        if ($r->container !== null && $r->container !== 'object') {
            return ['present' => true, 'invalid_container' => true];
        }
        if (! $r->present) {
            return ['present' => false];
        }
        if (! in_array($r->type, ['string', 'boolean', 'null'], true)) {
            return ['present' => true, 'unsupported_type' => $r->type, 'raw_json' => $r->value];
        }

        return ['present' => true, 'value' => json_decode($r->value, true, 8, JSON_THROW_ON_ERROR)];
    }

    private static function hash(string $key, array $value): string
    {
        return Originals::digest('canonical-field', [$key, $value]);
    }

    private static function snapshot(string $hash): array
    {
        return ['available' => true, 'present' => true, 'value' => $hash];
    }
}
