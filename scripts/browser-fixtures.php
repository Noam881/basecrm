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

    /** @return array<string,list<int>> Dependency order for guarded deletion or backup. */
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
        $plan['crm_contacts'] = $identityIds['contacts'];
        $plan['crm_companies'] = $identityIds['companies'];

        return array_map(fn ($ids) => array_map(fn ($id) => (int) $id, $ids), $plan);
    }

    private static function assertProject(?string $project): void
    {
        if ($project !== null && ! in_array($project, ['desktop', 'mobile'], true)) {
            throw new InvalidArgumentException('Only the desktop and mobile browser projects are permitted.');
        }
    }
}
