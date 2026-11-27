<?php

namespace Awove\Core\Imports;

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Modules;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Ordinary native writes only. No source I/O, source-user creation, raw staging or module-owned fields. */
class NativeRecords
{
    public function __construct(private Ledger $ledger, private NativeSources $sources, private AutomationHolds $holds) {}

    public function create($actor, string $boundary, string $batchId, string $kind, array $source, array $input): array
    {
        $source = Metadata::source($source);
        $input = $this->input($kind, $input);

        return $this->guarded($actor, $boundary, $batchId, function ($user, Batch $batch) use ($boundary, $kind, $source, $input): array {
            $this->sources->authorize($user, $batch, $source, $kind, 'create', array_keys($input['fields']), $this->policyContext($input));
            $this->lock($batch, $source);
            $mapping = $this->mapping($batch, $source);
            $target = $mapping?->target_id === null ? null : $this->target($user, $batch, $mapping, $kind);
            if ($target) {
                return $this->mutate($user, $boundary, $batch, $mapping, $kind, $source, $input, $target);
            }
            $unavailable = array_keys(array_filter($input['fields'], fn ($field) => ! $field['available']));
            if ($unavailable !== []) {
                return $this->result(null, null, 'unresolved', array_fill_keys($unavailable, ['state' => 'unresolved']));
            }
            abort_unless($kind !== 'leads' || isset($input['fields']['owner_id'], $input['fields']['source']), 422, 'Historical leads require an explicit owner and source.');
            $values = $this->values($user, $batch, $kind, $source, $input['fields']);
            $prepared = NativeValues::prepare($user, $kind, $values, null, $input['accept_duplicate'], $input['currency']);
            foreach ($input['fields'] as $field) {
                abort_unless($field['previous_receipt_id'] === null, 409, 'A new record cannot adopt an old field baseline.');
            }
            $claim = $this->entityClaim($input['request_key'], $kind, $source, null);
            $reserved = Operation::where('batch_id', $batch->id)->where('request_key', $input['request_key'])->first();
            $receipt = $this->ledger->claim($user, $boundary, $batch->id, $source, $claim);
            if ($receipt['state'] !== 'claimed' || $reserved !== null) {
                return $this->result(null, $receipt, $receipt['state'], []);
            }
            $plans = $this->plans($user, $batch, $kind, $source, $input['fields'], $prepared['canonical'], null);
            try {
                // Reservation clocks survive the separate business savepoint's rollback.
                $claims = DB::transaction(fn () => $this->claimFields($user, $boundary, $batch, $plans));

                return DB::transaction(function () use ($user, $boundary, $batch, $kind, $input, $prepared, $receipt, $plans, $claims): array {
                    $this->guard($user, $boundary, $batch->id);
                    if ($kind === 'leads') {
                        $target = app(Leads::class)->createHistorical($user, $prepared['data'], $prepared['dates']);
                        // The native event is after-commit: persistent hold exists before it is delivered.
                        $this->holds->placeNative($user, $boundary, $batch->id, 'lead', $target->id);
                    } else {
                        $target = app(Identities::class)->create($user, $kind, [...$prepared['data'], 'accept_duplicate' => $input['accept_duplicate']]);
                    }
                    $target->refresh();
                    $this->authorizeTarget($user, $target);
                    $this->guard($user, $boundary, $batch->id);
                    $finished = $this->ledger->finish($user, $boundary, $receipt['id'], $input['request_key'], ['state' => 'verified', 'target_type' => $this->type($kind), 'target_id' => (string) $target->id, 'result_fingerprint' => NativeValues::contextHash($kind, $target->id)]);
                    if ($finished['state'] !== 'verified') {
                        throw new NativeWriteRefused(null);
                    }
                    $fields = $this->finishFields($user, $boundary, $batch, $kind, $plans, $claims, $target);
                    $this->guardTarget($user, $boundary, $batch, $kind, $finished['mapping_id'], $target, $input);
                    $this->verifyFields($kind, $plans, $target);

                    return $this->result($target, $finished, 'verified', $fields);
                });
            } catch (NativeWriteRefused $refused) {
                $statuses = $this->refusedStatuses($user, $boundary, $batch, $plans, $refused->field);
                $root = $this->ledger->claim($user, $boundary, $batch->id, $source, $claim);

                return $this->result(null, $root, $root['state'], $statuses);
            }
        });
    }

