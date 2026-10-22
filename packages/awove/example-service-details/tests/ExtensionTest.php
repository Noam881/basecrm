<?php

use App\Models\User;
use Awove\Core\Events\LeadCreated;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Modules;
use Awove\Core\Services\Leads;
use Awove\ServiceDetails\ServiceDetailsModule;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    $this->artisan('crm:install');
});
function extensionLead($u)
{
    $c = Contact::create(['name' => 'Synthetic service customer', 'created_by' => $u->id, 'search_key' => 'service']);

    return app(Leads::class)->create($u, ['title' => 'Service request', 'contact_id' => $c->id, 'owner_id' => $u->id, 'status_id' => 'new']);
}
function extensionUser()
{
    $u = User::factory()->create(['active' => true]);
    $u->assignRole('Employee');
    $u->givePermissionTo(['service-details.view', 'service-details.edit']);

    return $u;
}
it('integrates panels, parent policies and persistent module data across activation', function () {
    config(['core.installation' => 'beta']);
    $u = extensionUser();
    $lead = extensionLead($u);
    event(new LeadCreated($lead->id, $u->id));
    expect(DB::table('service_details_leads')->where('lead_id', $lead->id)->value('created_from_event'))->toBeTrue();
    $this->actingAs($u)->put('/modules/service-details/leads/'.$lead->id, ['service_reference' => 'REF-001', 'requested_service' => 'Maintenance'])->assertOk();
    expect(app(Modules::class)->panels($u))->toHaveCount(1);
    $other = extensionUser();
    $this->actingAs($other)->get('/modules/service-details/leads/'.$lead->id)->assertForbidden();
    $this->put('/modules/service-details/leads/'.$lead->id, [])->assertForbidden();
    config(['core.installation' => 'alpha']);
    $this->actingAs($u)->get('/modules/service-details/leads/'.$lead->id)->assertNotFound();
    expect(app(Modules::class)->panels($u))->toHaveCount(0);
    $second = extensionLead($u);
    event(new LeadCreated($second->id, $u->id));
    expect(DB::table('service_details_leads')->where('lead_id', $second->id)->exists())->toBeFalse();
    config(['core.installation' => 'beta']);
    $this->get('/modules/service-details/leads/'.$lead->id)->assertOk()->assertJsonPath('service_reference', 'REF-001');
    $u->revokePermissionTo('service-details.view');
    $this->get('/modules/service-details/leads/'.$lead->id)->assertForbidden();
});
it('fails clearly for an absent required module', function () {
    config(['core.installation' => 'beta']);
    $modules = new Modules;
    expect(fn () => $modules->validate())->toThrow(RuntimeException::class, 'not installed');
});
it('rejects incompatible installed code before activation', function () {
    config(['core.installation' => 'beta']);
    $modules = new Modules;
    $modules->register(new class extends ServiceDetailsModule
    {
        public function contract(): int
        {
            return 999;
        }
    });
    expect(fn () => $modules->validate())->toThrow(RuntimeException::class, 'incompatible');
});
it('uses two brands and vocabularies with the same core', function () {
    config(['core.installation' => 'alpha']);
    $a = Installation::manifest();
    config(['core.installation' => 'beta']);
    $b = Installation::manifest();
    expect($a['color'])->not->toBe($b['color'])->and($a['modules'])->toBe([])->and($b['modules'])->toBe(['service-details'])->and($b['lead_label'])->toBe('Enquiries');
});
