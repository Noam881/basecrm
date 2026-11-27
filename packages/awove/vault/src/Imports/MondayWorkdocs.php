<?php

namespace Awove\Vault\Imports;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Metadata;
use Awove\Core\Imports\Operation;
use Awove\Core\Installation;
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

/** One complete workdoc per real unlocked request; no background source work or plaintext storage. */
final class MondayWorkdocs
{
    public function __construct(private MondayImport $main, private Ledger $ledger, private Records $records, private MondayWorkdocSource $source) {}

    public function status(Request $request): array
    {
        $plan = MondayWorkdocPlan::load();
        $this->context($request, $plan);
        $entries = $this->entries($plan)->get();
        $pending = $entries->firstWhere('state', 'pending');
        $accepted = $entries->where('state', 'committed')->pluck('doc_id')->all();
        $eligible = 0;
        foreach ($plan->data['documents'] as $document) {
            if (! in_array($document['source']['doc_id'], $accepted, true) && $this->parent($plan, $document) !== null) {
                $eligible++;
            }
        }

        return ['total' => count($plan->data['documents']), 'accepted' => count($accepted), 'eligible' => $eligible,
            'pending_request_key' => $pending && $pending->actor_id === $request->user()->id ? $pending->id : null,
            'source_configured' => app(MondayToken::class)->configured(), 'migration_complete' => false];
    }

    public function next(Request $request): array
    {
        abort_unless(DB::transactionLevel() === 0, 409);
        abort_unless(array_diff(array_keys($request->all()), ['request_key']) === [], 422);
        $key = Metadata::uuid($request->input('request_key'));
        $plan = MondayWorkdocPlan::load();
        $user = $this->context($request, $plan);
        $entry = DB::transaction(function () use ($request, $plan, $key, $user): ?ImportWorkdoc {
            $this->context($request, $plan);
            $batch = $this->batch($user, $plan);
            Batch::lockForUpdate()->findOrFail($batch['id']);
            $prior = ImportWorkdoc::find($key);
            if ($prior) {
                abort_unless($prior->installation_id === Installation::manifest()['id'] && $prior->plan_hash === $plan->hash && $prior->actor_id === $user->id, 409);

                return $prior;
            }
            abort_if($this->entries($plan)->where('state', 'pending')->exists(), 409);
            $accepted = $this->entries($plan)->where('state', 'committed')->pluck('doc_id')->all();
            foreach ($plan->data['documents'] as $document) {
                $parent = $this->parent($plan, $document);
                if (! in_array($document['source']['doc_id'], $accepted, true) && $parent !== null) {
                    $this->parentColumn($request, $plan, $document, $parent);
                    $entry = ImportWorkdoc::create(['id' => $key, 'installation_id' => Installation::manifest()['id'], 'plan_hash' => $plan->hash,
                        'main_plan_hash' => $plan->main->hash, 'doc_id' => $document['source']['doc_id'], 'parent_entry_id' => $parent->id,
                        'actor_id' => $user->id, 'state' => 'pending']);
                    $this->context($request, $plan);

                    return $entry;
                }
            }

            return null;
        });
        if ($entry === null) {
            $status = $this->status($request);

            return ['state' => $status['accepted'] === $status['total'] ? 'workdocs-accepted' : 'awaiting-items', ...$status];
        }
        if ($entry->state === 'committed') {
            $this->verify($request, $plan, $entry);

            return ['state' => 'verified', 'item_id' => $entry->item_id, 'request_key' => $entry->id, 'replayed' => true, ...$this->status($request)];
        }
        $document = $this->document($plan, $entry->doc_id);
        $parent = ImportEntry::findOrFail($entry->parent_entry_id);
        $authorize = function () use ($request, $plan, $document, $parent): void {
            $this->context($request, $plan);
            $this->parentColumn($request, $plan, $document, $parent->fresh());
        };
        $column = $this->parentColumn($request, $plan, $document, $parent);
        abort_unless(app(MondayToken::class)->configured(), 503);
        $original = $this->source->fetch($request, $plan, $document, $column, $authorize);
        $authorize();
        $payload = MondayWorkdocSnapshot::payload($plan, $document, $parent->id, $parent->item_id, $original);
        $sourceFingerprint = $this->digest('source', [$plan->hash, $document, $parent->id, $parent->item_id, $original]);
        unset($original, $column);
        try {
            $accepted = DB::transaction(function () use ($request, $plan, $entry, $document, $parent, $payload, $sourceFingerprint, $authorize): ImportWorkdoc {
                $user = $this->context($request, $plan);
                $authorize();
                $batch = $this->batch($user, $plan);
                Batch::lockForUpdate()->findOrFail($batch['id']);
                $current = $this->entries($plan)->lockForUpdate()->findOrFail($entry->id);
                abort_unless($current->actor_id === $user->id && $current->parent_entry_id === $parent->id && $current->doc_id === $document['source']['doc_id'], 409);
                if ($current->state === 'committed') {
                    $this->verify($request, $plan, $current);

                    return $current;
                }
                $receipt = $this->ledger->claim($user, $plan->main->data['boundary'], $batch['id'], $this->identity($document),
                    ['request_key' => $current->id, 'revision' => 1, 'source_fingerprint' => $sourceFingerprint, 'target_fingerprint' => null, 'previous_receipt_id' => null]);
                abort_unless($receipt['state'] === 'claimed', 409);
                $write = clone $request;
                $companyId = $this->records->find($parent->item_id)->company_id;
                $write->setJson(new InputBag(['customer_type' => 'companies', 'customer_id' => $companyId, 'title' => __('Imported protected workdoc').' '.$current->doc_id,
                    'type' => 'note', 'level' => 1, 'payload' => $payload]));
                $item = $this->records->create($write);
                $readback = $this->read($request, $item);
                abort_unless($readback === $payload, 503);
                $fingerprint = $this->fingerprint($item, $readback);
                $finished = $this->ledger->finish($user, $plan->main->data['boundary'], $receipt['id'], $current->id,
                    ['state' => 'verified', 'target_type' => 'vault.item', 'target_id' => $item->id, 'result_fingerprint' => $fingerprint]);
                abort_unless($finished['state'] === 'verified', 409);
                $current->update(['state' => 'committed', 'operation_id' => $receipt['id'], 'item_id' => $item->id,
                    'source_fingerprint' => $sourceFingerprint, 'target_fingerprint' => $fingerprint]);
                AuditLog::record($user, 'import.workdoc', $item->id);
                $this->verify($request, $plan, $current->fresh());
                abort_unless(Operation::findOrFail($receipt['id'])->reservation_expires_at->greaterThan(now()), 409);

                return $current;
            });
        } finally {
            unset($payload);
        }

        return ['state' => 'verified', 'item_id' => $accepted->item_id, 'request_key' => $accepted->id, 'replayed' => false, ...$this->status($request)];
    }

