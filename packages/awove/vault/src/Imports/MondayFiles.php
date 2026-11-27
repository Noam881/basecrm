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
use Awove\Vault\Files;
use Awove\Vault\Models\Attachment;
use Awove\Vault\Models\ImportEntry;
use Awove\Vault\Models\ImportFileTransfer;
use Awove\Vault\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One frozen uploaded-file occurrence, actual grant, bounded RAM, durable ciphertext ownership. */
final class MondayFiles
{
    public function __construct(private MondayImport $items, private Files $files, private Ledger $ledger, private TransferStorage $storage) {}

    public function next(Request $request): array
    {
        abort_unless(DB::transactionLevel() === 0 && array_diff(array_keys($request->all()), ['request_key']) === [], 409);
        $key = Metadata::uuid($request->input('request_key'));
        $plan = MondayPlan::load();
        $this->items->context($request, $plan);
        $jobs = $this->jobs($plan);
        if (! ImportEntry::where('installation_id', Installation::manifest()['id'])->where('plan_hash', $plan->hash)->where('state', 'committed')->exists()) {
            return ['state' => 'awaiting-items', ...$this->progress($request, $plan)];
        }
        $transfer = DB::transaction(function () use ($request, $plan, $jobs, $key): ?ImportFileTransfer {
            $this->items->context($request, $plan);
            Batch::lockForUpdate()->findOrFail($plan->data['run_id']);
            $prior = ImportFileTransfer::where('request_key', $key)->first();
            if ($prior) {
                abort_unless($prior->installation_id === Installation::manifest()['id'] && $prior->plan_hash === $plan->hash && $prior->actor_id === $request->user()->id, 409);

                return $prior;
            }
            abort_if($this->transfers($plan)->whereIn('state', ['pending', 'reserved', 'cleaned'])->exists(), 409);
            foreach ($jobs as [$node, $occurrence]) {
                $entry = $this->entry($plan, $node['id']);
                if ($entry === null) {
                    continue;
                }
                if ($this->transfers($plan)->where('entry_id', $entry->id)->where('column_id', $occurrence['column_id'])->where('source_index', $occurrence['index'])->exists()) {
                    continue;
                }
                $item = app(Records::class)->find($entry->item_id);
                $id = (string) Str::uuid();
                $transfer = ImportFileTransfer::create(['id' => $id, 'request_key' => $key, 'installation_id' => Installation::manifest()['id'], 'plan_hash' => $plan->hash,
                    'entry_id' => $entry->id, 'asset_id' => $occurrence['id'], 'column_id' => $occurrence['column_id'], 'source_index' => $occurrence['index'],
                    'actor_id' => $request->user()->id, 'item_id' => $item->id, 'file_id' => (string) Str::uuid(), 'private_path' => $this->storage->path($id),
                    'storage_boundary' => $this->storage->boundary(), 'item_version' => $item->version, 'state' => 'pending']);
                $this->guard($request, $plan, $transfer);

                return $transfer;
            }

            return null;
        });
        if ($transfer === null) {
            $progress = $this->progress($request, $plan);

            return ['state' => $progress['files_accepted'] === $progress['file_total'] ? 'files-accepted' : 'awaiting-items', ...$progress];
        }
        if ($transfer->state === 'committed') {
            $this->verify($request, $plan, $transfer);

            return ['state' => 'verified', 'request_key' => $key, 'transfer_id' => $transfer->id, 'replayed' => true, ...$this->progress($request, $plan)];
        }
        if ($transfer->state !== 'pending') {
            return ['state' => 'reconciliation-required', 'transfer_id' => $transfer->id, ...$this->progress($request, $plan)];
        }
        $context = $this->guard($request, $plan, $transfer);
        $source = app(MondayAsset::class)->fetch($request, $plan, $context['node'], $context['occurrence'], $context['column'],
            fn () => $this->guard($request, $plan, $transfer));
        $plain = $source['plaintext'];
        unset($source['plaintext']);
        $metadata = $source['source'];
        unset($source);
        try {
            $info = $this->files->information($metadata['original_name'], $plain);
            $bytesHmac = $this->bytes($plain);
            $sourceHmac = $this->sourceHmac($plan, $context['node'], $context['occurrence'], $metadata, $info, $bytesHmac);
            // This reservation really commits before the first ciphertext filesystem write.
            $transfer = DB::transaction(function () use ($request, $plan, $transfer, $context, $info, $bytesHmac, $sourceHmac): ImportFileTransfer {
                $this->guard($request, $plan, $transfer);
                $current = $this->transfers($plan)->lockForUpdate()->findOrFail($transfer->id);
                abort_unless($current->state === 'pending', 409);
                app(Records::class)->version(app(Records::class)->find($current->item_id), $current->item_version);
                $receipt = $this->ledger->claim($request->user(), $plan->data['boundary'], $plan->data['run_id'], $this->identity($context['node'], $context['occurrence']),
                    ['request_key' => $current->request_key, 'revision' => 1, 'source_fingerprint' => $sourceHmac, 'target_fingerprint' => null, 'previous_receipt_id' => null]);
                abort_unless($receipt['state'] === 'claimed', 409);
                $current->update(['state' => 'reserved', 'operation_id' => $receipt['id'], 'size_bytes' => $info['size_bytes'], 'content_hmac' => $bytesHmac, 'source_hmac' => $sourceHmac]);
                $this->guard($request, $plan, $current);

                return $current;
            });

            // No retry or catch-delete: an uncertain COMMIT must retain its own journal and ciphertext.
            return DB::transaction(function () use ($request, $plan, $transfer, $plain, $metadata): array {
                $current = $this->writable($request, $transfer, $plain, $metadata);
                $this->files->uploadReserved($request, $current, $plain, $metadata);
                $file = Attachment::where('installation_id', Installation::manifest()['id'])->findOrFail($current->file_id);
                $target = $this->target($request, $current, $file);
                $receipt = $this->ledger->finish($request->user(), $plan->data['boundary'], $current->operation_id, $current->request_key,
                    ['state' => 'verified', 'target_type' => 'vault.file', 'target_id' => $file->id, 'result_fingerprint' => $target]);
                abort_unless($receipt['state'] === 'verified', 409);
                $current->update(['state' => 'committed', 'attachment_id' => $file->id, 'target_hmac' => $target]);
                $this->verify($request, $plan, $current->fresh());
                $operation = Operation::findOrFail($current->operation_id);
                abort_unless($operation->reservation_expires_at->greaterThan(now()), 409);
                AuditLog::record($request->user(), 'import.file', $current->item_id);
                $this->verify($request, $plan, $current->fresh());
                abort_unless(Operation::findOrFail($current->operation_id)->reservation_expires_at->greaterThan(now()), 409);

                return ['state' => 'verified', 'request_key' => $current->request_key, 'transfer_id' => $current->id, 'replayed' => false, ...$this->progress($request, $plan)];
            });
        } finally {
            sodium_memzero($plain);
            unset($metadata);
        }
    }

