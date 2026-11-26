<?php

use App\Models\User;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\IdentityActivity;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Proposal;
use Awove\Core\Models\Quotation;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function baselineStorageStaff(string $name = 'Tamar Levi'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');

    return $user;
}

function baselineStorageLead(User $owner, array $data = []): Lead
{
    $contact = Contact::create(['name' => 'Noa Cohen', 'email' => 'noa.baseline@example.test', 'created_by' => $owner->id, 'search_key' => 'noacohen']);

    return app(Leads::class)->create($owner, [...['contact_id' => $contact->id, 'owner_id' => $owner->id, 'status_id' => 'new'], ...$data]);
}

it('creates and reloads baseline lead fields through the shared form endpoint without requiring future milestones', function () {
    $owner = baselineStorageStaff();
    $company = app(Identities::class)->create($owner, 'companies', ['name' => 'Cohen Studio', 'registration_number' => '001234567']);
    $contact = app(Identities::class)->create($owner, 'contacts', ['name' => 'Noa Cohen', 'company_id' => $company->id, 'email' => 'noa.create@example.test']);
    $data = [
        'contact_id' => $contact->id, 'company_id' => $company->id, 'status_id' => 'new',
        'business_sector' => 'Business consulting', 'product_sold' => 'A fictional process review',
        'contract_sent_on' => '2026-11-26', 'invoice_sent_on' => null,
        'payment_received_on' => null, 'process_started_on' => '2026-12-01',
        'contract_url' => 'https://example.test/contracts/review.pdf', 'privacy_policy_accepted' => false,
    ];
    $this->actingAs($owner)->post('/leads', $data)->assertRedirect();
    $lead = Lead::sole();
    expect($lead->business_sector)->toBe($data['business_sector'])
        ->and($lead->product_sold)->toBe($data['product_sold'])
        ->and($lead->contract_sent_on->format('Y-m-d'))->toBe('2026-11-26')
        ->and($lead->invoice_sent_on)->toBeNull()->and($lead->payment_received_on)->toBeNull()
        ->and($lead->privacy_policy_accepted)->toBeFalse()->and($lead->owner_id)->toBe($owner->id);
    $this->get('/leads/'.$lead->id)->assertInertia(fn (Assert $page) => $page
        ->where('lead.business_sector', $data['business_sector'])
        ->where('lead.product_sold', $data['product_sold'])
        ->where('lead.contract_sent_on', '2026-11-26')->where('lead.invoice_sent_on', null)
        ->where('lead.process_started_on', '2026-12-01')->where('lead.privacy_policy_accepted', false)
        ->where('lead.company.registration_number', '001234567')->has('signed_contracts', 0));
});

it('preserves unknown declined and accepted privacy values and clears nullable fields on direct service updates', function () {
    $owner = baselineStorageStaff();
    $lead = baselineStorageLead($owner);
    expect($lead->privacy_policy_accepted)->toBeNull()->and($lead->business_sector)->toBeNull()
        ->and($lead->contract_sent_on)->toBeNull()->and($lead->product_sold)->toBeNull();
    foreach ([false, true, null] as $consent) {
        app(Leads::class)->update($owner, $lead->id, ['version' => $lead->fresh()->version, 'privacy_policy_accepted' => $consent]);
        expect($lead->fresh()->privacy_policy_accepted)->toBe($consent);
    }
    app(Leads::class)->update($owner, $lead->id, ['version' => $lead->fresh()->version, 'contract_sent_on' => '2026-11-26', 'contract_url' => 'https://example.test/contract']);
    app(Leads::class)->update($owner, $lead->id, ['version' => $lead->fresh()->version, 'contract_sent_on' => '', 'contract_url' => '', 'privacy_policy_accepted' => '']);
    expect($lead->fresh()->contract_sent_on)->toBeNull()->and($lead->fresh()->contract_url)->toBeNull()
        ->and($lead->fresh()->privacy_policy_accepted)->toBeNull();
});