    public function apply($actor, string $boundary, string $batchId, int $mappingId, array $input): array
    {
        return $this->guarded($actor, $boundary, $batchId, function ($user, Batch $batch) use ($boundary, $mappingId, $input): array {
            $mapping = Mapping::findOrFail($mappingId);
            $kind = $this->kind($mapping->target_type);
            $input = $this->input($kind, $input);
            $source = $mapping->only(['board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key']);
            $this->sources->authorize($user, $batch, $source, $kind, 'update', array_keys($input['fields']), $this->policyContext($input));
            $this->lock($batch, $source);
            $target = $this->target($user, $batch, $mapping, $kind);

            return $this->mutate($user, $boundary, $batch, $mapping, $kind, $source, $input, $target);
        });
    }

    /** Explicit identity consolidation only; opportunities can never be aliased into one another. */
    public function alias($actor, string $boundary, string $batchId, string $kind, array $source, array $canonical, string $requestKey): array
    {
        abort_unless(in_array($kind, ['contacts', 'companies'], true), 422, 'Only identities can use a reviewed canonical mapping.');
        $source = Metadata::source($source);
        $requestKey = Metadata::uuid($requestKey);

        return $this->guarded($actor, $boundary, $batchId, function ($user, Batch $batch) use ($boundary, $kind, $source, $canonical, $requestKey): array {
            $this->descriptor($canonical);
            $this->sources->authorize($user, $batch, $source, $kind, 'consolidate', [], ['canonical' => $canonical]);
            $this->lock($batch, $source);
            $target = $this->reference($user, $batch, $kind, $canonical, $source, 'canonical');
            Gate::forUser($user)->authorize('update', $target);
            $mapping = $this->mapping($batch, $source);
            if ($mapping?->target_id !== null) {
                $this->target($user, $batch, $mapping, $kind);
            }
            $claim = $this->entityClaim($requestKey, $kind, $source, $target);
            $reserved = Operation::where('batch_id', $batch->id)->where('request_key', $requestKey)->first();
            $receipt = $reserved?->state === 'verified' ? $this->replayEntity($user, $boundary, $reserved, $kind, $source, $target) : $this->ledger->claim($user, $boundary, $batch->id, $source, $claim);
            if ($receipt['state'] === 'claimed' && $reserved === null) {
                $receipt = $this->ledger->finish($user, $boundary, $receipt['id'], $requestKey, ['state' => 'verified', 'target_type' => $this->type($kind), 'target_id' => (string) $target->id, 'result_fingerprint' => NativeValues::contextHash($kind, $target->id)]);
            }
            $this->reference($user, $batch, $kind, $canonical, $source, 'canonical');
            Gate::forUser($user)->authorize('update', $target->fresh());
            $this->sources->authorize($this->actor($user), $batch, $source, $kind, 'consolidate', [], ['canonical' => $canonical]);

            return $this->result($receipt['state'] === 'verified' ? $target : null, $receipt, $receipt['state'], []);
        });
    }

