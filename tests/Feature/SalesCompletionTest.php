<?php

use App\Models\User;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Communication;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Proposal;
use Awove\Core\Models\Quotation;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Sales;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function salesStaff(string $name = 'Shira Cohen'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');

    return $user;
}

function salesLead(User $owner, array $data = []): Lead
{
    $contact = Contact::create(['name' => 'Noa Levi', 'email' => 'noa.sales@example.test', 'created_by' => $owner->id, 'search_key' => 'noalevi']);

    return app(Leads::class)->create($owner, [...['contact_id' => $contact->id, 'owner_id' => $owner->id, 'status_id' => 'new'], ...$data]);
}

it('extends the same opportunity with exact money and retains multiple opportunities for one customer', function () {
    $owner = salesStaff();
    $lead = salesLead($owner);
    $second = app(Leads::class)->create($owner, ['contact_id' => $lead->contact_id, 'owner_id' => $owner->id, 'status_id' => 'new', 'title' => 'Second engagement']);
    $this->actingAs($owner)->patch('/leads/'.$lead->id, ['version' => 1, 'needs' => 'Manual business process review', 'estimated_value' => '12345.67', 'closing_date' => '2026-10-24', 'final_value' => '12000.01', 'loss_reason' => null])->assertRedirect();
    expect($lead->fresh()->estimated_value)->toBe('12345.67')->and($lead->fresh()->final_value)->toBe('12000.01')
        ->and($lead->fresh()->closing_date->format('Y-m-d'))->toBe('2026-10-24')->and($second->contact_id)->toBe($lead->contact_id)
        ->and(Lead::count())->toBe(2)->and(Activity::where('event', 'lead.sales_details_updated')->count())->toBe(1);
    $this->patch('/leads/'.$lead->id, ['version' => 1, 'estimated_value' => '50.00'])->assertConflict();
    $this->patch('/leads/'.$lead->id, ['version' => 2, 'final_value' => '0.001'])->assertSessionHasErrors('final_value');
    $this->patch('/leads/'.$lead->id, ['version' => 2, 'estimated_value' => '-0.01'])->assertSessionHasErrors('estimated_value');
    expect($lead->fresh()->version)->toBe(2);
});

it('records typed dated communications chronologically with the actual staff member and access checks', function () {
    $owner = salesStaff();
    $other = salesStaff('Uri Erez');
    $lead = salesLead($owner);
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/communications', ['type' => 'call', 'occurred_at' => '2026-10-24T12:00:00+03:00', 'summary' => 'Discussed needs', 'staff_id' => $other->id])->assertRedirect();
    $this->post('/leads/'.$lead->id.'/communications', ['type' => 'email', 'occurred_at' => '2026-10-23T09:00:00+03:00', 'summary' => 'Initial enquiry'])->assertRedirect();
    expect(Communication::first()->staff_id)->toBe($owner->id)->and(Communication::first()->occurred_at->format('H:i'))->toBe('09:00');
    $details = app(Sales::class)->details($owner, $lead);
    expect($details['communications']->pluck('type')->all())->toBe(['call', 'email']);
    $this->post('/leads/'.$lead->id.'/communications', ['type' => 'automated', 'occurred_at' => 'invalid', 'summary' => ''])->assertSessionHasErrors(['type', 'occurred_at', 'summary']);
    $this->actingAs($other)->post('/leads/'.$lead->id.'/communications', ['type' => 'message', 'occurred_at' => '2026-10-24T09:00:00+00:00', 'summary' => 'Restricted'])->assertForbidden();
    $lead->update(['archived_at' => now()]);
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/communications', ['type' => 'message', 'occurred_at' => '2026-10-24T09:00:00+00:00', 'summary' => 'Archived'])->assertForbidden();
    expect(Communication::count())->toBe(2);
    expect(fn () => Communication::first()->update(['summary' => 'Replaced']))->toThrow(LogicException::class);
    expect(fn () => Communication::first()->delete())->toThrow(LogicException::class);
});

