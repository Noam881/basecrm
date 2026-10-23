<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Services\Leads;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function identityEmployee(): User
{
    $user = User::factory()->create(['active' => true]);
    $user->assignRole('Employee');

    return $user;
}

function identityContact(User $user, string $name = 'Fictional contact', array $extra = []): Contact
{
    return Contact::create([...['name' => $name, 'created_by' => $user->id, 'search_key' => 'fictionalcontact'], ...$extra]);
}

function identityCompany(User $user, string $name = 'Fictional company'): Company
{
    return Company::create(['name' => $name, 'created_by' => $user->id, 'search_key' => 'fictionalcompany']);
}

it('edits an owned contact and company and refreshes normalized details', function () {
    $user = identityEmployee();
    $contact = identityContact($user);
    $company = identityCompany($user);
    $this->actingAs($user)->get('/people/contacts/'.$contact->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Core/Person')->where('editable', true)->where('identity.name', $contact->name)->where('type', 'contacts'));
    $this->patch('/people/contacts/'.$contact->id, ['name' => 'Changed contact', 'email' => 'Changed@Example.Test', 'phone' => '+357 99 123 456', 'company_id' => $company->id, 'created_by' => 999])->assertRedirect('/people?table=contacts');
    $contact->refresh();
    expect($contact->name)->toBe('Changed contact')->and($contact->email)->toBe('changed@example.test')->and($contact->phone)->toBe('+357 99 123 456')->and($contact->company_id)->toBe($company->id)->and($contact->created_by)->toBe($user->id)->and($contact->search_key)->toContain('changedcontactchanged@example.test35799123456');
    $this->get('/people?table=contacts&q=changed%40example.test')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('table.page.data.0.cells.phone', '+357 99 123 456'));
    $this->patch('/people/contacts/'.$contact->id, ['phone' => null, 'email' => null, 'company_id' => null])->assertRedirect();
    expect($contact->fresh()->phone)->toBeNull()->and($contact->fresh()->email)->toBeNull()->and($contact->fresh()->company_id)->toBeNull();
    $this->patch('/people/companies/'.$company->id, ['name' => 'Changed studio', 'email' => 'Studio@Example.Test', 'phone' => '+357 25 123456', 'company_id' => null])->assertRedirect('/people?table=companies');
    expect($company->fresh()->name)->toBe('Changed studio')->and($company->fresh()->email)->toBe('studio@example.test');
});

it('keeps viewing and editing identities separate even when a contact is shared with an owned lead', function () {
    $owner = identityEmployee();
    $reader = identityEmployee();
    $reader->givePermissionTo('leads.view.all');
    $contact = identityContact($owner);
    $lead = app(Leads::class)->create($reader, ['title' => 'Shared contact enquiry', 'owner_id' => $reader->id, 'contact_id' => $contact->id, 'status_id' => 'new', 'source' => 'Direct']);
    $this->actingAs($reader)->get('/people/contacts/'.$contact->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('editable', false));
    $this->get('/leads/'.$lead->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('editable', true)->where('identityPermissions.contact', false));
    $this->patch('/people/contacts/'.$contact->id, ['phone' => 'forged'])->assertForbidden();
    expect($contact->fresh()->phone)->toBeNull();
    $reader->revokePermissionTo('leads.view.all');
    $this->get('/people/contacts/'.$contact->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('editable', false));
});

it('requires edit permission for the creators own identities and hides unrelated records', function () {
    $user = identityEmployee();
    $foreign = identityEmployee();
    $contact = identityContact($user);
    $other = identityContact($foreign, 'Hidden contact');
    $user->syncPermissions([]);
    $user->syncRoles([]);
    $user->givePermissionTo('leads.view.all');
    $this->actingAs($user)->get('/people/contacts/'.$contact->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('editable', false));
    $this->patch('/people/contacts/'.$contact->id, ['name' => 'No edit permission'])->assertForbidden();
    $this->get('/people/contacts/'.$other->id)->assertNotFound();
    $this->patch('/people/contacts/'.$other->id, ['phone' => 'forged'])->assertForbidden();
    $this->get('/people/companies/'.$contact->id)->assertNotFound();
});

it('allows identity managers to edit other creators and denies inactive users', function () {
    $manager = identityEmployee();
    $manager->givePermissionTo('identities.manage');
    $contact = identityContact(identityEmployee());
    $this->actingAs($manager)->get('/people/contacts/'.$contact->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('editable', true));
    $this->patch('/people/contacts/'.$contact->id, ['phone' => '+357 99 000111'])->assertRedirect();
    expect($contact->fresh()->phone)->toBe('+357 99 000111');
    $manager->forceFill(['active' => false])->save();
    $this->patch('/people/contacts/'.$contact->id, ['phone' => 'inactive'])->assertForbidden();
});