    private function mutate($actor, string $boundary, Batch $batch, Mapping $mapping, string $kind, array $source, array $input, Contact|Company|Lead $initial): array
    {
        $dependencies = $this->holdDependencies($actor, $boundary, $batch, $initial);
        $class = $initial::class;
        $target = $class::lockForUpdate()->findOrFail($initial->id);
        $this->authorizeTarget($actor, $target);
        $this->dependencies($target, $dependencies);
        $unavailable = array_keys(array_filter($input['fields'], fn ($field) => ! $field['available']));
        if ($unavailable !== []) {
            return $this->result($target, null, 'unresolved', array_fill_keys($unavailable, ['state' => 'unresolved']));
        }
        $values = $this->values($actor, $batch, $kind, $source, $input['fields']);
        $prepared = NativeValues::prepare($actor, $kind, $values, $target, $input['accept_duplicate'], $input['currency']);
        $plans = $this->plans($actor, $batch, $kind, $source, $input['fields'], $prepared['canonical'], $target);
        $reserved = Operation::where('batch_id', $batch->id)->where('request_key', $input['request_key'])->first();
        $receipt = $reserved?->state === 'verified' ? $this->replayEntity($actor, $boundary, $reserved, $kind, $source, $target)
            : $this->ledger->claim($actor, $boundary, $batch->id, $source, $this->entityClaim($input['request_key'], $kind, $source, $target));
        if ($receipt['state'] !== 'verified') {
            return $this->result(null, $receipt, $receipt['state'], []);
        }
        $blocked = array_filter($plans, fn ($plan) => $plan['decision'] === 'conflict');
        if ($blocked !== []) {
            $results = [];
            foreach ($plans as $key => $plan) {
                $results[$key] = $plan['decision'] === 'conflict' ? $this->status($actor, $boundary, $batch, $plan, true) : ['state' => $plan['decision'] === 'skip' ? 'unchanged_source' : 'not_applied'];
            }
            $this->guardTarget($actor, $boundary, $batch, $kind, $mapping->id, $target, $input);
            $this->dependencies($target, $dependencies);
            $this->assertHolds($batch, $dependencies);
            $this->verifyFields($kind, $plans, $target, false);

            return $this->result($target, $receipt, 'conflict', $results);
        }
        try {
            $claims = DB::transaction(fn () => $this->claimFields($actor, $boundary, $batch, $plans));
            $result = DB::transaction(function () use ($actor, $boundary, $batch, $mapping, $kind, $input, $target, $plans, $values, $receipt, $dependencies, $claims): array {
                $apply = array_keys(array_filter($plans, fn ($plan) => $plan['decision'] === 'apply'));
                if ($apply !== []) {
                    $next = NativeValues::prepare($actor, $kind, array_intersect_key($values, array_flip($apply)), $target, $input['accept_duplicate'], $input['currency']);
                    $this->guardTarget($actor, $boundary, $batch, $kind, $mapping->id, $target, $input);
                    $target = $kind === 'leads'
                        ? app(Leads::class)->updateHistorical($actor, $target->id, [...$next['data'], 'version' => $target->getAttribute('version')], $next['dates'])
                        : app(Identities::class)->update($actor, $kind, $target->id, [...$next['data'], 'accept_duplicate' => $input['accept_duplicate']]);
                }
                $target->refresh();
                $fields = $this->finishFields($actor, $boundary, $batch, $kind, $plans, $claims, $target);
                $this->guardTarget($actor, $boundary, $batch, $kind, $mapping->id, $target, $input);
                $this->dependencies($target, $dependencies);
                $this->assertHolds($batch, $dependencies);
                $this->verifyFields($kind, $plans, $target);

                return $this->result($target, $receipt, 'verified', $fields);
            });
        } catch (NativeWriteRefused $refused) {
            $result = $this->result($target->fresh(), $receipt, 'not_applied', $this->refusedStatuses($actor, $boundary, $batch, $plans, $refused->field));
        }
        $this->guardTarget($actor, $boundary, $batch, $kind, $mapping->id, $target->fresh(), $input);
        $this->dependencies($target->fresh(), $dependencies);
        $this->assertHolds($batch, $dependencies);
        $this->verifyFields($kind, $plans, $target, $result['state'] === 'verified');

        return $result;
    }

