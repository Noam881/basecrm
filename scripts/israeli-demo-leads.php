<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Status;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Tasks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Explicit local demonstration data only; never called during installation/deployment. */
final class IsraeliDemoLeads
{
    public const PREFIX = 'demo:israel:v1:';

    public static function run(string $installation, bool $apply = false): array
    {
        FictionalCrmFixtures::assertLocalExample($installation);
        $connection = DB::connection();
        if ($connection->getConfig('database') !== 'base_'.$installation
            || ! in_array($connection->getConfig('host'), ['127.0.0.1', 'localhost'], true)
            || (int) $connection->getConfig('port') !== 55438) {
            throw new RuntimeException('The resolved connection is not the isolated local example.');
        }
        $effective = $connection->selectOne('SELECT current_database() AS database_name, current_user AS role_name');
        if ($effective->database_name !== 'base_'.$installation || $effective->role_name !== 'base_'.$installation) {
            throw new RuntimeException('The database server or role is not the isolated local example.');
        }
        $owner = User::where('email', 'reviewer@'.$installation.'.example.test')->where('active', true)->sole();
        abort_unless($owner->can('leads.create'), 403);

        return DB::transaction(function () use ($installation, $owner, $apply) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-israeli-demo:'.$installation]);
            $catalog = IsraeliDemoCatalog::rows();
            $keys = array_map(fn ($row) => self::PREFIX.$row['key'], $catalog);
            $existing = Lead::whereIn('external_reference', $keys)->get();
            $missing = [];
            foreach ($catalog as $entry) {
                $matches = $existing->where('external_reference', self::PREFIX.$entry['key']);
                if ($matches->count() > 1) {
                    throw new RuntimeException('Duplicate demo marker; no records changed.');
                }
                $lead = $matches->first();
                if ($lead) {
                    if ($lead->created_by !== $owner->id
                        || ! Contact::whereKey($lead->contact_id)->where('created_by', $owner->id)->where('email', $entry['contact']['email'])->exists()
                        || ! Company::whereKey($lead->company_id)->where('created_by', $owner->id)->where('email', $entry['company']['email'])->exists()) {
                        throw new RuntimeException('A demo marker or identity changed ownership; no records changed.');
                    }

                    // Preserve every authored edit and existing relationship, note and date.
                    continue;
                }
                if (Contact::where('email', $entry['contact']['email'])->exists() || Company::where('email', $entry['company']['email'])->exists()) {
                    throw new RuntimeException('A demo email is already in use without its matching marker; no records changed.');
                }
                if (! Status::whereKey($entry['lead']['status_id'])->exists()) {
                    throw new RuntimeException('A required stage is missing; existing stages will not be changed.');
                }
                $missing[] = $entry;
            }
            if ($apply) {
                foreach ($missing as $entry) {
                    self::insert($owner, $entry);
                }
            }

            return ['installation' => $installation, 'applied' => $apply, 'catalog_leads' => count($catalog),
                'existing_demo_leads_preserved' => $existing->count(), 'leads_to_create' => count($missing),
                'leads_created' => $apply ? count($missing) : 0,
                'fictional_data_only' => true, 'existing_records_updated' => 0];
        });
    }

    private static function insert(User $owner, array $entry): void
    {
        $identities = app(Identities::class);
        $company = $identities->create($owner, 'companies', $entry['company']);
        $contact = $identities->create($owner, 'contacts', [...$entry['contact'], 'company_id' => $company->id, 'notes' => $entry['contact_note']]);
        $entered = CarbonImmutable::today('Asia/Jerusalem')->subDays($entry['age_days'])->setTime(10, 15);
        $won = $entry['lead']['status_id'] === 'won';
        $closed = in_array($entry['lead']['status_id'], ['won', 'lost'], true);
        $milestones = $won ? [
            'closing_date' => $entered->addDays(5)->toDateString(),
            'contract_sent_on' => $entered->addDays(6)->toDateString(),
            'invoice_sent_on' => $entered->addDays(7)->toDateString(),
            'payment_received_on' => $entered->addDays(9)->toDateString(),
            'process_started_on' => $entered->addDays(12)->toDateString(),
            'contract_url' => 'https://documents.example.test/demo/israel/'.$entry['key'].'/contract',
        ] : ($closed ? ['closing_date' => $entered->addDays(5)->toDateString()] : []);
        $lead = app(Leads::class)->create($owner, [...$entry['lead'], ...$milestones,
            'contact_id' => $contact->id, 'company_id' => $company->id, 'owner_id' => $owner->id,
            'external_reference' => self::PREFIX.$entry['key'],
        ]);
        // Backdate only this newly created, explicitly marked fictional row.
        DB::table('crm_leads')->where('id', $lead->id)->where('external_reference', self::PREFIX.$entry['key'])
            ->update(['created_at' => $entered, 'updated_at' => $entered]);
        DB::table('crm_activity')->where('lead_id', $lead->id)->where('event', 'lead.created')->update(['created_at' => $entered]);
        $lead->notes()->create(['author_id' => $owner->id, 'body' => 'Fictional demonstration record. No real customer or communication.']);
        foreach ($entry['notes'] as $body) {
            $lead->notes()->create(['author_id' => $owner->id, 'body' => $body]);
        }
        if ($entry['follow_up'] !== null) {
            $followUp = $entry['follow_up'];
            $due = CarbonImmutable::today('Asia/Jerusalem')->addDays($followUp['days_offset'])->setTime($followUp['hour'], 30);
            while (in_array($due->dayOfWeek, [5, 6], true)) {
                $due = $due->addDay();
            }
            app(Tasks::class)->create($owner, $lead, ['title' => $followUp['title'], 'assignee_id' => $owner->id,
                'due_at' => $due->utc(), 'remind_at' => $due->subMinutes(30)->utc(), 'priority' => $lead->priority]);
        }
    }
}