it('persists quotation states and retains an accepted document link immutably', function () {
    $owner = salesStaff();
    $lead = salesLead($owner);
    $data = ['number' => 'Q-102', 'amount' => '4500.25', 'status' => 'draft', 'document_url' => 'https://example.test/quote.pdf'];
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/quotations', $data)->assertRedirect();
    $quotation = Quotation::sole();
    expect($quotation->amount)->toBe('4500.25')->and($quotation->status)->toBe('draft');
    $this->patch('/leads/'.$lead->id.'/quotations/'.$quotation->id, [...$data, 'status' => 'sent', 'version' => 1])->assertSessionHasErrors('sent_date');
    $this->patch('/leads/'.$lead->id.'/quotations/'.$quotation->id, [...$data, 'status' => 'sent', 'sent_date' => '2026-10-23', 'version' => 1])->assertRedirect();
    $this->patch('/leads/'.$lead->id.'/quotations/'.$quotation->id, [...$data, 'status' => 'rejected', 'version' => 1])->assertConflict();
    $this->patch('/leads/'.$lead->id.'/quotations/'.$quotation->id, [...$data, 'status' => 'accepted', 'sent_date' => '2026-10-23', 'version' => 2])->assertRedirect();
    expect($quotation->fresh()->status)->toBe('accepted')->and($quotation->fresh()->accepted_at)->not->toBeNull()->and($quotation->fresh()->version)->toBe(3);
    $this->patch('/leads/'.$lead->id.'/quotations/'.$quotation->id, [...$data, 'version' => 3])->assertUnprocessable();
    expect(fn () => $quotation->fresh()->update(['document_url' => 'https://example.test/replaced.pdf']))->toThrow(LogicException::class);
    expect(fn () => $quotation->fresh()->delete())->toThrow(LogicException::class);
    $this->post('/leads/'.$lead->id.'/quotations', [...$data, 'number' => 'Q-103', 'status' => 'rejected'])->assertRedirect();
    expect(Quotation::count())->toBe(2)->and(Quotation::find($quotation->id)->document_url)->toBe('https://example.test/quote.pdf');
});

it('denies cross-owner quotation access and unsafe or missing document references', function () {
    $owner = salesStaff();
    $lead = salesLead($owner);
    $other = salesStaff('Yael Shalev');
    $data = ['number' => 'Q-104', 'amount' => '99.99', 'status' => 'draft', 'document_url' => 'https://example.test/quote.pdf'];
    $this->actingAs($other)->post('/leads/'.$lead->id.'/quotations', $data)->assertForbidden();
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/quotations', [...$data, 'document_url' => 'javascript:alert(1)'])->assertSessionHasErrors('document_url');
    $this->post('/leads/'.$lead->id.'/quotations', [...$data, 'document_url' => null])->assertSessionHasErrors('document_url');
    $this->post('/leads/'.$lead->id.'/quotations', [...$data, 'amount' => '1.001'])->assertSessionHasErrors('amount');
    expect(Quotation::count())->toBe(0);
});

it('reports scoped open pipeline and won final values by closing period without inventing unknown values', function () {
    $owner = salesStaff();
    $other = salesStaff('Tamar Erez');
    salesLead($owner, ['estimated_value' => '0.10']);
    salesLead($owner, ['estimated_value' => '0.20']);
    salesLead($owner, ['status_id' => 'won', 'closing_date' => '2026-10-24', 'final_value' => '5000.03']);
    salesLead($owner, ['status_id' => 'won', 'closing_date' => '2026-09-24', 'final_value' => '6000.04']);
    salesLead($other, ['estimated_value' => '9000.00']);
    $this->actingAs($owner)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->component('Core/Dashboard')->where('salesOverview.estimated_pipeline', '0.30')->where('salesOverview.won_value', '11000.07')->where('salesOverview.won_count', 2));
    salesLead($owner, ['status_id' => 'won']);
    $this->get('/dashboard?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $page) => $page->where('salesOverview.won_value', '5000.03')->where('salesOverview.won_count', 1)->where('salesOverview.won_missing_closing_date', 1));
    $this->get('/dashboard?owner='.$other->id)->assertInertia(fn (Assert $page) => $page->where('salesOverview.estimated_pipeline', null)->where('salesOverview.won_count', 0));
    $this->get('/dashboard?to=2026-10-31')->assertOk();
    $this->get('/dashboard?from=2026-10-31&to=2026-10-01')->assertSessionHasErrors('to');
});

it('binds a quotation only to a retained version of the same opportunity', function () {
    $owner = salesStaff();
    $lead = salesLead($owner);
    $other = salesLead($owner);
    $proposal = Proposal::create(['lead_id' => $lead->id, 'version' => 1, 'original_name' => 'Retained quote.pdf', 'private_path' => 'proposals/synthetic.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $owner->id, 'created_at' => now()]);
    $data = ['number' => 'Q-105', 'amount' => '100.01', 'status' => 'accepted', 'sent_date' => '2026-10-24', 'proposal_id' => $proposal->id];
    $this->actingAs($owner)->post('/leads/'.$other->id.'/quotations', $data)->assertSessionHasErrors('proposal_id');
    $this->post('/leads/'.$lead->id.'/quotations', $data)->assertRedirect();
    expect(Quotation::sole()->proposal_id)->toBe($proposal->id);
    $details = app(Sales::class)->details($owner, $lead);
    expect($details['quotations']->first()['download_url'])->toBe('/leads/'.$lead->id.'/proposals/'.$proposal->id.'/download')
        ->and($details['quotations']->first()['sent_date'])->toBe('2026-10-24');
    $this->patch('/leads/'.$other->id.'/quotations/'.Quotation::sole()->id, [...$data, 'version' => 1])->assertNotFound();
    expect(Quotation::sole()->proposal_id)->toBe($proposal->id)->and(Proposal::count())->toBe(1);
});
