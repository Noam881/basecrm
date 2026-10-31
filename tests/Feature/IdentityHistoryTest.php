<?php

use App\Models\User;
use Awove\Core\Models\Contact;
use Awove\Core\Models\ContactNote;
use Awove\Core\Models\IdentityActivity;
use Awove\Core\Services\Identities;
use Awove\Core\Services\IdentityHistory;
use Awove\Core\Services\Leads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function identityHistoryStaff(string $name = 'Noa Cohen'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');

    return $user;
}

it('retains only changed field names and actor history on the exact contact or company', function () {
    $owner = identityHistoryStaff();
    $service = app(Identities::class);
    $company = $service->create($owner, 'companies', ['name' => 'Levi Solutions', 'email' => 'company@example.test']);
    $contact = $service->create($owner, 'contacts', ['name' => 'Shira Levi', 'email' => 'shira@example.test', 'phone' => '+1 202-555-0170', 'company_id' => $company->id]);
    $created = IdentityActivity::where('contact_id', $contact->id)->sole();
    expect($created->company_id)->toBeNull()->and($created->actor_id)->toBe($owner->id)
        ->and($created->changed_fields)->toBe(['company_id', 'email', 'name', 'phone']);
    $service->update($owner, 'contacts', $contact->id, ['phone' => '+1 202-555-0171']);
    $updated = IdentityActivity::where('contact_id', $contact->id)->latest('id')->firstOrFail();
    expect($updated->changed_fields)->toBe(['phone'])->and($updated->event)->toBe('identity.updated');
    $service->update($owner, 'contacts', $contact->id, ['phone' => '+1 202-555-0171']);
    $service->note($owner, $contact->id, ['body' => 'Ordinary notes retain their own authorship.']);
    expect(IdentityActivity::count())->toBe(3)->and(ContactNote::count())->toBe(1);
    $encoded = IdentityActivity::all()->toJson();
    expect($encoded)->not->toContain('shira@example.test', '+1 202-555-0170', '+1 202-555-0171', 'Ordinary notes', 'old_value', 'new_value');
    $this->actingAs($owner)->get('/people/contacts/'.$contact->id)->assertInertia(fn (Assert $page) => $page
        ->component('Core/Person')->has('identityHistory', 2)->where('identityHistory.0.id', $updated->id)
        ->where('identityHistory.1.id', $created->id)->where('identityHistory.0.changed_fields', ['phone'])
        ->where('identityHistory.0.actor.name', $owner->name)->missing('identityHistory.0.contact_id')
        ->missing('identityHistory.0.company_id')->missing('identityHistory.0.actor.email'));
    $this->get('/people/companies/'.$company->id)->assertInertia(fn (Assert $page) => $page
        ->has('identityHistory', 1)->where('identityHistory.0.changed_fields', ['email', 'name']));
    expect(fn () => $updated->update(['event' => 'Replaced']))->toThrow(LogicException::class);
    expect(fn () => $updated->delete())->toThrow(LogicException::class);
    $service->update($owner, 'contacts', $contact->id, ['phone' => null]);
    expect($contact->fresh()->phone)->toBeNull()
        ->and(IdentityActivity::where('contact_id', $contact->id)->latest('id')->firstOrFail()->changed_fields)->toBe(['phone']);
});

it('requires the same identity authorization for every history read and shows no parent history through a contact', function () {
    $owner = identityHistoryStaff();
    $other = identityHistoryStaff('Uri Ben David');
    $company = app(Identities::class)->create($owner, 'companies', ['name' => 'Cohen Studio', 'email' => 'studio@example.test']);
    $contact = app(Identities::class)->create($owner, 'contacts', ['name' => 'Yael Shalev', 'company_id' => $company->id]);
    $companyEntry = IdentityActivity::where('company_id', $company->id)->sole();
    $this->actingAs($other)->get('/people/contacts/'.$contact->id)->assertNotFound();
    expect(fn () => app(IdentityHistory::class)->forIdentity($other, $contact))->toThrow(AuthorizationException::class);
    $owner->givePermissionTo('leads.assign');
    app(Leads::class)->create($owner, ['contact_id' => $contact->id, 'owner_id' => $other->id, 'status_id' => 'new']);
    $this->get('/people/contacts/'.$contact->id)->assertInertia(fn (Assert $page) => $page
        ->where('editable', false)->has('identityHistory', 1)->where('identityHistory.0.event', 'identity.created')
        ->where('identityHistory.0.id', fn ($id) => $id !== $companyEntry->id));
    $this->get('/people/companies/'.$company->id)->assertNotFound();
    $other->active = false;
    expect(fn () => app(IdentityHistory::class)->forIdentity($other, $contact))->toThrow(AuthorizationException::class);
});

it('rolls back a contact and its initial note when audit persistence fails', function () {
    $owner = identityHistoryStaff();
    $history = Mockery::mock(IdentityHistory::class);
    $history->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure.'));
    app()->instance(IdentityHistory::class, $history);
    expect(fn () => app(Identities::class)->create($owner, 'contacts', ['name' => 'Tamar Erez', 'notes' => 'Must not be partially saved.']))->toThrow(RuntimeException::class);
    expect(Contact::count())->toBe(0)->and(ContactNote::count())->toBe(0)->and(IdentityActivity::count())->toBe(0);
});

it('rolls back an identity edit if its audit cannot be retained', function () {
    $owner = identityHistoryStaff();
    $company = app(Identities::class)->create($owner, 'companies', ['name' => 'Erez Solutions', 'phone' => '+1 202-555-0172']);
    $history = Mockery::mock(IdentityHistory::class);
    $history->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure.'));
    app()->instance(IdentityHistory::class, $history);
    expect(fn () => app(Identities::class)->update($owner, 'companies', $company->id, ['phone' => '+1 202-555-0173']))->toThrow(RuntimeException::class);
    expect($company->fresh()->phone)->toBe('+1 202-555-0172')->and(IdentityActivity::count())->toBe(1);
});

it('enforces exactly one retained identity reference in PostgreSQL', function () {
    $owner = identityHistoryStaff();
    $company = app(Identities::class)->create($owner, 'companies', ['name' => 'Shalev Studio']);
    $contact = app(Identities::class)->create($owner, 'contacts', ['name' => 'Yael Levi']);
    foreach ([['contact_id' => null, 'company_id' => null], ['contact_id' => $contact->id, 'company_id' => $company->id]] as $references) {
        expect(fn () => DB::transaction(fn () => DB::table('crm_identity_activity')->insert([...$references, 'actor_id' => $owner->id, 'event' => 'identity.updated', 'changed_fields' => json_encode(['name'])])))->toThrow(QueryException::class);
    }
    expect(IdentityActivity::count())->toBe(2);
});