    public function writable(Request $request, ImportFileTransfer $transfer, #[\SensitiveParameter] string $plain, #[\SensitiveParameter] array $source): ImportFileTransfer
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $plan = MondayPlan::load();
        $context = $this->guard($request, $plan, $transfer);
        $current = $this->transfers($plan)->lockForUpdate()->findOrFail($transfer->id);
        $snapshot = Operation::findOrFail($current->operation_id);
        $mapping = Mapping::lockForUpdate()->findOrFail($snapshot->mapping_id);
        $operation = Operation::lockForUpdate()->findOrFail($snapshot->id);
        app(Records::class)->find($current->item_id)->newQuery()->lockForUpdate()->findOrFail($current->item_id);
        app(Records::class)->version(app(Records::class)->find($current->item_id), $current->item_version);
        $info = $this->files->information($source['original_name'], $plain);
        abort_unless($current->actor_id === $request->user()->id && $current->state === 'reserved' && $operation->state === 'claimed'
            && $operation->actor_id === $current->actor_id && $operation->request_key === $current->request_key && $operation->reservation_expires_at->greaterThan(now())
            && $operation->batch_id === $plan->data['run_id'] && $mapping->boundary === $plan->data['boundary'] && $mapping->provider === 'monday'
            && $mapping->source_account === $plan->data['account_id']
            && $mapping->only(array_keys($this->identity($context['node'], $context['occurrence']))) === $this->identity($context['node'], $context['occurrence'])
            && ! Attachment::where('private_path', $current->private_path)->exists() && ! $this->storage->exists($current)
            && $current->size_bytes === $info['size_bytes'] && hash_equals($current->content_hmac, $this->bytes($plain))
            && hash_equals($current->source_hmac, $operation->source_fingerprint)
            && hash_equals($current->source_hmac, $this->sourceHmac($plan, $context['node'], $context['occurrence'], $source, $info, $current->content_hmac)), 409);

        return $current;
    }