    private function plans($actor, Batch $batch, string $kind, array $source, array $fields, array $canonical, Contact|Company|Lead|null $target): array
    {
        $plans = [];
        ksort($fields);
        foreach ($fields as $key => $field) {
            $fieldSource = ['board_id' => $source['board_id'], 'entity_type' => 'field', 'entity_id' => $source['entity_id'], 'target_role' => 'native-field', 'relation_key' => NativeValues::digest('field-role', [$source['target_role'], $source['relation_key'], $key])];
            $mapping = $this->mapping($batch, $fieldSource);
            $baseline = $mapping ? Operation::where('mapping_id', $mapping->id)->where('state', 'verified')->orderByDesc('revision')->orderByDesc('id')->first() : null;
            $reserved = Operation::where('batch_id', $batch->id)->where('request_key', $field['request_key'])->first();
            $next = NativeValues::fieldHash($kind, $key, ['present' => true, 'value' => $canonical[$key]]);
            $current = $target ? NativeValues::fieldHash($kind, $key, NativeValues::read($target, $key)) : null;
            if ($mapping?->target_id !== null) {
                abort_unless($target && $mapping->target_type === $this->type($kind) && $mapping->target_id === (string) $target->id, 409, 'Native field target changed.');
            }
            if ($reserved?->state === 'verified') {
                abort_unless($mapping && $reserved->mapping_id === $mapping->id && $reserved->source_fingerprint === $next && $target
                    && $reserved->result_target_type === $this->type($kind) && $reserved->result_target_id === (string) $target->id, 409, 'Native field retry conflicts with retained history.');
                $decision = $reserved->result_fingerprint === $current ? 'replay' : 'skip';
                $baseline = $reserved;
            } elseif ($baseline) {
                $decision = FieldConflict::decide($this->snapshot($baseline->source_fingerprint), $this->snapshot($next), $this->snapshot($baseline->result_fingerprint), $this->snapshot($current));
                if ($decision === FieldConflict::UNCHANGED_SOURCE) {
                    $decision = 'skip';
                } else {
                    abort_unless($field['previous_receipt_id'] === $baseline->id && $this->sameRevisions($batch, Batch::findOrFail($baseline->batch_id)), 409, 'The reviewed native field baseline changed.');
                    $decision = $decision === FieldConflict::APPLY_SOURCE_CHANGE ? 'apply' : 'conflict';
                }
            } else {
                abort_unless($field['previous_receipt_id'] === null, 409, 'The native field baseline is unavailable.');
                $decision = $target === null ? 'apply' : ($next === $current ? 'match' : 'conflict');
            }
            $revision = 1;
            $previous = null;
            if ($baseline !== null) {
                $retained = in_array($decision, ['skip', 'replay'], true);
                $revision = $retained ? $baseline->revision : $baseline->revision + 1;
                $previous = $retained ? $baseline->previous_receipt_id : $baseline->id;
            }
            $plans[$key] = ['key' => $key, 'source' => $fieldSource, 'decision' => $decision, 'reserved' => $reserved?->id,
                'baseline' => $baseline?->id, 'claim' => ['request_key' => $field['request_key'], 'revision' => $revision,
                    'source_fingerprint' => $next, 'target_fingerprint' => $current, 'previous_receipt_id' => $previous]];
        }

        return $plans;
    }

    private function claimFields($actor, string $boundary, Batch $batch, array $plans): array
    {
        $claims = [];
        foreach ($plans as $key => $plan) {
            if (in_array($plan['decision'], ['skip', 'replay'], true)) {
                continue;
            }
            $claims[$key] = $this->ledger->claim($actor, $boundary, $batch->id, $plan['source'], $plan['claim']);
            if ($claims[$key]['state'] !== 'claimed' || $plan['reserved'] !== null) {
                throw new NativeWriteRefused($key);
            }
        }

        return $claims;
    }

    private function finishFields($actor, string $boundary, Batch $batch, string $kind, array $plans, array $claims, Contact|Company|Lead $target): array
    {
        $results = [];
        foreach ($plans as $key => $plan) {
            if ($plan['decision'] === 'skip') {
                $results[$key] = ['state' => 'unchanged_source', 'baseline_receipt_id' => $plan['baseline']];

                continue;
            }
            $actual = NativeValues::fieldHash($kind, $key, NativeValues::read($target->fresh(), $key));
            abort_unless(hash_equals($plan['claim']['source_fingerprint'], $actual), 409, 'Native readback differs from the reviewed destination representation.');
            $operationId = $plan['decision'] === 'replay' ? Operation::findOrFail($plan['reserved'])->id : $claims[$key]['id'];
            $receipt = $this->ledger->finish($actor, $boundary, $operationId, $plan['claim']['request_key'], ['state' => 'verified', 'target_type' => $this->type($kind), 'target_id' => (string) $target->id, 'result_fingerprint' => $actual]);
            if ($receipt['state'] !== 'verified') {
                throw new NativeWriteRefused($key);
            }
            $results[$key] = ['state' => 'verified', 'receipt_id' => $receipt['id'], 'mapping_id' => $receipt['mapping_id']];
        }

        return $results;
    }

    private function verifyFields(string $kind, array $plans, Contact|Company|Lead $target, bool $applied = true): void
    {
        $target = $target->fresh();
        foreach ($plans as $key => $plan) {
            $expected = $applied && $plan['decision'] !== 'skip' ? $plan['claim']['source_fingerprint'] : $plan['claim']['target_fingerprint'];
            abort_unless($expected !== null && hash_equals($expected, NativeValues::fieldHash($kind, $key, NativeValues::read($target, $key))), 409, 'Native field changed after receipt verification.');
        }
    }

    private function status($actor, string $boundary, Batch $batch, array $plan, bool $conflict = false): array
    {
        $receipt = $this->ledger->claim($actor, $boundary, $batch->id, $plan['source'], $plan['claim']);
        if ($conflict && $receipt['state'] === 'claimed' && $plan['reserved'] === null) {
            $receipt = $this->ledger->finish($actor, $boundary, $receipt['id'], $plan['claim']['request_key'], ['state' => 'conflict', 'error_code' => 'unresolved_dependency']);
        }

        return ['state' => $receipt['state'], 'receipt_id' => $receipt['id'] ?? null, 'mapping_id' => $receipt['mapping_id']];
    }

