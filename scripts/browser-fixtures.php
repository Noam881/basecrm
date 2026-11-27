<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/fictional-fixtures.php';

/** Read-only identification of disposable local browser fixtures. */
final class BrowserFixtures
{
    /**
     * Recognize only explicit browser fixture labels and run-marker emails.
     * Timestamped labels without a matching email require explicit legacy mode.
     */
    public static function identityMatches(string $kind, string $name, ?string $email, ?string $project = null, bool $legacy = false): bool
    {
        self::assertProject($project);
        if (! in_array($kind, ['contacts', 'companies'], true)) {
            throw new InvalidArgumentException('Unknown browser fixture identity kind.');
        }
        $cleanNames = $kind === 'companies'
            ? ['Oren Solutions', 'Levi Solutions', 'אורן פתרונות', 'לוי פתרונות']
            : ['Uri Ben David', 'Shira Levi', 'Tamar Ben Ami', 'אורי בן דוד', 'שירה לוי'];
        $emailMarkers = $kind === 'companies'
            ? ['company-' => '@example.test', 'office.' => '@demo.example.test']
            : ['contact-' => '@example.test', 'changed-contact-' => '@example.test', 'uri.' => '@demo.example.test'];
        if ($kind === 'contacts' && $name === 'Tamar Ben Ami') {
            return preg_match('/^tamar\.stage\.(?<project>desktop|mobile)-[0-9]{13}@demo\.example\.test$/D', strtolower($email ?? ''), $match) === 1
                && ($project === null || $match['project'] === $project);
        }
        if (in_array($name, $cleanNames, true)) {
            foreach ($emailMarkers as $prefix => $domain) {
                $emailPattern = '/^'.preg_quote($prefix, '/').'(?<project>desktop|mobile)-[0-9]{13}'.preg_quote($domain, '/').'$/D';
                if (preg_match($emailPattern, strtolower($email ?? ''), $match) && ($project === null || $match['project'] === $project)) {
                    return true;
                }
            }
        }
        if (! $legacy) {
            return false;
        }
        $legacyPattern = $kind === 'companies'
            ? '/^(?:Company |אורן פתרונות — |Oren Solutions — |לוי פתרונות — |Levi Solutions — |חברה לבדיקת עריכה )(?<project>desktop|mobile)-[0-9]{13}$/uD'
            : '/^(?:Person |אורי בן דוד — |Uri Ben David — |שירה לוי — |Shira Levi — |איש קשר לבדיקת עריכה )(?<project>desktop|mobile)-[0-9]{13}$/uD';

        return preg_match($legacyPattern, $name, $match) === 1 && ($project === null || $match['project'] === $project);
    }