    public function progress(Request $request, MondayPlan $plan): array
    {
        $this->items->context($request, $plan);
        $pending = $this->transfers($plan)->whereIn('state', ['pending', 'reserved'])->first();
        $ready = true;
        foreach ($plan->data['nodes'] as $node) {
            $ready = $ready && ($node['assets'] === [] || array_key_exists('asset_occurrences', $node));
        }
        $total = $ready ? count($this->jobs($plan)) : count(array_unique(array_merge(...array_column($plan->data['nodes'], 'assets'))));
        $accepted = $this->transfers($plan)->where('state', 'committed')->count();
        $eligible = 0;
        if ($ready) {
            foreach ($this->jobs($plan) as [$node, $occurrence]) {
                $entry = $this->entry($plan, $node['id']);
                if ($entry === null) {
                    continue;
                }
                $existing = $this->transfers($plan)->where('entry_id', $entry->id)->where('column_id', $occurrence['column_id'])->where('source_index', $occurrence['index'])->first();
                if ($existing === null || $existing->state === 'pending') {
                    $eligible++;
                }
            }
        }

        return ['file_total' => $total, 'files_accepted' => $accepted, 'files_eligible' => $eligible, 'file_scope_ready' => $ready, 'binary_assets_pending' => max(0, $total - $accepted),
            'pending_file_request_key' => $pending && $pending->actor_id === $request->user()->id ? $pending->request_key : null, 'migration_complete' => false];
    }

    public function reconcile(Request $request, string $id): array
    {
        abort_unless(DB::transactionLevel() === 0 && array_diff(array_keys($request->all()), ['cleanup']) === [], 409);
        $data = $request->validate(['cleanup' => 'sometimes|boolean']);
        $plan = MondayPlan::load();

        return DB::transaction(function () use ($request, $plan, $id, $data): array {
            $transfer = $this->transfers($plan)->lockForUpdate()->findOrFail(Metadata::uuid($id));
            $this->guard($request, $plan, $transfer);
            if ($transfer->state === 'committed') {
                $this->verify($request, $plan, $transfer);

                return ['state' => 'committed', 'can_cleanup' => false];
            }
            abort_unless(! Attachment::where('private_path', $transfer->private_path)->exists(), 409);
            $exists = $this->storage->exists($transfer);
            if (! ($data['cleanup'] ?? false) || $transfer->state === 'cleaned') {
                return ['state' => $transfer->state, 'exists' => $exists, 'can_cleanup' => $transfer->state === 'reserved' && $transfer->actor_id === $request->user()->id];
            }
            abort_unless($transfer->actor_id === $request->user()->id && $transfer->state === 'reserved', 403);
            $snapshot = Operation::findOrFail($transfer->operation_id);
            Mapping::lockForUpdate()->findOrFail($snapshot->mapping_id);
            $operation = Operation::lockForUpdate()->findOrFail($snapshot->id);
            abort_unless($operation->state !== 'verified', 409);
            if ($operation->state === 'claimed') {
                $this->ledger->finish($request->user(), $plan->data['boundary'], $operation->id, $transfer->request_key, ['state' => 'failed', 'error_code' => 'operation_failed']);
            }
            $this->guard($request, $plan, $transfer);
            $this->storage->cleanup($transfer);
            $transfer->update(['state' => 'cleaned', 'reconciled_by' => $request->user()->id, 'reconciled_at' => now()]);
            $this->guard($request, $plan, $transfer);

            return ['state' => 'cleaned', 'can_cleanup' => false, 'ledger_quarantine_retained' => true];
        });
    }

    private function verify(Request $request, MondayPlan $plan, ImportFileTransfer $transfer): void
    {
        $context = $this->guard($request, $plan, $transfer);
        $file = Attachment::where('installation_id', Installation::manifest()['id'])->findOrFail($transfer->attachment_id);
        $operation = Operation::findOrFail($transfer->operation_id);
        $mapping = Mapping::findOrFail($operation->mapping_id);
        abort_unless($transfer->state === 'committed' && $file->id === $transfer->file_id && $file->item_id === $transfer->item_id
            && $file->created_by === $transfer->actor_id
            && $operation->state === 'verified' && $operation->batch_id === $plan->data['run_id'] && $operation->actor_id === $transfer->actor_id && $operation->request_key === $transfer->request_key
            && $operation->result_target_type === 'vault.file' && $operation->result_target_id === $file->id
            && $mapping->boundary === $plan->data['boundary'] && $mapping->provider === 'monday' && $mapping->source_account === $plan->data['account_id']
            && $mapping->only(array_keys($this->identity($context['node'], $context['occurrence']))) === $this->identity($context['node'], $context['occurrence'])
            && $mapping->state === 'verified' && $mapping->target_type === 'vault.file' && $mapping->target_id === $file->id
            && hash_equals($transfer->source_hmac, $operation->source_fingerprint)
            && hash_equals($transfer->target_hmac, $operation->result_fingerprint) && hash_equals($transfer->target_hmac, $this->target($request, $transfer, $file)), 409);
        $this->guard($request, $plan, $transfer);
    }