    /** No new reservations for unrelated fields after rollback; expire only clocks already retained. */
    private function refusedStatuses($actor, string $boundary, Batch $batch, array $plans, ?string $failed): array
    {
        $results = [];
        foreach ($plans as $key => $plan) {
            if (in_array($plan['decision'], ['skip', 'replay'], true)) {
                continue;
            }
            if ($key === $failed || Operation::where('batch_id', $batch->id)->where('request_key', $plan['claim']['request_key'])->exists()) {
                $results[$key] = $this->status($actor, $boundary, $batch, $plan);
            }
        }

        return $results;
    }

    private function values($actor, Batch $batch, string $kind, array $source, array $fields): array
    {
        $values = [];
        foreach ($fields as $key => $field) {
            $value = $field['value'];
            if (in_array($key, ['contact_id', 'company_id', 'owner_id'], true)) {
                if ($key === 'owner_id' && $value === 'actor') {
                    $value = $actor->id;
                } elseif ($value !== null) {
                    abort_unless(is_array($value), 422, 'Native relationships require verified source mappings.');
                    $referenceKind = match ($key) {
                        'contact_id' => 'contacts', 'company_id' => 'companies', default => 'users'
                    };
                    $value = $this->reference($actor, $batch, $referenceKind, $value, $source, $key)->id;
                }
                if ($key === 'owner_id') {
                    $this->owner($actor, $value);
                }
            }
            $values[$key] = $value;
        }

        return $values;
    }

    private function reference($actor, Batch $batch, string $kind, array $descriptor, array $origin, string $field): Contact|Company|User
    {
        $this->descriptor($descriptor);
        $mapping = Mapping::findOrFail($descriptor['mapping_id']);
        abort_unless($mapping->board_id === $descriptor['board_id'] && $mapping->entity_id === $descriptor['entity_id'], 409, 'Native source reference changed.');
        $this->sources->authorize($actor, $batch, $mapping->only(['board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key']), $kind, 'reference', [], ['origin' => $origin, 'field' => $field, 'descriptor' => $descriptor]);
        $this->mapped($batch, $mapping, $kind);
        $class = match ($kind) {
            'contacts' => Contact::class, 'companies' => Company::class, 'users' => User::class, default => abort(422, 'Unsupported native reference.')
        };
        $record = $class::findOrFail((int) $mapping->target_id);
        if ($record instanceof User) {
            $this->owner($actor, $record->id);
        } else {
            Gate::forUser($actor)->authorize('view', $record);
        }

        return $record;
    }

    private function target($actor, Batch $batch, Mapping $mapping, string $kind): Contact|Company|Lead
    {
        $this->mapped($batch, $mapping, $kind);
        $class = match ($kind) {
            'contacts' => Contact::class, 'companies' => Company::class, 'leads' => Lead::class, default => abort(422, 'Unsupported native target.')
        };
        $target = $class::findOrFail((int) $mapping->target_id);
        $this->authorizeTarget($actor, $target);

        return $target;
    }

    private function mapped(Batch $batch, Mapping $mapping, string $kind): void
    {
        abort_unless($mapping->boundary === $batch->boundary && $mapping->provider === $batch->provider && $mapping->source_account === $batch->source_account
            && in_array($mapping->entity_type, $kind === 'users' ? ['user', 'account'] : ['item'], true) && $mapping->state === 'verified' && $mapping->target_type === $this->type($kind)
            && is_string($mapping->target_id) && ctype_digit($mapping->target_id) && (string) (int) $mapping->target_id === $mapping->target_id && (int) $mapping->target_id > 0
            && Operation::where('mapping_id', $mapping->id)->where('state', 'verified')->where('result_target_type', $this->type($kind))->where('result_target_id', $mapping->target_id)->where('result_fingerprint', NativeValues::contextHash($kind, (int) $mapping->target_id))->exists(), 409, 'A verified canonical native mapping is required.');
    }