    public function export(Request $request, string $id): StreamedResponse
    {
        $plan = MondayWorkdocPlan::load();
        $this->context($request, $plan);
        $entry = $this->entries($plan)->where('item_id', Metadata::uuid($id))->where('state', 'committed')->firstOrFail();
        $plain = MondayWorkdocSnapshot::export($this->verify($request, $plan, $entry));

        return response()->streamDownload(function () use (&$plain, $request, $plan, $entry): void {
            try {
                $this->verify($request, $plan, $entry->fresh());
                echo $plain;
            } finally {
                sodium_memzero($plain);
            }
        }, 'original-workdoc-export.txt', ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function verify(Request $request, MondayWorkdocPlan $plan, ImportWorkdoc $entry): array
    {
        $this->context($request, $plan);
        abort_unless($entry->installation_id === Installation::manifest()['id'] && $entry->plan_hash === $plan->hash && $entry->main_plan_hash === $plan->main->hash && $entry->state === 'committed', 409);
        $document = $this->document($plan, $entry->doc_id);
        $parent = ImportEntry::findOrFail($entry->parent_entry_id);
        $this->parentColumn($request, $plan, $document, $parent);
        $item = $this->records->find($entry->item_id);
        abort_unless($item->contact_id === null && $item->company_id === $this->records->find($parent->item_id)->company_id && $item->level === 1, 409);
        $payload = $this->read($request, $item);
        $snapshot = json_decode(MondayWorkdocSnapshot::export($payload), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $fingerprint = $this->fingerprint($item, $payload);
        $operation = Operation::findOrFail($entry->operation_id);
        $mapping = Mapping::findOrFail($operation->mapping_id);
        abort_unless($mapping->boundary === $plan->main->data['boundary'] && $mapping->provider === 'monday' && $mapping->source_account === $plan->main->data['account_id']
            && $mapping->only(array_keys($this->identity($document))) === $this->identity($document) && $mapping->state === 'verified'
            && $mapping->target_type === 'vault.item' && $mapping->target_id === $item->id && $operation->state === 'verified' && $operation->batch_id === $plan->data['run_id']
            && $operation->actor_id === $entry->actor_id && $item->created_by === $entry->actor_id && $operation->result_target_type === 'vault.item' && $operation->result_target_id === $item->id
            && hash_equals($entry->target_fingerprint, $fingerprint) && hash_equals($operation->result_fingerprint, $fingerprint)
            && hash_equals($entry->source_fingerprint, $operation->source_fingerprint) && ($snapshot['plan_hash'] ?? null) === $plan->hash && ($snapshot['main_plan_hash'] ?? null) === $plan->main->hash
            && ($snapshot['source_account'] ?? null) === $plan->main->data['account_id'] && ($snapshot['scope'] ?? null) === $document
            && ($snapshot['parent_entry_id'] ?? null) === $parent->id && ($snapshot['parent_item_id'] ?? null) === $parent->item_id
            && is_array($snapshot['original'] ?? null) && hash_equals($entry->source_fingerprint,
                $this->digest('source', [$plan->hash, $document, $parent->id, $parent->item_id, $snapshot['original']])), 409);
        $this->context($request, $plan);
        $this->parentColumn($request, $plan, $document, $parent->fresh());

        return $payload;
    }

    private function parentColumn(Request $request, MondayWorkdocPlan $plan, array $document, ImportEntry $parent): array
    {
        abort_unless($parent->node_id === $document['source']['item_id'] && $parent->installation_id === Installation::manifest()['id'] && $parent->plan_hash === $plan->main->hash, 409);
        $snapshot = json_decode(MondaySnapshot::export($this->main->verify($request, $plan->main, $parent)), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $columns = array_values(array_filter($snapshot['original']['column_values'], fn ($column) => $column['id'] === $document['source']['column_id']));
        abort_unless(count($columns) === 1 && $columns[0]['type'] === 'file' && is_string($columns[0]['value']), 409);
        $value = json_decode($columns[0]['value'], true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        abort_unless(is_array($value) && is_array($value['files'] ?? null) && array_is_list($value['files']), 409);
        $matches = array_values(array_filter($value['files'], fn ($file) => is_array($file) && ($file['fileType'] ?? null) === 'MONDAY_DOC'
            && (is_string($file['objectId'] ?? null) || is_int($file['objectId'] ?? null)) && (string) $file['objectId'] === $document['source']['object_id']
            && is_string($file['fileId'] ?? null) && $file['fileId'] === $document['source']['file_id']));
        abort_unless(count($matches) === 1, 409);

        return $columns[0];
    }

    private function read(Request $request, Item $item): array
    {
        ImportWindow::require($request);
        $item = $this->records->find($item->id);
        Access::authorize($request->user(), 'read', $item);
        $payload = $this->records->decrypt($item);
        ImportWindow::require($request);
        Access::authorize($request->user(), 'read', $item->fresh());

        return $payload;
    }

    private function context(Request $request, MondayWorkdocPlan $plan)
    {
        $user = $this->main->context($request, $plan->main);
        abort_unless(hash_equals(MondayWorkdocPlan::load()->hash, $plan->hash), 409);
        $batch = Batch::find($plan->data['run_id']);
        abort_unless($batch === null || $batch->state === 'open' && $batch->boundary === $plan->main->data['boundary'] && $batch->provider === 'monday'
            && $batch->source_account === $plan->main->data['account_id'] && $batch->code_revision === $plan->data['code_revision']
            && $batch->mapping_revision === $plan->data['mapping_revision'] && $batch->plan_revision === $plan->hash, 409);

        return $user;
    }

    private function batch($user, MondayWorkdocPlan $plan): array
    {
        return $this->ledger->start($user, $plan->main->data['boundary'], ['batch_key' => $plan->data['run_id'], 'provider' => 'monday',
            'source_account' => $plan->main->data['account_id'], 'mapping_revision' => $plan->data['mapping_revision'],
            'code_revision' => $plan->data['code_revision'], 'plan_revision' => $plan->hash]);
    }

    private function parent(MondayWorkdocPlan $plan, array $document): ?ImportEntry
    {
        return ImportEntry::where('installation_id', Installation::manifest()['id'])->where('plan_hash', $plan->main->hash)
            ->where('node_id', $document['source']['item_id'])->where('state', 'committed')->first();
    }

    private function entries(MondayWorkdocPlan $plan)
    {
        return ImportWorkdoc::where('installation_id', Installation::manifest()['id'])->where('plan_hash', $plan->hash);
    }

    private function document(MondayWorkdocPlan $plan, string $id): array
    {
        foreach ($plan->data['documents'] as $document) {
            if ($document['source']['doc_id'] === $id) {
                return $document;
            }
        }
        abort(409);
    }

    private function identity(array $document): array
    {
        return ['board_id' => $document['source']['board_id'], 'entity_type' => 'document', 'entity_id' => $document['source']['doc_id'],
            'target_role' => 'vault-workdoc', 'relation_key' => hash('sha256', json_encode($document['source'], JSON_THROW_ON_ERROR))];
    }

    private function fingerprint(Item $item, array $payload): string
    {
        return $this->digest('target', [$item->only(['id', 'installation_id', 'contact_id', 'company_id', 'title', 'type', 'level', 'created_by']), $payload]);
    }

    private function digest(string $purpose, array $value): string
    {
        return hash_hmac('sha256', 'vault-monday-workdoc:v1:'.$purpose."\0".json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), app('encrypter')->getKey());
    }
}