    private function target(Request $request, ImportFileTransfer $transfer, Attachment $file): string
    {
        abort_unless($this->storage->current($transfer, $file), 409);
        $readback = $this->files->inspectStored($request, $file);
        $plain = $readback['plaintext'];
        unset($readback['plaintext']);
        try {
            $metadata = $readback['metadata'];
            $source = $metadata['source'] ?? null;
            abort_unless(is_array($source), 409);
            $info = $this->files->information($source['original_name'], $plain);
            $plan = MondayPlan::load();
            $context = $this->guard($request, $plan, $transfer);
            abort_unless($metadata['name'] === $info['name'] && $metadata['mime'] === $info['mime'] && $info['size_bytes'] === $transfer->size_bytes
                && hash_equals($transfer->content_hmac, $this->bytes($plain))
                && hash_equals($transfer->source_hmac, $this->sourceHmac($plan, $context['node'], $context['occurrence'], $source, $info, $transfer->content_hmac)), 409);

            return $this->digest('target', [$file->id, $file->item_id, $file->created_by, $file->size_bytes, $metadata, $transfer->content_hmac]);
        } finally {
            sodium_memzero($plain);
        }
    }

    private function guard(Request $request, MondayPlan $plan, ImportFileTransfer $transfer): array
    {
        $user = $this->items->context($request, $plan);
        abort_unless($transfer->installation_id === Installation::manifest()['id'] && $transfer->plan_hash === $plan->hash
            && hash_equals($transfer->storage_boundary, $this->storage->boundary()) && $transfer->private_path === $this->storage->path($transfer->id), 409);
        $entry = ImportEntry::where('installation_id', Installation::manifest()['id'])->where('plan_hash', $plan->hash)->where('state', 'committed')->findOrFail($transfer->entry_id);
        $payload = $this->items->verify($request, $plan, $entry);
        abort_unless($transfer->item_id === $entry->item_id, 409);
        $node = $this->node($plan, $entry->node_id);
        $matches = array_values(array_filter($node['asset_occurrences'] ?? [], fn ($file) => $file['id'] === $transfer->asset_id
            && $file['column_id'] === $transfer->column_id && $file['index'] === $transfer->source_index));
        abort_unless(count($matches) === 1, 409);
        $item = app(Records::class)->find($transfer->item_id);
        Access::authorize($user, 'files.upload', $item);
        Access::authorize($user, 'files.download', $item);
        $original = json_decode(MondaySnapshot::export($payload), true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)['original'];
        $columns = array_values(array_filter($original['column_values'], fn ($column) => $column['id'] === $transfer->column_id && $column['type'] === 'file'));
        abort_unless(count($columns) === 1, 409);
        ImportWindow::require($request);

        return ['node' => $node, 'occurrence' => $matches[0], 'column' => $columns[0]];
    }

    private function jobs(MondayPlan $plan): array
    {
        $jobs = [];
        foreach ($plan->data['nodes'] as $node) {
            abort_unless($node['assets'] === [] || array_key_exists('asset_occurrences', $node), 409);
            foreach ($node['asset_occurrences'] ?? [] as $occurrence) {
                $jobs[] = [$node, $occurrence];
            }
        }

        return $jobs;
    }

    private function entry(MondayPlan $plan, string $nodeId): ?ImportEntry
    {
        return ImportEntry::where('installation_id', Installation::manifest()['id'])->where('plan_hash', $plan->hash)->where('node_id', $nodeId)->where('state', 'committed')->first();
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

    private function transfers(MondayPlan $plan)
    {
        return ImportFileTransfer::where('installation_id', Installation::manifest()['id'])->where('plan_hash', $plan->hash);
    }

    private function identity(array $node, array $occurrence): array
    {
        return ['board_id' => $node['board_id'], 'entity_type' => 'asset', 'entity_id' => $occurrence['id'], 'target_role' => 'vault-file',
            'relation_key' => $this->digest('occurrence', [$node['id'], $occurrence])];
    }

    private function sourceHmac(MondayPlan $plan, array $node, array $occurrence, array $source, array $info, string $bytes): string
    {
        return $this->digest('source', [$plan->hash, $node['id'], $occurrence, $source, $info, $bytes]);
    }

    private function bytes(#[\SensitiveParameter] string $plain): string
    {
        return hash_hmac('sha256', "vault-monday-file-bytes:v1\0".$plain, app('encrypter')->getKey());
    }

    private function digest(string $purpose, array $data): string
    {
        return hash_hmac('sha256', 'vault-monday-file:v1:'.$purpose."\0".json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), app('encrypter')->getKey());
    }
}