    private function authorizeTarget($actor, Contact|Company|Lead $target): void
    {
        $actor = $this->actor($actor);
        Gate::forUser($actor)->authorize('update', $target);
        if ($target instanceof Lead) {
            $this->owner($actor, $target->owner_id);
            foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $key => $class) {
                if ($target->$key !== null) {
                    Gate::forUser($actor)->authorize('view', $class::findOrFail($target->$key));
                }
            }
            abort_unless($target->contact_id !== null || $target->company_id !== null, 422, 'A historical lead needs a reviewed identity association.');
        } elseif ($target instanceof Contact && $target->getAttribute('company_id') !== null) {
            Gate::forUser($actor)->authorize('view', Company::findOrFail($target->getAttribute('company_id')));
        }
    }

    private function owner($actor, ?int $id): void
    {
        abort_unless($id !== null && ($id === $actor->id || $actor->can('leads.assign')), 403);
        $owner = User::findOrFail($id);
        abort_unless($owner->active && $owner->email_verified_at !== null && ($owner->can('leads.view.own') || $owner->can('leads.view.all')), 403, 'The reviewed lead owner is unavailable.');
    }

    private function holdDependencies($actor, string $boundary, Batch $batch, Contact|Company|Lead $target): array
    {
        $ids = $this->dependencyIds($target);
        foreach ($ids as $id) {
            $this->holds->placeNative($actor, $boundary, $batch->id, 'lead', $id);
        }

        return $ids;
    }

    private function dependencyIds(Contact|Company|Lead $target): array
    {
        return $target instanceof Lead ? [$target->id] : Lead::where($target instanceof Contact ? 'contact_id' : 'company_id', $target->id)->orderBy('id')->pluck('id')->all();
    }

    private function dependencies(Contact|Company|Lead $target, array $ids): void
    {
        abort_unless($this->dependencyIds($target) === $ids, 409, 'Native import dependencies changed.');
    }

    private function assertHolds(Batch $batch, array $ids): void
    {
        abort_unless(AutomationHold::where('batch_id', $batch->id)->where('boundary', $batch->boundary)->where('module', 'core')->where('source', 'lead')->where('state', 'held')->whereIn('record_id', $ids)->count() === count($ids), 409, 'The native import automation hold changed.');
    }

    private function guardTarget($actor, string $boundary, Batch $batch, string $kind, int $mappingId, Contact|Company|Lead $target, array $input): void
    {
        $user = $this->guard($actor, $boundary, $batch->id);
        $mapping = Mapping::findOrFail($mappingId);
        $actual = $this->target($user, $batch, $mapping, $kind);
        abort_unless($actual->id === $target->id, 409, 'Native import target changed.');
        $source = $mapping->only(['board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key']);
        $this->sources->authorize($user, $batch, $source, $kind, 'verify', array_keys($input['fields']), $this->policyContext($input));
        NativeValues::assertCurrency($kind, $this->values($user, $batch, $kind, $source, $input['fields']), $input['currency']);
        if ($actual instanceof Lead) {
            $this->assertHolds($batch, [$actual->id]);
        }
    }

    private function policyContext(array $input): array
    {
        $references = [];
        foreach (['contact_id', 'company_id', 'owner_id'] as $key) {
            if (! isset($input['fields'][$key]) || ! $input['fields'][$key]['available']) {
                continue;
            }
            $value = $input['fields'][$key]['value'];
            if ($value !== null && ! ($key === 'owner_id' && $value === 'actor')) {
                abort_unless(is_array($value), 422, 'Native relationships require reviewed mapping descriptors.');
                $this->descriptor($value);
            }
            $references[$key] = $value;
        }

        return ['references' => $references, 'accept_duplicate' => $input['accept_duplicate'], 'currency' => $input['currency']];
    }

    private function descriptor(array $descriptor): void
    {
        Metadata::exact($descriptor, ['mapping_id', 'board_id', 'entity_id']);
        abort_unless(is_int($descriptor['mapping_id']) && $descriptor['mapping_id'] > 0, 422, 'Invalid native source reference.');
        Metadata::opaque($descriptor['board_id']);
        Metadata::opaque($descriptor['entity_id']);
    }

    private function guarded($actor, string $boundary, string $batchId, \Closure $work): array
    {
        $this->actor($actor);
        Metadata::digest($boundary);
        $batchId = Metadata::uuid($batchId);
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
            throw new \RuntimeException('The ordinary native import could not be applied safely.');
        }
    }

    private function guard($actor, string $boundary, string $batchId): User
    {
        $user = $this->actor($actor);
        abort_unless(hash_equals(Runtime::current()['boundary'], $boundary), 409, 'Import runtime boundary changed.');
        $batch = Batch::findOrFail($batchId);
        abort_unless($batch->boundary === $boundary && $batch->state === 'open', 409, 'Import batch is unavailable.');

        return $user;
    }

    private function actor($actor): User
    {
        $user = $actor?->fresh();
        abort_unless($user && $user->active && $user->email_verified_at !== null && $user->can('imports.manage') && app(Modules::class)->enabled('sales'), 403);

        return $user;
    }

    private function input(string $kind, array $input): array
    {
        Metadata::exact($input, ['request_key', 'fields', 'accept_duplicate', 'currency']);
        $input['request_key'] = Metadata::uuid($input['request_key']);
        abort_unless(is_array($input['fields']) && is_bool($input['accept_duplicate']) && ($input['currency'] === null || is_string($input['currency']) && preg_match('/^[A-Z]{3}$/D', $input['currency'])), 422, 'Invalid native import plan.');
        NativeValues::validateFields($kind, $input['fields']);
        $keys = [$input['request_key']];
        foreach ($input['fields'] as &$field) {
            $field['request_key'] = Metadata::uuid($field['request_key']);
            $keys[] = $field['request_key'];
        }
        unset($field);
        abort_unless(count($keys) === count(array_unique($keys)), 422, 'Native import requests must be distinct.');

        return $input;
    }

    private function entityClaim(string $key, string $kind, array $source, Contact|Company|Lead|null $target): array
    {
        return ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => NativeValues::digest('entity-source', [$kind, $source]), 'target_fingerprint' => $target ? NativeValues::contextHash($kind, $target->id) : null, 'previous_receipt_id' => null];
    }

    private function replayEntity($actor, string $boundary, Operation $operation, string $kind, array $source, Contact|Company|Lead $target): array
    {
        $mapping = Mapping::findOrFail($operation->mapping_id);
        abort_unless($mapping->only(array_keys($source)) === $source && $operation->source_fingerprint === NativeValues::digest('entity-source', [$kind, $source])
            && $operation->result_target_type === $this->type($kind) && $operation->result_target_id === (string) $target->id, 409, 'Native entity retry conflicts with retained history.');

        return $this->ledger->finish($actor, $boundary, $operation->id, $operation->request_key, ['state' => 'verified', 'target_type' => $this->type($kind), 'target_id' => (string) $target->id, 'result_fingerprint' => NativeValues::contextHash($kind, $target->id)]);
    }

    private function mapping(Batch $batch, array $source): ?Mapping
    {
        return Mapping::where(['boundary' => $batch->boundary, 'provider' => $batch->provider, 'source_account' => $batch->source_account, ...$source])->first();
    }

    private function lock(Batch $batch, array $source): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['core-native-import:'.NativeValues::digest('source-lock', [$batch->boundary, $batch->provider, $batch->source_account, $source])]);
    }

    private function snapshot(?string $hash): array
    {
        return $hash === null ? ['available' => false] : ['available' => true, 'present' => true, 'value' => $hash];
    }

    private function sameRevisions(Batch $first, Batch $second): bool
    {
        return $first->only(['mapping_revision', 'code_revision', 'plan_revision']) === $second->only(['mapping_revision', 'code_revision', 'plan_revision']);
    }

    private function type(string $kind): string
    {
        return match ($kind) {
            'contacts' => 'core.contact', 'companies' => 'core.company', 'leads' => 'core.lead', 'users' => 'core.user', default => abort(422, 'Unsupported ordinary native kind.')
        };
    }

    private function kind(?string $type): string
    {
        return match ($type) {
            'core.contact' => 'contacts', 'core.company' => 'companies', 'core.lead' => 'leads', default => abort(409, 'Unsupported native target mapping.')
        };
    }

    private function result(Contact|Company|Lead|null $target, ?array $receipt, string $state, array $fields): array
    {
        return ['state' => $state, 'target' => $target ? ['type' => $this->type($target instanceof Lead ? 'leads' : ($target instanceof Contact ? 'contacts' : 'companies')), 'id' => $target->id] : null, 'entity_receipt' => $receipt, 'fields' => $fields];
    }
}

/** @internal SQL savepoint rollback before reading actual reservation status. */
final class NativeWriteRefused extends \RuntimeException
{
    public function __construct(public readonly ?string $field)
    {
        parent::__construct('Native write reservation was not verified.');
    }
}
