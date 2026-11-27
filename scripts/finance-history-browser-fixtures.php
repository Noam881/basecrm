<?php

use App\Models\User;
use Awove\Core\Imports\Ledger;
use Awove\Core\Models\Contact;
use Awove\Finance\History\HistoryCapability;
use Awove\Finance\History\Import;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/browser-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
if (($argv[1] ?? '') !== 'alpha' || ! preg_match('/^--project=(desktop|mobile)$/D', $argv[2] ?? '', $project)) {
    throw new RuntimeException('Choose only a reserved Alpha Finance history browser project.');
}
$project = $project[1];
$contact = [];
$cleanup = ($argv[3] ?? '') === '--cleanup' && count($argv) === 4;
if (! $cleanup && (count($argv) !== 5 || ! preg_match('/^--run='.preg_quote($project, '/').'-[0-9]{13}$/D', $argv[3] ?? '') || ! preg_match('/^--contact=([1-9][0-9]*)$/D', $argv[4] ?? '', $contact))) {
    throw new RuntimeException('Use an exact synthetic run/contact, or cleanup for that reserved project.');
}
$app->loadEnvironmentFrom('.env.alpha');
$app->make(Kernel::class)->bootstrap();
$result = DB::transaction(function () use ($project, $cleanup, $argv, $contact): array {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-browser-fixture-cleanup']);
    $plan = BrowserFixtures::plan('alpha', $project);
    $user = User::where('email', 'table-'.$project.'@alpha.example.test')->where('active', true)->whereNotNull('email_verified_at')->firstOrFail();
    if ($cleanup) {
        $batches = DB::table('crm_import_batches')->where('provider', 'browser-fixture')->where('created_by', $user->id)->get()
            ->filter(fn ($row) => preg_match('/^finance-history-'.preg_quote($project, '/').'-[0-9]{13}$/D', $row->source_account));
        $batchIds = $batches->pluck('id')->all();
        $mappings = DB::table('crm_import_mappings')->where('provider', 'browser-fixture')->whereIn('source_account', $batches->pluck('source_account'))->get();
        $mappingIds = $mappings->pluck('id')->all();
        $ops = DB::table('crm_import_operations')->whereIn('batch_id', $batchIds)->orderBy('id')->get();
        $opIds = $ops->pluck('id')->all();
        $records = DB::table('finance_history_records')->whereIn('operation_id', $opIds)->get();
        $recordIds = $records->pluck('id')->all();
        $entries = DB::table('finance_history_entries')->whereIn('record_id', $recordIds)->orderBy('id')->get();
        $entryIds = $entries->pluck('id')->all();
        $links = DB::table('finance_history_identities')->whereIn('record_id', $recordIds)->get();
        foreach ($records as $row) {
            if ($row->created_by !== $user->id || $row->owner_id !== $user->id || ! in_array($row->placement, ['linked', 'internal'], true)) {
                throw new RuntimeException('Protected Finance history ownership prevents cleanup.');
            }
        }
        foreach ($links as $link) {
            if (! in_array($link->source_mapping_id, $mappingIds, true) || $link->company_id !== null || ! in_array($link->contact_id, $plan['crm_contacts'], true)) {
                throw new RuntimeException('Protected Finance identity association prevents cleanup.');
            }
        }
        foreach ($entries as $entry) {
            if (! in_array($entry->operation_id, $opIds, true) || ! in_array($entry->source_mapping_id, $mappingIds, true)
                || ($entry->previous_entry_id !== null && ! in_array($entry->previous_entry_id, $entryIds, true)) || ($entry->parent_entry_id !== null && ! in_array($entry->parent_entry_id, $entryIds, true))) {
                throw new RuntimeException('Protected Finance source history prevents cleanup.');
            }
        }
        $target = function ($type, $id) use ($recordIds, $entryIds, $plan): bool {
            return $type === null || is_string($id) && (string) (int) $id === $id && in_array((int) $id, match ($type) {
                'core.contact' => $plan['crm_contacts'], 'finance.history-record' => $recordIds, 'finance.history-entry' => $entryIds, default => []
            }, true);
        };
        foreach ($mappings as $mapping) {
            if ($mapping->created_by !== $user->id || $mapping->board_id !== 'board-'.substr($mapping->source_account, strlen('finance-history-')) || ! $target($mapping->target_type, $mapping->target_id)) {
                throw new RuntimeException('Protected import mapping prevents Finance fixture cleanup.');
            }
        }
        foreach ($ops as $op) {
            if ($op->actor_id !== $user->id || ($op->finished_by !== null && $op->finished_by !== $user->id) || ! in_array($op->mapping_id, $mappingIds, true) || ! $target($op->result_target_type, $op->result_target_id)) {
                throw new RuntimeException('Protected import receipt prevents Finance fixture cleanup.');
            }
        }
        if (DB::table('crm_import_operations')->whereNotIn('id', $opIds)->where(fn ($q) => $q->whereIn('mapping_id', $mappingIds)->orWhereIn('previous_receipt_id', $opIds)->orWhereIn('replayed_from_id', $opIds))->exists()
            || DB::table('crm_import_mappings')->whereNotIn('id', $mappingIds)->where(fn ($q) => $q->where(fn ($q) => $q->where('target_type', 'finance.history-record')->whereIn('target_id', array_map('strval', $recordIds)))->orWhere(fn ($q) => $q->where('target_type', 'finance.history-entry')->whereIn('target_id', array_map('strval', $entryIds))))->exists()
            || DB::table('crm_import_operations')->whereNotIn('id', $opIds)->where(fn ($q) => $q->where(fn ($q) => $q->where('result_target_type', 'finance.history-record')->whereIn('result_target_id', array_map('strval', $recordIds)))->orWhere(fn ($q) => $q->where('result_target_type', 'finance.history-entry')->whereIn('result_target_id', array_map('strval', $entryIds))))->exists()
            || DB::table('finance_history_entries')->whereNotIn('id', $entryIds)->where(fn ($q) => $q->whereIn('previous_entry_id', $entryIds)->orWhereIn('parent_entry_id', $entryIds)->orWhereIn('source_mapping_id', $mappingIds)->orWhereIn('operation_id', $opIds))->exists()
            || DB::table('finance_history_identities')->whereNotIn('record_id', $recordIds)->whereIn('source_mapping_id', $mappingIds)->exists()
            || Schema::hasTable('crm_import_automation_holds') && DB::table('crm_import_automation_holds')->whereIn('batch_id', $batchIds)->exists()
            || Schema::hasTable('crm_import_document_transfers') && DB::table('crm_import_document_transfers')->whereIn('batch_id', $batchIds)->exists()) {
            throw new RuntimeException('Retained external references prevent Finance fixture cleanup.');
        }
        $tables = ['finance_history_entries' => $entryIds, 'finance_history_identities' => $links->pluck('id')->all(), 'finance_history_records' => $recordIds, 'crm_import_operations' => $opIds, 'crm_import_mappings' => $mappingIds, 'crm_import_batches' => $batchIds];
        $rows = [];
        foreach ($tables as $table => $ids) {
            $rows[$table] = DB::table($table)->whereIn('id', $ids)->orderBy('id')->get()->all();
        }
        if ($batchIds !== []) {
            $directory = storage_path('app/private/browser-fixture-recovery');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $path = $directory.'/finance-history-'.$project.'-'.bin2hex(random_bytes(6)).'.json';
            $mask = umask(0077);
            try {
                if (file_put_contents($path, json_encode(['installation' => 'alpha', 'rows' => $rows], JSON_THROW_ON_ERROR), LOCK_EX) === false || ! chmod($path, 0600)) {
                    throw new RuntimeException('Could not retain synthetic Finance fixture recovery.');
                }
            } finally {
                umask($mask);
            }
        }
        foreach ($tables as $table => $ids) {
            foreach (array_reverse($ids) as $id) {
                DB::table($table)->where('id', $id)->delete();
            }
        }

        return ['cleaned' => count($recordIds)];
    }
    $run = substr($argv[3], 6);
    $id = (int) $contact[1];
    if (! in_array($id, $plan['crm_contacts'], true)) {
        throw new RuntimeException('The synthetic contact is outside the reserved fixture plan.');
    }
    $identity = Contact::whereKey($id)->where('created_by', $user->id)->where('name', 'Shira Levi')->where('email', 'contact-'.$run.'@example.test')->firstOrFail();
    foreach (['imports.manage', ...(new HistoryCapability)->permissions()] as $permission) {
        if (! $user->can($permission)) {
            throw new RuntimeException('Explicit Finance fixture permissions are required.');
        }
    }
    $ledger = app(Ledger::class);
    $history = app(Import::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'browser-fixture', 'source_account' => 'finance-history-'.$run, 'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)]);
    $board = 'board-'.$run;
    $key = (string) Str::uuid();
    $parent = $ledger->claim($user, $boundary, $batch['id'], ['board_id' => $board, 'entity_type' => 'item', 'entity_id' => 'contact-'.$identity->id, 'target_role' => 'primary-contact', 'relation_key' => 'primary'], ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => hash_hmac('sha256', 'fictional:'.$run, app('encrypter')->getKey()), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $parent = $ledger->finish($user, $boundary, $parent['id'], $key, ['state' => 'verified', 'target_type' => 'core.contact', 'target_id' => (string) $identity->id, 'result_fingerprint' => hash_hmac('sha256', json_encode($identity->getAttributes(), JSON_THROW_ON_ERROR), app('encrypter')->getKey())]);
    $payload = ['label' => 'Carmel Studio historical income', 'facts' => [['key' => 'amount', 'label' => 'Original amount', 'value_kind' => 'money', 'availability' => 'present', 'raw_json' => '9007199254740993.12345678901234567890', 'unit' => null, 'currency' => 'ILS']], 'body_html' => '<p>Fictional financial reference.</p><script>window.__financeUnsafe=true</script>', 'author' => ['source_id' => 'legacy-77', 'name' => 'Yael Cohen'], 'source_created_at' => '2020-02-29T09:30:52.123456789+02:00', 'source_updated_at' => null];
    $write = $history->create($user, $boundary, $batch['id'], ['board_id' => $board, 'source_id' => 'income-1', 'kind' => 'income', 'owner_id' => $user->id, 'placement' => 'linked', 'associations' => [['mapping_id' => $parent['mapping_id'], 'source_board_id' => $board, 'source_item_id' => 'contact-'.$identity->id]], 'request_key' => (string) Str::uuid(), 'entry_request_key' => (string) Str::uuid()], $payload);
    if ($write['record_receipt']['state'] !== 'verified' || $write['entry_receipt']['state'] !== 'verified') {
        throw new RuntimeException('Fictional Finance reference was not verified.');
    }
    $history->append($user, $boundary, $batch['id'], $write['record_receipt']['mapping_id'], ['entry_kind' => 'update', 'source_id' => 'update-1', 'source_parent_id' => 'income-1', 'revision' => 1, 'request_key' => (string) Str::uuid(), 'previous_receipt_id' => null, 'reply_to_mapping_id' => null], [...$payload, 'label' => null, 'facts' => [], 'body_html' => '<p>'.str_repeat('Fictional financial history. ', 400).'Final reference line.</p>', 'author' => ['source_id' => null, 'name' => null], 'source_created_at' => null]);

    return ['record_id' => $write['record_id'], 'contact_id' => $identity->id];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