    /** @return array<string,list<int|string>> Exact disposable IDs; import batch UUIDs remain strings. */
    public static function plan(string $installation, ?string $project = null, bool $legacy = false): array
    {
        self::assertProject($project);
        FictionalCrmFixtures::assertLocalExample($installation);
        $connection = DB::connection();
        $database = 'base_'.$installation;
        $actual = $connection->selectOne('select current_database() as database_name, current_user as database_user');
        if (! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || (string) $connection->getConfig('port') !== '55438'
            || $connection->getConfig('database') !== $database
            || ! is_object($actual)
            || $actual->database_name !== $database
            || $actual->database_user !== $database) {
            throw new RuntimeException('Browser fixture planning requires the isolated local example database and role.');
        }
        $projects = $project === null ? ['desktop', 'mobile'] : [$project];
        $emails = array_map(fn ($size) => 'table-'.$size.'@'.$installation.'.example.test', $projects);
        if ($legacy && $project === null) {
            $emails[] = 'reviewer@'.$installation.'.example.test';
        }
        $userIds = User::whereIn('email', $emails)->pluck('id')->all();
        $identityIds = [];
        foreach (['contacts' => Contact::class, 'companies' => Company::class] as $kind => $model) {
            $identityIds[$kind] = $model::whereIn('created_by', $userIds)->orderBy('id')->get(['id', 'name', 'email'])->filter(fn ($record) => self::identityMatches($kind, $record->getAttribute('name'), $record->getAttribute('email'), $project, $legacy))->modelKeys();
        }
        $related = function ($query) use ($identityIds) {
            $query->whereIn('contact_id', $identityIds['contacts'])->orWhereIn('company_id', $identityIds['companies']);
        };
        $leadIds = Lead::where($related)->whereIn('created_by', $userIds)->whereColumn('owner_id', 'created_by')->where(fn ($query) => $query->whereNull('contact_id')->orWhereIn('contact_id', $identityIds['contacts']))->where(fn ($query) => $query->whereNull('company_id')->orWhereIn('company_id', $identityIds['companies']))->orderBy('id')->pluck('id')->all();
        if (Lead::where($related)->whereNotIn('id', $leadIds)->exists()) {
            throw new RuntimeException('A protected lead references a browser fixture. No cleanup is permitted.');
        }
        if (Contact::whereIn('company_id', $identityIds['companies'])->whereNotIn('id', $identityIds['contacts'])->exists()) {
            throw new RuntimeException('A protected contact references a browser fixture company. No cleanup is permitted.');
        }
        $taskIds = DB::table('crm_tasks')->whereIn('lead_id', $leadIds)->orderBy('id')->pluck('id')->all();
        $plan = ['crm_reminders' => DB::table('crm_reminders')->whereIn('task_id', $taskIds)->orderBy('id')->pluck('id')->all()];
        if (Schema::hasTable('service_details_leads')) {
            $plan['service_details_leads'] = DB::table('service_details_leads')->whereIn('lead_id', $leadIds)->orderBy('id')->pluck('id')->all();
        }
        foreach (['sales_communications' => 'staff_id', 'sales_quotations' => 'created_by'] as $table => $ownerColumn) {
            if (Schema::hasTable($table)) {
                $records = DB::table($table)->whereIn('lead_id', $leadIds);
                if ((clone $records)->whereNotIn($ownerColumn, $userIds)->exists()) {
                    throw new RuntimeException('A protected Sales history record references a browser fixture. No cleanup is permitted.');
                }
                $plan[$table] = $records->orderBy('id')->pluck('id')->all();
            }
        }
        if (Schema::hasTable('crm_proposals')) {
            $proposals = DB::table('crm_proposals')->whereIn('lead_id', $leadIds);
            if ((clone $proposals)->whereNotIn('uploaded_by', $userIds)->exists()) {
                throw new RuntimeException('A protected proposal references a browser fixture. No cleanup is permitted.');
            }
            $plan['crm_proposals'] = $proposals->orderBy('id')->pluck('id')->all();
        }
        if (Schema::hasTable('crm_signed_contracts')) {
            $contracts = DB::table('crm_signed_contracts')->whereIn('lead_id', $leadIds);
            if ((clone $contracts)->where(fn ($query) => $query->whereNull('created_by')->orWhereNotIn('created_by', $userIds))->exists()) {
                throw new RuntimeException('A protected signed contract references a browser fixture. No cleanup is permitted.');
            }
            $plan['crm_signed_contracts'] = $contracts->orderBy('id')->pluck('id')->all();
        }
        $plan['crm_notes'] = DB::table('crm_notes')->whereIn('lead_id', $leadIds)->orderBy('id')->pluck('id')->all();
        $plan['crm_activity'] = DB::table('crm_activity')->whereIn('lead_id', $leadIds)->orderBy('id')->pluck('id')->all();
        $plan['crm_tasks'] = $taskIds;
        $plan['crm_leads'] = $leadIds;
        if (Schema::hasTable('crm_contact_notes')) {
            $notes = DB::table('crm_contact_notes')->whereIn('contact_id', $identityIds['contacts']);
            if ((clone $notes)->whereNotIn('author_id', $userIds)->exists()) {
                throw new RuntimeException('A protected contact note references a browser fixture. No cleanup is permitted.');
            }
            $plan['crm_contact_notes'] = $notes->orderBy('id')->pluck('id')->all();
        }
        if (Schema::hasTable('crm_identity_activity')) {
            $history = DB::table('crm_identity_activity')->where(fn ($query) => $query->whereIn('contact_id', $identityIds['contacts'])->orWhereIn('company_id', $identityIds['companies']));
            if ((clone $history)->whereNotIn('actor_id', $userIds)->exists()) {
                throw new RuntimeException('A protected identity history record references a browser fixture. No cleanup is permitted.');
            }
            $plan['crm_identity_activity'] = $history->orderBy('id')->pluck('id')->all();
        }
        if (Schema::hasTable('crm_company_notes')) {
            $notes = DB::table('crm_company_notes')->whereIn('company_id', $identityIds['companies']);
            if ((clone $notes)->whereNotIn('author_id', $userIds)->exists()) {
                throw new RuntimeException('A protected company note references a browser fixture. No cleanup is permitted.');
            }
            $plan['crm_company_notes'] = $notes->orderBy('id')->pluck('id')->all();
        }
        if (Schema::hasTable('crm_documents')) {
            $documents = DB::table('crm_documents')->where($related);
            if ((clone $documents)->where(fn ($query) => $query->whereNull('uploaded_by')->orWhereNotIn('uploaded_by', $userIds))->exists()) {
                throw new RuntimeException('A protected client document references a browser fixture. No cleanup is permitted.');
            }
            $plan['crm_documents'] = $documents->orderBy('id')->pluck('id')->all();
        }
        $plan['crm_contacts'] = $identityIds['contacts'];
        $plan['crm_companies'] = $identityIds['companies'];
        if ($installation === 'alpha' && Schema::hasTable('crm_import_batches')) {
            $fixtureUsers = User::whereIn('email', array_map(fn ($size) => 'table-'.$size.'@alpha.example.test', $projects))->pluck('id')->all();
            $plan = [...$plan, ...self::legacyHistoryPlan($fixtureUsers, $leadIds, $plan['crm_activity'], $project)];
        }

        foreach ($plan as $table => $ids) {
            $plan[$table] = array_map(fn ($id) => $table === 'crm_import_batches' ? (string) $id : (int) $id, $ids);
        }

        return $plan;
    }

