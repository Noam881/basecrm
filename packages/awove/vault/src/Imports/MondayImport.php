<?php

namespace Awove\Vault\Imports;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Metadata;
use Awove\Core\Imports\Operation;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Vault\Access;
use Awove\Vault\AuditLog;
use Awove\Vault\Models\ImportEntry;
use Awove\Vault\Models\ImportWorkdoc;
use Awove\Vault\Models\Item;
use Awove\Vault\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One actual unlocked HTTP request, one exact frozen node, no queue or plaintext persistence. */
final class MondayImport
{
    public function __construct(private Ledger $ledger, private Records $records, private MondaySource $source) {}

    public function status(Request $request): array
    {
        $plan = MondayPlan::load();
        $this->context($request, $plan);
        $entries = $this->entries($plan)->get();
        $pending = $entries->firstWhere('state', 'pending');
        $expectedDocs = array_values(array_unique(array_merge(...array_map(fn ($node) => array_column($node['workdocs'], 'doc_id'), $plan->data['nodes'])), SORT_STRING));
        $acceptedDocs = ImportWorkdoc::where('installation_id', Installation::manifest()['id'])->where('main_plan_hash', $plan->hash)
            ->where('state', 'committed')->whereIn('doc_id', $expectedDocs)->distinct()->count('doc_id');

        return ['total' => count($plan->data['nodes']), 'accepted' => $entries->where('state', 'committed')->count(),
            'pending_request_key' => $pending && $pending->actor_id === $request->user()->id ? $pending->id : null,
            'binary_assets_pending' => count(array_unique(array_merge(...array_column($plan->data['nodes'], 'assets')))),
            'workdocs_pending' => max(0, count($expectedDocs) - $acceptedDocs),
            'source_configured' => app(MondayToken::class)->configured(), 'migration_complete' => false];
    }

