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
            ['key' => 'rimon', 'old_company' => 'Harbor Studio', 'company' => 'סטודיו רימון', 'contact' => 'נועה לוי', 'contact_slug' => 'noa.levi', 'title' => 'Website refresh', 'status' => 'qualified', 'priority' => 'high'],
            ['key' => 'carmel', 'old_company' => 'Northline Supply', 'company' => 'אספקת כרמל', 'contact' => 'דניאל כהן', 'contact_slug' => 'daniel.cohen', 'title' => 'Service consultation', 'status' => 'contacted', 'priority' => 'normal'],
            ['key' => 'shaked', 'old_company' => 'Juniper & Co', 'company' => 'שקד ושות׳', 'contact' => 'יעל מזרחי', 'contact_slug' => 'yael.mizrahi', 'title' => 'Annual maintenance', 'status' => 'new', 'priority' => 'low'],
        ];
    }

    public static function reviewerName(string $installation): string
    {
        return $installation === 'alpha' ? 'איתי לוי' : 'מיכל כהן';
    }

    public static function tableReviewerName(string $size): string
    {
        return $size === 'desktop' ? 'איתן לוי — בדיקת טבלאות' : 'תמר כהן — בדיקת טבלאות';
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
                $company = Company::where('created_by', $user->id)->whereIn('name', [$entry['old_company'], $entry['company']])->orderBy('id')->first();
                if (! $company && ! $createLeads) {
                    continue;
                }
                $company ??= new Company(['created_by' => $user->id]);
                self::enrich($company, ['name' => $entry['company'], 'email' => 'hello@'.$entry['key'].'.'.$installation.'.example.test', 'phone' => self::phone(10 + $index), 'search_key' => self::searchKey($entry['company'])]);
                $counts['companies']++;

                $contact = Contact::where('created_by', $user->id)->where('company_id', $company->id)->whereIn('name', [$entry['old_company'].' contact', $entry['contact']])->orderBy('id')->first();
                $contact ??= new Contact(['created_by' => $user->id, 'company_id' => $company->id]);
                self::enrich($contact, ['name' => $entry['contact'], 'email' => $entry['contact_slug'].'@'.$installation.'.example.test', 'phone' => self::phone(20 + $index), 'search_key' => self::searchKey($entry['contact'])]);
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
            $tasks = Task::where('assignee_id', $user->id)->whereHas('lead', fn ($query) => $query->where('created_by', $user->id))->where('title', 'Check in with '.$entry['old_company'])->get();
            foreach ($tasks as $task) {
                self::enrich($task, ['title' => 'Check in with '.$entry['company']]);
                $count++;
            }
        }

        return $count;
    }

    /** Update only browser fixtures identifiable by both reserved owner and exact name. */
    public static function localizeBrowserRows(User $user): array
    {
        $installation = config('core.installation');
        self::assertLocalExample($installation);
        if ($user->email !== 'reviewer@'.$installation.'.example.test') {
            throw new RuntimeException('Only the reserved fictional browser-review account is permitted.');
        }
        $counts = ['companies' => 0, 'contacts' => 0];
        foreach (Company::where('created_by', $user->id)->get() as $company) {
            if (! preg_match('/^(?:Company |אורן פתרונות — )((?:desktop|mobile)-\d{13})$/u', $company->name, $match)) {
                continue;
            }
            $name = 'אורן פתרונות — '.$match[1];
            self::enrich($company, ['name' => $name, 'email' => 'office.'.$match[1].'@demo.example.test', 'phone' => self::phone(30 + $company->id), 'search_key' => self::searchKey($name)]);
            $counts['companies']++;
        }
        foreach (Contact::where('created_by', $user->id)->get() as $contact) {
            if (! preg_match('/^(?:Person |אורי בן דוד — )((?:desktop|mobile)-\d{13})$/u', $contact->name, $match)) {
                continue;
            }
            $name = 'אורי בן דוד — '.$match[1];
            self::enrich($contact, ['name' => $name, 'email' => 'uri.'.$match[1].'@demo.example.test', 'phone' => self::phone(60 + $contact->id), 'search_key' => self::searchKey($name)]);
            $counts['contacts']++;
        }

        return $counts;
    }
}
