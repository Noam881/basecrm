<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Task;
use Awove\Core\Services\Leads;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Shared, deliberately fictional local demo identities; never used by production. */
final class FictionalCrmFixtures
{
    public static function assertLocalExample(string $installation): void
    {
        if (! in_array($installation, ['alpha', 'beta'], true)
            || ! app()->isLocal()
            || config('core.installation') !== $installation
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'base_'.$installation
            || ! in_array(config('database.connections.pgsql.host'), ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Only the isolated local Alpha/Beta example database is permitted.');
        }
    }

    /** @return list<array<string, string>> */
    public static function catalog(): array
    {
        return [
            ['key' => 'rimon', 'old_company' => 'Harbor Studio', 'company' => 'Rimon Studio', 'hebrew_company' => 'סטודיו רימון', 'contact' => 'Noa Levi', 'hebrew_contact' => 'נועה לוי', 'contact_slug' => 'noa.levi', 'title' => 'Website refresh', 'status' => 'qualified', 'priority' => 'high'],
            ['key' => 'carmel', 'old_company' => 'Northline Supply', 'company' => 'Carmel Supply', 'hebrew_company' => 'אספקת כרמל', 'contact' => 'Daniel Cohen', 'hebrew_contact' => 'דניאל כהן', 'contact_slug' => 'daniel.cohen', 'title' => 'Service consultation', 'status' => 'contacted', 'priority' => 'normal'],
            ['key' => 'shaked', 'old_company' => 'Juniper & Co', 'company' => 'Shaked & Co', 'hebrew_company' => 'שקד ושות׳', 'contact' => 'Yael Mizrahi', 'hebrew_contact' => 'יעל מזרחי', 'contact_slug' => 'yael.mizrahi', 'title' => 'Annual maintenance', 'status' => 'new', 'priority' => 'low'],
        ];
    }

    public static function reviewerName(string $installation): string
    {
        return $installation === 'alpha' ? 'Itai Levi' : 'Michal Cohen';
    }

    public static function tableReviewerName(string $size): string
    {
        return $size === 'desktop' ? 'Eitan Levi — table review' : 'Tamar Cohen — table review';
    }

    /** @return list<string> */
    public static function companyAliases(array $entry): array
    {
        return [$entry['old_company'], $entry['hebrew_company'], $entry['company']];
    }

    /** @return list<string> */
    public static function contactAliases(array $entry): array
    {
        return [$entry['old_company'].' contact', $entry['hebrew_contact'], $entry['contact']];
    }

    /** Recognize only the explicit catalog or timestamped browser demo identities. */
    public static function englishName(string $name, string $kind): ?string
    {
        foreach (self::catalog() as $entry) {
            if (in_array($name, $kind === 'companies' ? self::companyAliases($entry) : self::contactAliases($entry), true)) {
                return $kind === 'companies' ? $entry['company'] : $entry['contact'];
            }
        }
        $patterns = $kind === 'companies' ? [
            '/^(?:Company |אורן פתרונות — |Oren Solutions — )((?:desktop|mobile)-\d{13})$/u' => 'Oren Solutions — ',
            '/^(?:לוי פתרונות — |Levi Solutions — )((?:desktop|mobile)-\d{13})$/u' => 'Levi Solutions — ',
        ] : [
            '/^(?:Person |אורי בן דוד — |Uri Ben David — )((?:desktop|mobile)-\d{13})$/u' => 'Uri Ben David — ',
            '/^(?:שירה לוי — |Shira Levi — )((?:desktop|mobile)-\d{13})$/u' => 'Shira Levi — ',
        ];
        foreach ($patterns as $pattern => $prefix) {
            if (preg_match($pattern, $name, $match)) {
                return $prefix.$match[1];
            }
        }

        return null;
    }

    /** North American 555-0100–0199 is reserved for fictional use. */
    public static function phone(int $index): string
    {
        return '+1 202-555-'.sprintf('%04d', 100 + ($index % 100));
    }

    public static function searchKey(string $name): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $name));
    }

    /** Correct labels/details without rewriting original creation or update times. */
    public static function enrich(Model $record, array $attributes): void
    {
        $record->forceFill($attributes);
        if ($record->isDirty()) {
            $timestamps = $record->timestamps;
            $record->timestamps = $record->exists ? false : $timestamps;
            $record->save();
            $record->timestamps = $timestamps;
        }
    }

    /**
     * Reuse exact recognized demo rows, preserving existing non-null relationships.
     * Missing contacts are added to the original three review leads only.
     *
     * @return array{companies:int, contacts:int, leads_created:int, contacts_linked:int}
     */
    public static function sync(User $user, string $installation, bool $table, bool $createLeads): array
    {
        self::assertLocalExample($installation);
        $allowed = ['reviewer@'.$installation.'.example.test', 'table-desktop@'.$installation.'.example.test', 'table-mobile@'.$installation.'.example.test'];
        if (! in_array($user->email, $allowed, true)) {
            throw new RuntimeException('Unrecognized demo account.');
        }

        return DB::transaction(function () use ($user, $installation, $table, $createLeads) {
            $counts = ['companies' => 0, 'contacts' => 0, 'leads_created' => 0, 'contacts_linked' => 0];
            foreach (self::catalog() as $index => $entry) {
                $company = Company::where('created_by', $user->id)->whereIn('name', self::companyAliases($entry))->orderBy('id')->first();
                if (! $company && ! $createLeads) {
                    continue;
                }
                $company ??= new Company(['created_by' => $user->id]);
                self::enrich($company, ['name' => $entry['company'], 'email' => $company->email ?? 'hello@'.$entry['key'].'.'.$installation.'.example.test', 'phone' => $company->phone ?? self::phone(10 + $index), 'search_key' => self::searchKey($entry['company'])]);
                $counts['companies']++;

                $contact = Contact::where('created_by', $user->id)->where('company_id', $company->id)->whereIn('name', self::contactAliases($entry))->orderBy('id')->first();
                $contact ??= new Contact(['created_by' => $user->id, 'company_id' => $company->id]);
                self::enrich($contact, ['name' => $entry['contact'], 'email' => $contact->email ?? $entry['contact_slug'].'@'.$installation.'.example.test', 'phone' => $contact->phone ?? self::phone(20 + $index), 'search_key' => self::searchKey($entry['contact'])]);
                $counts['contacts']++;

                $lead = Lead::where('created_by', $user->id)->where('company_id', $company->id)->where('title', $entry['title'])->orderBy('id')->first();
                // A reviewer may have renamed the original lead; reuse it rather than duplicate it.
                $lead ??= Lead::where('created_by', $user->id)->where('company_id', $company->id)->orderBy('id')->first();
                if (! $lead && $createLeads) {
                    $lead = app(Leads::class)->create($user, ['title' => $entry['title'], 'company_id' => $company->id, 'contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => $table ? 'new' : $entry['status'], 'source' => 'Referral', 'priority' => $table ? $entry['priority'] : 'normal', 'custom_fields' => $table && $installation === 'beta' ? ['service_type' => 'Maintenance'] : []]);
                    $counts['leads_created']++;
                    if (! $table) {
                        $lead->notes()->create(['body' => 'Fictional review data. No live customer relationship.', 'author_id' => $user->id]);
                        $lead->tasks()->create(['title' => 'Check in with '.$entry['company'], 'assignee_id' => $user->id, 'due_at' => now()->addHours(2), 'remind_at' => now()->subMinute(), 'priority' => 'normal']);
                    } elseif ($installation === 'beta') {
                        DB::table('service_details_leads')->where('lead_id', $lead->id)->update(['service_reference' => $entry['key'] === 'rimon' ? 'DEMO-REF' : 'TABLE-REF', 'requested_service' => 'Fictional service']);
                    }
                } elseif ($lead && $lead->contact_id === null) {
                    self::enrich($lead, ['contact_id' => $contact->id]);
                    $counts['contacts_linked']++;
                }
            }

            return $counts;
        });
    }

    /** Replace only known old fictional names embedded in current follow-up titles. */
    public static function localizeTaskTitles(User $user): int
    {
        $installation = config('core.installation');
        self::assertLocalExample($installation);
        if (! in_array($user->email, ['reviewer@'.$installation.'.example.test', 'table-desktop@'.$installation.'.example.test', 'table-mobile@'.$installation.'.example.test'], true)) {
            throw new RuntimeException('Unrecognized demo account.');
        }
        $count = 0;
        foreach (self::catalog() as $entry) {
            $tasks = Task::where('assignee_id', $user->id)->whereHas('lead', fn ($query) => $query->where('created_by', $user->id))->whereIn('title', array_map(fn ($name) => 'Check in with '.$name, array_diff(self::companyAliases($entry), [$entry['company']])))->get();
            foreach ($tasks as $task) {
                self::enrich($task, ['title' => 'Check in with '.$entry['company']]);
                $count++;
            }
        }

        return $count;
    }

    /** Rename recognized demo entities without replacing edited contact details. */
    public static function localizeBrowserRows(User $user): array
    {
        $installation = config('core.installation');
        self::assertLocalExample($installation);
        if (! in_array($user->email, ['reviewer@'.$installation.'.example.test', 'table-desktop@'.$installation.'.example.test', 'table-mobile@'.$installation.'.example.test'], true)) {
            throw new RuntimeException('Only reserved fictional review accounts are permitted.');
        }
        $counts = ['companies' => 0, 'contacts' => 0];
        foreach (['companies' => Company::class, 'contacts' => Contact::class] as $kind => $model) {
            foreach ($model::where('created_by', $user->id)->get() as $record) {
                $name = self::englishName($record->name, $kind);
                if ($name !== null && $name !== $record->name) {
                    self::enrich($record, ['name' => $name, 'search_key' => self::searchKey($name)]);
                    $counts[$kind]++;
                }
            }
        }

        return $counts;
    }
}