    public function next(Request $request): array
    {
        abort_unless(DB::transactionLevel() === 0, 409);
        abort_unless(array_diff(array_keys($request->all()), ['request_key']) === [], 422);
        $requestKey = Metadata::uuid($request->input('request_key'));
        $plan = MondayPlan::load();
        $user = $this->context($request, $plan);
        // The committed metadata reservation survives disconnection, but holds no source values.
        $entry = DB::transaction(function () use ($request, $requestKey, $plan, $user): ?ImportEntry {
            $this->context($request, $plan);
            $batch = $this->batch($user, $plan);
            Batch::lockForUpdate()->findOrFail($batch['id']);
            $prior = ImportEntry::find($requestKey);
            if ($prior) {
                abort_unless($prior->installation_id === Installation::manifest()['id'] && $prior->plan_hash === $plan->hash && $prior->actor_id === $user->id, 409);

                return $prior;
            }
            abort_if($this->entries($plan)->where('state', 'pending')->exists(), 409);
            $accepted = $this->entries($plan)->where('state', 'committed')->pluck('node_id')->all();
            foreach ($plan->data['nodes'] as $node) {
                if (! in_array($node['id'], $accepted, true) && ($node['parent_id'] === null || in_array($node['parent_id'], $accepted, true))) {
                    $entry = ImportEntry::create(['id' => $requestKey, 'installation_id' => Installation::manifest()['id'],
                        'plan_hash' => $plan->hash, 'node_id' => $node['id'], 'actor_id' => $user->id, 'state' => 'pending']);
                    $this->context($request, $plan);

                    return $entry;
                }
            }

            return null;
        });
        if ($entry === null) {
            return ['state' => 'items-accepted', ...$this->status($request)];
        }
        $node = $this->node($plan, $entry->node_id);
        if ($entry->state === 'committed') {
            $this->verify($request, $plan, $entry);

            return ['state' => 'verified', 'request_key' => $entry->id, 'item_id' => $entry->item_id, 'replayed' => true, ...$this->status($request)];
        }
        abort_unless(app(MondayToken::class)->configured(), 503);
        $this->context($request, $plan);
        $source = $this->source->fetch($request, $plan, $node);
        $this->context($request, $plan);
        $payload = MondaySnapshot::payload($plan, $node, $source);
        $sourceFingerprint = $this->digest('source', [$plan->hash, $node, $source]);
        unset($source);
        try {
            $accepted = DB::transaction(function () use ($request, $plan, $entry, $node, $payload, $sourceFingerprint): ImportEntry {
                $user = $this->context($request, $plan);
                $batch = $this->batch($user, $plan);
                Batch::lockForUpdate()->findOrFail($batch['id']);
                $current = $this->entries($plan)->lockForUpdate()->findOrFail($entry->id);
                abort_unless($current->actor_id === $user->id && $current->node_id === $node['id'], 409);
                if ($current->state === 'committed') {
                    $this->verify($request, $plan, $current);

                    return $current;
                }
                $receipt = $this->ledger->claim($user, $plan->data['boundary'], $batch['id'], $this->sourceIdentity($node),
                    ['request_key' => $current->id, 'revision' => 1, 'source_fingerprint' => $sourceFingerprint, 'target_fingerprint' => null, 'previous_receipt_id' => null]);
                abort_unless($receipt['state'] === 'claimed', 409);
                $write = clone $request; // Same actual user, session and fixed grant; no forged authentication context.
                $company = $this->company($plan);
                $write->setJson(new InputBag(['customer_type' => 'companies', 'customer_id' => $company->id,
                    'title' => __('Imported secure record').' '.$node['id'], 'type' => 'note', 'level' => 1, 'payload' => $payload]));
                $item = $this->records->create($write);
                $readback = $this->read($request, $item);
                abort_unless($readback === $payload, 503);
                $fingerprint = $this->fingerprint($item, $readback);
                $finished = $this->ledger->finish($user, $plan->data['boundary'], $receipt['id'], $current->id,
                    ['state' => 'verified', 'target_type' => 'vault.item', 'target_id' => $item->id, 'result_fingerprint' => $fingerprint]);
                abort_unless($finished['state'] === 'verified', 409);
                $current->update(['state' => 'committed', 'operation_id' => $receipt['id'], 'item_id' => $item->id,
                    'source_fingerprint' => $sourceFingerprint, 'target_fingerprint' => $fingerprint]);
                $this->verify($request, $plan, $current->fresh());
                $this->context($request, $plan);
                $operation = Operation::findOrFail($receipt['id']);
                abort_unless($operation->reservation_expires_at->greaterThan(now()), 409);
                AuditLog::record($user, 'import.item', $item->id);
                $this->verify($request, $plan, $current->fresh());
                abort_unless(Operation::findOrFail($receipt['id'])->reservation_expires_at->greaterThan(now()), 409);

                return $current;
            });
        } finally {
            unset($payload);
        }

        return ['state' => 'verified', 'request_key' => $accepted->id, 'item_id' => $accepted->item_id, 'replayed' => false, ...$this->status($request)];
    }

