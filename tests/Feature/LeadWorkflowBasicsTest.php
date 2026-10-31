<?php

use App\Models\User;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Leads;
use Illuminate\Support\Facades\DB;
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

it('keeps frozen legacy attribution private and unchanged through shared stage updates', function () {
    $user = workflowEmployee();
    $lead = workflowLead($user);
    $raw = ['campaign_id' => '00034', 'campaign_name' => 'Original name', 'ad_id' => 44, 'extra' => ['keep' => true]];
    DB::table('crm_leads')->where('id', $lead->id)->update(['attribution' => json_encode($raw)]);
    $this->actingAs($user)->patch('/leads/'.$lead->id, ['status_id' => 'contacted', 'version' => 1])->assertRedirect();
    expect($lead->fresh()->attribution)->toEqual($raw)->and($lead->fresh()->toArray())->not->toHaveKey('attribution');
    $response = $this->get('/leads')->assertOk();
    expect($response->inertiaProps('table.page.data.0'))->not->toHaveKey('attribution');
    $this->get('/leads/'.$lead->id)->assertOk();
});
it('rejects all legacy attribution input at both shared mutation boundaries', function () {
    $user = workflowEmployee();
    $lead = workflowLead($user);
    foreach ([null, [], ['ad_id' => 'ad-34']] as $attribution) {
        $this->actingAs($user)->patch('/leads/'.$lead->id, ['attribution' => $attribution, 'version' => 1])->assertSessionHasErrors('attribution');
        expect(fn () => app(Leads::class)->update($user, $lead->id, ['attribution' => $attribution, 'version' => 1]))->toThrow(ValidationException::class);
        expect(fn () => app(Leads::class)->create($user, ['attribution' => $attribution, 'title' => 'Example', 'status_id' => 'new', 'source' => 'Website']))->toThrow(ValidationException::class);
    }
    expect($lead->fresh()->version)->toBe(1)->and($lead->fresh()->attribution)->toBeNull();
});
