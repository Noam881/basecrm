<?php

use App\Models\User;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Leads;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function workflowEmployee(): User
{
    $user = User::factory()->create();
    $user->assignRole('Employee');

    return $user;
}

function workflowLead(User $user, array $extra = []): Lead
{
    $contact = Contact::create(['name' => 'Yael Shalev', 'email' => 'yael@workflow.example.test', 'created_by' => $user->id, 'search_key' => 'yael']);

    return app(Leads::class)->create($user, [...['contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new', 'source' => 'Website'], ...$extra]);
}

it('supports an inline stage-only change with audit history and rejects a stale overwrite', function () {
    $user = workflowEmployee();
    $lead = workflowLead($user);
    $created = $lead->created_at->toISOString();
    $this->actingAs($user)->patch('/leads/'.$lead->id, ['status_id' => 'qualified', 'version' => 1])->assertRedirect();
    $saved = $lead->fresh();
    expect($saved->status_id)->toBe('qualified')->and($saved->version)->toBe(2)->and($saved->created_at->toISOString())->toBe($created);
    expect(Activity::where('lead_id', $lead->id)->where('event', 'lead.status_changed')->sole()->details)->toMatchArray(['from' => 'new', 'to' => 'qualified']);
    $this->patch('/leads/'.$lead->id, ['status_id' => 'lost', 'version' => 1])->assertConflict();
    expect($lead->fresh()->status_id)->toBe('qualified');
});

it('exposes stage edit permissions and creation and proposal columns without overwriting saved layouts', function () {
    $owner = workflowEmployee();
    $viewer = workflowEmployee();
    $viewer->givePermissionTo('leads.view.all');
    $lead = workflowLead($owner);
    $response = $this->actingAs($viewer)->get('/leads')->assertOk();
    expect($response->inertiaProps('table.page.data.0.editable'))->toBeFalse();
    expect($response->inertiaProps('table.page.data.0.status_id'))->toBe('new');
    expect($response->inertiaProps('table.page.data.0.version'))->toBe(1);
    expect($response->inertiaProps('table.page.data.0.cells.created_at'))->not->toBeNull();
    expect($response->inertiaProps('table.preferences.columns'))->toContain('created_at', 'proposal');
    $this->patch('/leads/'.$lead->id, ['status_id' => 'qualified', 'version' => 1])->assertForbidden();
    $this->actingAs($viewer)->postJson('/preferences', ['table' => 'leads', 'columns' => ['contact', 'status']])->assertOk();
    expect($this->get('/leads')->inertiaProps('table.preferences.columns'))->toBe(['contact', 'status']);
    expect($this->actingAs($owner)->get('/leads')->inertiaProps('table.page.data.0.editable'))->toBeTrue();
    $lead->update(['archived_at' => now()]);
    expect($this->get('/leads?view=archived')->inertiaProps('table.page.data.0.editable'))->toBeFalse();
});

it('stores and searches ad page and referral attribution without changing it on a stage-only update', function () {
    $user = workflowEmployee();
    $lead = workflowLead($user);
    $attribution = ['platform' => 'facebook', 'campaign_id' => 'campaign-12', 'campaign_name' => 'Autumn enquiries', 'ad_id' => 'ad-34', 'ad_name' => 'Office consultation', 'landing_page_url' => 'https://example.test/consultation', 'referrer_name' => 'Tamar Ben Ami', 'utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'autumn', 'utm_content' => 'office'];
    $this->actingAs($user)->patch('/leads/'.$lead->id, ['attribution' => $attribution, 'version' => 1])->assertRedirect();
    expect($lead->fresh()->attribution)->toEqual($attribution);
    $this->patch('/leads/'.$lead->id, ['status_id' => 'contacted', 'version' => 2])->assertRedirect();
    expect($lead->fresh()->attribution)->toEqual($attribution);
    $response = $this->get('/leads?q=Office%20consultation')->assertOk();
    expect($response->inertiaProps('table.page.total'))->toBe(1)->and($response->inertiaProps('table.page.data.0.cells.attribution:ad_id'))->toBe('ad-34');
    $this->patch('/leads/'.$lead->id, ['attribution' => null, 'version' => 3])->assertRedirect();
    expect($lead->fresh()->attribution)->toBeNull();
});

it('rejects unknown attribution keys unsafe page schemes and invalid platform values at both boundaries', function () {
    $user = workflowEmployee();
    $lead = workflowLead($user);
    foreach ([['unexpected' => 'value'], ['landing_page_url' => 'javascript:alert(1)'], ['platform' => 'unregistered'], ['ad_id' => str_repeat('x', 201)]] as $attribution) {
        $this->actingAs($user)->patch('/leads/'.$lead->id, ['attribution' => $attribution, 'version' => 1])->assertSessionHasErrors();
        try {
            app(Leads::class)->update($user, $lead->id, ['attribution' => $attribution, 'version' => 1]);
            $this->fail('The service must reject invalid attribution.');
        } catch (ValidationException) {
            expect($lead->fresh()->version)->toBe(1);
        }
    }
    expect($lead->fresh()->attribution)->toBeNull();
});