it('retains existing lead identity money proposal quotation and creation history when baseline fields change', function () {
    $owner = baselineStorageStaff();
    $lead = baselineStorageLead($owner, ['title' => 'Retained enquiry', 'source' => 'Referral', 'priority' => 'high', 'needs' => 'An authored urgent problem', 'estimated_value' => '1234.56', 'final_value' => '1200.01', 'closing_date' => '2026-11-25', 'loss_reason' => 'Retained legacy reason']);
    $proposal = Proposal::create(['lead_id' => $lead->id, 'version' => 1, 'original_name' => 'Existing proposal.pdf', 'private_path' => 'proposals/retained-baseline.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 90, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $owner->id, 'created_at' => now()]);
    $quotation = Quotation::create(['lead_id' => $lead->id, 'created_by' => $owner->id, 'number' => 'Q-RETAIN', 'amount' => '1200.01', 'sent_date' => '2026-11-24', 'status' => 'accepted', 'proposal_id' => $proposal->id, 'accepted_at' => now()]);
    $preserved = $lead->fresh()->only(['id', 'title', 'contact_id', 'company_id', 'owner_id', 'status_id', 'source', 'priority', 'needs', 'estimated_value', 'final_value', 'closing_date', 'loss_reason', 'custom_fields', 'created_at']);
    $oldProposal = $proposal->fresh()->getRawOriginal();
    $oldQuotation = $quotation->fresh()->getRawOriginal();
    $this->actingAs($owner)->patch('/leads/'.$lead->id, ['version' => 1, 'business_sector' => 'Consulting', 'product_sold' => 'Review', 'invoice_sent_on' => '2026-11-26', 'privacy_policy_accepted' => true, 'created_at' => '2000-01-01T00:00:00Z'])->assertRedirect();
    expect($lead->fresh()->only(array_keys($preserved)))->toEqual($preserved)
        ->and($proposal->fresh()->getRawOriginal())->toBe($oldProposal)
        ->and($quotation->fresh()->getRawOriginal())->toBe($oldQuotation);
    $entry = Activity::where('event', 'lead.sales_details_updated')->sole();
    expect($entry->details['fields'])->toContain('business_sector', 'product_sold', 'invoice_sent_on', 'privacy_policy_accepted')
        ->and($entry->details)->not->toHaveKeys(['business_sector', 'product_sold', 'privacy_policy_accepted']);
    $this->patch('/leads/'.$lead->id, ['version' => 1, 'business_sector' => 'Stale overwrite', 'privacy_policy_accepted' => false])->assertConflict();
    expect($lead->fresh()->business_sector)->toBe('Consulting')->and($lead->fresh()->privacy_policy_accepted)->toBeTrue()->and($lead->fresh()->version)->toBe(2);
});

it('validates baseline fields atomically in direct services and does not alter audit timestamps', function (string $field, mixed $value) {
    $owner = baselineStorageStaff();
    $lead = baselineStorageLead($owner);
    $before = $lead->fresh()->getRawOriginal();
    expect(fn () => app(Leads::class)->update($owner, $lead->id, ['version' => 1, 'business_sector' => 'Must not partially save', $field => $value]))->toThrow(ValidationException::class);
    expect($lead->fresh()->getRawOriginal())->toBe($before)->and(Activity::where('event', 'lead.sales_details_updated')->count())->toBe(0);
    $create = ['contact_id' => $lead->contact_id, 'owner_id' => $owner->id, 'status_id' => 'new', $field => $value];
    expect(fn () => app(Leads::class)->create($owner, $create))->toThrow(ValidationException::class);
    expect(Lead::count())->toBe(1);
})->with([
    ['business_sector', str_repeat('x', 121)],
    ['product_sold', str_repeat('x', 1001)],
    ['contract_sent_on', '26/11/2026'],
    ['invoice_sent_on', '2026-02-30'],
    ['payment_received_on', '2026-11-26T10:00:00Z'],
    ['process_started_on', 'invalid'],
    ['contract_url', 'javascript:alert(1)'],
    ['contract_url', 'https://example.test/'.str_repeat('x', 2048)],
    ['privacy_policy_accepted', 'yes'],
    ['privacy_policy_accepted', 2],
    ['created_at', '2000-01-01T00:00:00Z'],
    ['updated_at', null],
]);

it('keeps new field writes under existing lead scope and archive permissions', function () {
    $owner = baselineStorageStaff();
    $other = baselineStorageStaff('Uri Shalev');
    $lead = baselineStorageLead($owner);
    $this->actingAs($other)->patch('/leads/'.$lead->id, ['version' => 1, 'product_sold' => 'Restricted', 'privacy_policy_accepted' => true])->assertForbidden();
    $other->givePermissionTo('leads.view.all');
    $this->get('/leads/'.$lead->id)->assertOk();
    $this->patch('/leads/'.$lead->id, ['version' => 1, 'product_sold' => 'Still restricted'])->assertForbidden();
    $lead->update(['archived_at' => now()]);
    $this->actingAs($owner)->patch('/leads/'.$lead->id, ['version' => 1, 'contract_sent_on' => '2026-11-26'])->assertForbidden();
    expect($lead->fresh()->version)->toBe(1)->and($lead->fresh()->product_sold)->toBeNull()
        ->and($lead->fresh()->privacy_policy_accepted)->toBeNull();
});

it('stores company registration as text through creation partial edits profile DTOs and field-name-only history', function () {
    $owner = baselineStorageStaff();
    $this->actingAs($owner)->post('/people/companies', ['name' => 'Erez Studio', 'registration_number' => '  000123456  '])->assertRedirect();
    $company = Company::sole();
    expect($company->registration_number)->toBe('000123456')->and(IdentityActivity::sole()->changed_fields)->toBe(['name', 'registration_number']);
    $this->get('/people/companies/'.$company->id)->assertInertia(fn (Assert $page) => $page->where('identity.registration_number', '000123456'));
    $this->patch('/people/companies/'.$company->id, ['phone' => '+1 202-555-0172'])->assertRedirect();
    expect($company->fresh()->registration_number)->toBe('000123456');
    $this->patch('/people/companies/'.$company->id, ['registration_number' => null])->assertRedirect();
    expect($company->fresh()->registration_number)->toBeNull()
        ->and(IdentityActivity::latest('id')->firstOrFail()->changed_fields)->toBe(['registration_number']);
    foreach ([123456, str_repeat('x', 81)] as $invalid) {
        $this->post('/people/companies', ['name' => 'Cohen Studio', 'registration_number' => $invalid])->assertSessionHasErrors('registration_number');
    }
    $this->post('/people/contacts', ['name' => 'Noa Erez', 'registration_number' => '001'])->assertSessionHasErrors('registration_number');
    expect(Company::count())->toBe(1)->and(Contact::count())->toBe(0);
    $other = baselineStorageStaff('Yael Cohen');
    $this->actingAs($other)->patch('/people/companies/'.$company->id, ['registration_number' => '999'])->assertForbidden();
    expect($company->fresh()->registration_number)->toBeNull();
});

it('adds nullable baseline storage without replacing pre-existing customer or opportunity values', function () {
    $owner = baselineStorageStaff();
    $company = app(Identities::class)->create($owner, 'companies', ['name' => 'Levi Office', 'phone' => '+1 202-555-0182']);
    $lead = baselineStorageLead($owner, ['company_id' => $company->id, 'source' => 'Referral', 'needs' => 'Retained authored need', 'estimated_value' => '1500.25']);
    $leadFields = ['business_sector', 'product_sold', 'contract_sent_on', 'invoice_sent_on', 'payment_received_on', 'process_started_on', 'contract_url', 'privacy_policy_accepted'];
    Schema::table('crm_leads', fn ($table) => $table->dropColumn($leadFields));
    Schema::table('crm_companies', fn ($table) => $table->dropColumn('registration_number'));
    $beforeLead = (array) DB::table('crm_leads')->where('id', $lead->id)->first();
    $beforeCompany = (array) DB::table('crm_companies')->where('id', $company->id)->first();
    $migration = require base_path('packages/awove/crm-core/database/migrations/2026_11_26_001400_add_lead_baseline_fields.php');
    $migration->up();
    $afterLead = (array) DB::table('crm_leads')->where('id', $lead->id)->first();
    $afterCompany = (array) DB::table('crm_companies')->where('id', $company->id)->first();
    expect(array_intersect_key($afterLead, $beforeLead))->toBe($beforeLead)
        ->and(array_intersect_key($afterCompany, $beforeCompany))->toBe($beforeCompany);
    foreach ($leadFields as $field) {
        expect($afterLead[$field])->toBeNull();
    }
    expect($afterCompany['registration_number'])->toBeNull();
});