    public static function legacyHistoryAccountMatches(string $provider, string $account, ?string $project): bool
    {
        self::assertProject($project);

        return $provider === 'browser-fixture' && preg_match('/^legacy-history-(desktop|mobile)-[0-9]{13}$/D', $account, $match) === 1
            && ($project === null || $match[1] === $project);
    }

    /** The caller already verified the exact local Alpha database/role and disposable graph. */
    private static function legacyHistoryPlan(array $users, array $leads, array $activities, ?string $project): array
    {
        $batches = DB::table('crm_import_batches')->whereIn('created_by', $users)->where('provider', 'browser-fixture')->orderBy('id')->get()
            ->filter(fn ($batch) => self::legacyHistoryAccountMatches($batch->provider, $batch->source_account, $project));
        $batchIds = $batches->pluck('id')->all();
        $accounts = $batches->pluck('source_account')->all();
        $mappings = DB::table('crm_import_mappings')->where('provider', 'browser-fixture')->whereIn('source_account', $accounts)->orderBy('id')->get();
        foreach ($mappings as $mapping) {
            $run = substr($mapping->source_account, strlen('legacy-history-'));
            if (! in_array($mapping->created_by, $users, true) || $mapping->board_id !== 'board-'.$run
                || ($mapping->target_type !== null && ! in_array($mapping->target_type, ['core.lead', 'core.activity'], true))) {
                throw new RuntimeException('Protected import metadata references browser history; cleanup refused.');
            }
            if ($mapping->target_type !== null) {
                $table = $mapping->target_type === 'core.lead' ? 'crm_leads' : 'crm_activity';
                $selected = $mapping->target_type === 'core.lead' ? $leads : $activities;
                if ((string) (int) $mapping->target_id !== $mapping->target_id
                    || (DB::table($table)->where('id', $mapping->target_id)->exists() && ! in_array((int) $mapping->target_id, $selected, true))) {
                    throw new RuntimeException('A protected import target is outside the browser fixture; cleanup refused.');
                }
            }
        }
        $mappingIds = $mappings->pluck('id')->all();
        $operations = DB::table('crm_import_operations')->whereIn('batch_id', $batchIds)->orderBy('id')->get();
        $operationIds = $operations->pluck('id')->all();
        foreach ($operations as $operation) {
            if (! in_array($operation->mapping_id, $mappingIds, true) || ! in_array($operation->actor_id, $users, true)
                || ($operation->finished_by !== null && ! in_array($operation->finished_by, $users, true))) {
                throw new RuntimeException('Protected import operations reference browser history; cleanup refused.');
            }
            if ($operation->result_target_type !== null) {
                if (! in_array($operation->result_target_type, ['core.lead', 'core.activity'], true) || (string) (int) $operation->result_target_id !== $operation->result_target_id) {
                    throw new RuntimeException('A protected import receipt target prevents browser cleanup.');
                }
                $table = $operation->result_target_type === 'core.lead' ? 'crm_leads' : 'crm_activity';
                $selected = $operation->result_target_type === 'core.lead' ? $leads : $activities;
                if (DB::table($table)->where('id', $operation->result_target_id)->exists() && ! in_array((int) $operation->result_target_id, $selected, true)) {
                    throw new RuntimeException('A protected import receipt target prevents browser cleanup.');
                }
            }
        }
        if (DB::table('crm_import_operations')->whereNotIn('id', $operationIds)->where(fn ($query) => $query->whereIn('mapping_id', $mappingIds)
            ->orWhereIn('previous_receipt_id', $operationIds)->orWhereIn('replayed_from_id', $operationIds))->exists()
            || (Schema::hasTable('crm_import_automation_holds') && DB::table('crm_import_automation_holds')->whereIn('batch_id', $batchIds)->exists())) {
            throw new RuntimeException('Retained import references prevent browser metadata cleanup.');
        }
        foreach (DB::table('crm_activity')->whereIn('id', $activities)->where('event', 'lead.legacy_history')->get(['details']) as $entry) {
            $details = json_decode($entry->details ?? '', true, flags: JSON_THROW_ON_ERROR);
            if (! in_array($details['operation_id'] ?? null, $operationIds, true) || ! in_array($details['source_mapping_id'] ?? null, $mappingIds, true)) {
                throw new RuntimeException('Retained legacy history is outside the browser metadata scope.');
            }
        }

        return ['crm_import_operations' => $operationIds, 'crm_import_mappings' => $mappingIds, 'crm_import_batches' => $batchIds];
    }

    private static function assertProject(?string $project): void
    {
        if ($project !== null && ! in_array($project, ['desktop', 'mobile'], true)) {
            throw new InvalidArgumentException('Only the desktop and mobile browser projects are permitted.');
        }
    }
}