it('validates changes and duplicate confirmation without leaking hidden identities', function () {
    $user = identityEmployee();
    $contact = identityContact($user);
    identityContact($user, 'Existing customer', ['phone' => '+357 99 123 456']);
    $this->actingAs($user)->patch('/people/contacts/'.$contact->id, ['phone' => '+357-99-123-456'])->assertSessionHasErrors('name');
    expect($contact->fresh()->phone)->toBeNull();
    $this->patch('/people/contacts/'.$contact->id, ['phone' => '+357-99-123-456', 'accept_duplicate' => true])->assertRedirect();
    $this->patch('/people/contacts/'.$contact->id, ['name' => ' ', 'email' => 'invalid', 'phone' => str_repeat('1', 41)])->assertSessionHasErrors(['email', 'phone']);
    $this->patch('/people/contacts/'.$contact->id, ['name' => ' '])->assertSessionHasErrors('name');
    identityContact(identityEmployee(), 'Hidden duplicate', ['email' => 'hidden@example.test']);
    $this->patch('/people/contacts/'.$contact->id, ['email' => 'hidden@example.test', 'phone' => null])->assertRedirect();
    expect($contact->fresh()->email)->toBe('hidden@example.test');
});

it('scopes company reassignment and returns from editing to an authorized lead', function () {
    $user = identityEmployee();
    $other = identityEmployee();
    $contact = identityContact($user);
    $hidden = identityCompany($other);
    $lead = app(Leads::class)->create($user, ['title' => 'Editable enquiry', 'owner_id' => $user->id, 'contact_id' => $contact->id, 'status_id' => 'new', 'source' => 'Direct']);
    $hiddenLead = app(Leads::class)->create($other, ['title' => 'Hidden enquiry', 'owner_id' => $other->id, 'company_id' => $hidden->id, 'status_id' => 'new', 'source' => 'Direct']);
    $this->actingAs($user)->get('/leads/'.$lead->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('identityPermissions.contact', true)->where('identityPermissions.company', false));
    $this->get('/people/contacts/'.$contact->id.'?from_lead='.$lead->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('returnTo', '/leads/'.$lead->id)->where('saveUrl', '/people/contacts/'.$contact->id.'?from_lead='.$lead->id)->has('companyOptions', 0));
    $this->patch('/people/contacts/'.$contact->id, ['company_id' => 0])->assertSessionHasErrors('company_id');
    $this->patch('/people/contacts/'.$contact->id, ['company_id' => $hidden->id])->assertForbidden();
    expect($contact->fresh()->company_id)->toBeNull();
    $this->patch('/people/contacts/'.$contact->id.'?from_lead='.$lead->id, ['phone' => '+357 99 222333'])->assertRedirect('/leads/'.$lead->id);
    $this->get('/leads/'.$lead->id)->assertInertia(fn (Assert $p) => $p->where('lead.contact.phone', '+357 99 222333'));
    $this->patch('/people/contacts/'.$contact->id.'?from_lead='.$hiddenLead->id, ['phone' => 'forged'])->assertForbidden();
    expect($contact->fresh()->phone)->toBe('+357 99 222333');
});

it('preserves a restricted existing company link while editing other contact details', function () {
    $user = identityEmployee();
    $company = identityCompany(identityEmployee(), 'Hidden company name');
    $contact = identityContact($user, 'Existing customer', ['company_id' => $company->id]);
    $response = $this->actingAs($user)->get('/people/contacts/'.$contact->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('editable', true)->where('companyOptions.0.name', 'Current company (restricted)'));
    expect(json_encode($response->inertiaProps('companyOptions')))->not->toContain('Hidden company name');
    $this->patch('/people/contacts/'.$contact->id, ['company_id' => $company->id, 'email' => 'changed@example.test'])->assertRedirect();
    expect($contact->fresh()->company_id)->toBe($company->id)->and($contact->fresh()->email)->toBe('changed@example.test');
});

it('bounds derived search keys while preserving valid long customer details on create and update', function () {
    $user = identityEmployee();
    $details = ['name' => str_repeat('א', 120), 'email' => str_repeat('a', 60).'@'.str_repeat('b', 60).'.test', 'phone' => '+357 99 123 456'];
    $this->actingAs($user)->post('/people/companies', $details)->assertRedirect('/people?table=companies');
    $company = Company::sole();
    expect($company->name)->toBe($details['name'])->and($company->email)->toBe($details['email'])->and($company->phone)->toBe($details['phone'])->and(mb_strlen($company->search_key))->toBe(255);
    $contact = identityContact($user);
    $this->patch('/people/contacts/'.$contact->id, $details)->assertRedirect('/people?table=contacts');
    $contact->refresh();
    expect($contact->name)->toBe($details['name'])->and($contact->email)->toBe($details['email'])->and($contact->phone)->toBe($details['phone'])->and($contact->search_key)->toBe($company->search_key);
});