    public function export(Request $request, string $id): StreamedResponse
    {
        $plan = MondayPlan::load();
        $entry = $this->entries($plan)->where('item_id', Metadata::uuid($id))->where('state', 'committed')->firstOrFail();
        $payload = $this->verify($request, $plan, $entry);
        $plain = MondaySnapshot::export($payload);
        unset($payload);

        return response()->streamDownload(function () use (&$plain, $request, $plan, $entry): void {
            try {
                $this->verify($request, $plan, $entry->fresh());
                echo $plain;
            } finally {
                sodium_memzero($plain);
            }
        }, 'original-source-export.txt', ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer']);
    }

    public function verify(Request $request, MondayPlan $plan, ImportEntry $entry): array
    {
        $this->context($request, $plan);
        abort_unless($entry->installation_id === Installation::manifest()['id'] && $entry->plan_hash === $plan->hash && $entry->state === 'committed', 409);
        $node = $this->node($plan, $entry->node_id);
        $item = $this->records->find($entry->item_id);
        abort_unless($item->contact_id === null && $item->company_id === $this->company($plan)->id && $item->level === 1, 409);
        $payload = $this->read($request, $item);
        $operation = Operation::findOrFail($entry->operation_id);
        $mapping = Mapping::findOrFail($operation->mapping_id);
        $original = json_decode(MondaySnapshot::export($payload), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $fingerprint = $this->fingerprint($item, $payload);
        abort_unless($mapping->boundary === $plan->data['boundary'] && $mapping->provider === 'monday' && $mapping->source_account === $plan->data['account_id']
            && $mapping->only(array_keys($this->sourceIdentity($node))) === $this->sourceIdentity($node) && $mapping->state === 'verified'
            && $mapping->target_type === 'vault.item' && $mapping->target_id === $item->id && $operation->state === 'verified' && $operation->batch_id === $plan->data['run_id']
            && $operation->actor_id === $entry->actor_id && $item->created_by === $entry->actor_id
            && $operation->result_target_type === 'vault.item' && $operation->result_target_id === $item->id
            && hash_equals($entry->source_fingerprint, $operation->source_fingerprint) && hash_equals($entry->target_fingerprint, $fingerprint)
            && hash_equals($operation->result_fingerprint, $fingerprint) && ($original['plan_hash'] ?? null) === $plan->hash
            && ($original['scope'] ?? null) === $node && ($original['source_account'] ?? null) === $plan->data['account_id']
            && hash_equals($entry->source_fingerprint, $this->digest('source', [$plan->hash, $node, $original['original']])), 409);
        $this->context($request, $plan);

        return $payload;
    }

    private function read(Request $request, Item $item): array
    {
        ImportWindow::require($request);
        Access::authorize($request->user(), 'read', $item);
        $payload = $this->records->decrypt($item);
        ImportWindow::require($request);
        Access::authorize($request->user(), 'read', $item->fresh());

        return $payload;
    }

    public function context(Request $request, MondayPlan $plan)
    {
        $user = Access::actor($request->user());
        abort_unless(config('app.debug') === false && $user->hasRole('Administrator') && $user->can('imports.manage') && $user->can('vault.admin') && Access::restricted($user), 403);
        ImportWindow::require($request);
        abort_unless(hash_equals($this->ledger->boundary($user), $plan->data['boundary']) && hash_equals(MondayPlan::load()->hash, $plan->hash), 409);
        $company = $this->company($plan);
        Access::authorize($user, 'create', parent: $company);
        Access::authorize($user, 'read', parent: $company);
        $batch = Batch::find($plan->data['run_id']);
        abort_unless($batch === null || $batch->state === 'open' && $batch->boundary === $plan->data['boundary'] && $batch->provider === 'monday'
            && $batch->source_account === $plan->data['account_id'] && $batch->code_revision === $plan->data['code_revision']
            && $batch->mapping_revision === $plan->data['mapping_revision'] && $batch->plan_revision === $plan->hash, 409);

        return $user;
    }

    private function company(MondayPlan $plan): Company
    {
        $mapping = Mapping::findOrFail($plan->data['company_mapping_id']);
        abort_unless($mapping->boundary === $plan->data['boundary'] && $mapping->provider === 'monday' && $mapping->source_account === $plan->data['account_id']
            && $mapping->state === 'verified' && $mapping->target_type === 'core.company' && is_string($mapping->target_id) && ctype_digit($mapping->target_id)
            && (string) (int) $mapping->target_id === $mapping->target_id && Operation::where('mapping_id', $mapping->id)->where('state', 'verified')
                ->where('result_target_type', 'core.company')->where('result_target_id', $mapping->target_id)->exists(), 409);

        return Company::findOrFail((int) $mapping->target_id);
    }

    private function batch($user, MondayPlan $plan): array
    {
        return $this->ledger->start($user, $plan->data['boundary'], ['batch_key' => $plan->data['run_id'], 'provider' => 'monday',
            'source_account' => $plan->data['account_id'], 'mapping_revision' => $plan->data['mapping_revision'],
            'code_revision' => $plan->data['code_revision'], 'plan_revision' => $plan->hash]);
    }

    private function entries(MondayPlan $plan)
    {
        return ImportEntry::where('installation_id', Installation::manifest()['id'])->where('plan_hash', $plan->hash);
    }

    private function node(MondayPlan $plan, string $id): array
    {
        foreach ($plan->data['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        abort(409);
    }

    private function sourceIdentity(array $node): array
    {
        return ['board_id' => $node['board_id'], 'entity_type' => 'item', 'entity_id' => $node['id'], 'target_role' => 'vault-item', 'relation_key' => 'internal-tier-one'];
    }

    private function fingerprint(Item $item, array $payload): string
    {
        return $this->digest('target', [$item->only(['id', 'installation_id', 'contact_id', 'company_id', 'title', 'type', 'level', 'created_by']), $payload]);
    }

    private function digest(string $purpose, array $data): string
    {
        return hash_hmac('sha256', 'vault-monday-import:v1:'.$purpose."\0".json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), app('encrypter')->getKey());
    }
}
