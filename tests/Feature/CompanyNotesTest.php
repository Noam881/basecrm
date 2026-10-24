<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\CompanyNote;
use Awove\Core\Models\Contact;
use Awove\Core\Models\ContactNote;
use Awove\Core\Models\IdentityActivity;
use Awove\Core\Services\Identities;
use Awove\Core\Services\IdentityHistory;
use Awove\Core\Services\Leads;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function companyNotesStaff(string $name = 'Shira Levi'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');

    return $user;
}

it('creates and displays authored company notes through the shared identity profile', function () {
    $owner = companyNotesStaff();
    $this->actingAs($owner)->post('/people/companies', ['name' => 'Levi Solutions', 'email' => 'levi@example.test', 'notes' => '  Requested a fictional consultation.  ', 'author_id' => 999])->assertRedirect();
    $company = Company::sole();
    $note = CompanyNote::sole();
    expect($note->body)->toBe('Requested a fictional consultation.')->and($note->author_id)->toBe($owner->id)->and($note->company_id)->toBe($company->id)
        ->and($company->notes()->sole()->id)->toBe($note->id)->and(ContactNote::count())->toBe(0);
    $this->get('/people/companies/'.$company->id)->assertInertia(fn (Assert $page) => $page
        ->component('Core/Person')->where('type', 'companies')->where('identity.notes.0.body', $note->body)
        ->where('identity.notes.0.author.id', $owner->id)->where('noteUrl', '/people/companies/'.$company->id.'/notes')
        ->missing('identity.notes.0.author.email'));
    expect(IdentityActivity::sole()->changed_fields)->toBe(['email', 'name']);
});

it('appends notes without replacing existing history or leaking company notes into a related contact', function () {
    $owner = companyNotesStaff();
    $company = app(Identities::class)->create($owner, 'companies', ['name' => 'Cohen Studio', 'notes' => 'First company conversation.']);
    $contact = app(Identities::class)->create($owner, 'contacts', ['name' => 'Noa Cohen', 'company_id' => $company->id, 'notes' => 'Separate contact conversation.']);
    $this->actingAs($owner)->post('/people/companies/'.$company->id.'/notes', ['body' => '  Agreed the next company step.  ', 'author_id' => 999, 'company_id' => 999])->assertRedirect('/people/companies/'.$company->id);
    $this->patch('/people/companies/'.$company->id, ['phone' => '+1 202-555-0170', 'notes' => 'Replace notes'])->assertSessionHasErrors('notes');
    expect($company->fresh()->phone)->toBeNull();
    $this->patch('/people/companies/'.$company->id, ['phone' => '+1 202-555-0170', 'notes' => []])->assertSessionHasNoErrors();
    expect(CompanyNote::count())->toBe(2)->and(ContactNote::count())->toBe(1);
    $this->get('/people/companies/'.$company->id)->assertInertia(fn (Assert $page) => $page->has('identity.notes', 2)
        ->where('identity.notes.0.body', 'Agreed the next company step.')->where('identity.notes.1.body', 'First company conversation.'));
    $this->get('/people/contacts/'.$contact->id)->assertInertia(fn (Assert $page) => $page->has('identity.notes', 1)
        ->where('identity.notes.0.body', 'Separate contact conversation.'));
    expect(CompanyNote::latest('id')->firstOrFail()->author_id)->toBe($owner->id);
});

it('scopes company note reads and writes to the company policy including read-only and inactive staff', function () {
    $owner = companyNotesStaff();
    $reader = companyNotesStaff('Uri Ben David');
    $unrelated = companyNotesStaff('Tamar Erez');
    $company = app(Identities::class)->create($owner, 'companies', ['name' => 'Erez Solutions', 'notes' => 'A fictional company conversation.']);
    $owner->givePermissionTo(['leads.assign', 'leads.view.all']);
    $lead = app(Leads::class)->create($owner, ['company_id' => $company->id, 'owner_id' => $reader->id, 'status_id' => 'new']);
    $this->actingAs($reader)->get('/people/companies/'.$company->id)->assertInertia(fn (Assert $page) => $page
        ->where('editable', false)->where('identity.notes.0.body', 'A fictional company conversation.'));
    $this->post('/people/companies/'.$company->id.'/notes', ['body' => 'Restricted'])->assertForbidden();
    $this->actingAs($unrelated)->get('/people/companies/'.$company->id)->assertNotFound();
    $this->post('/people/companies/'.$company->id.'/notes', ['body' => 'Restricted'])->assertForbidden();
    $this->actingAs($owner)->post('/people/companies/'.$company->id.'/notes?from_lead='.$lead->id, ['body' => 'Owner note with context.'])->assertRedirect('/people/companies/'.$company->id.'?from_lead='.$lead->id);
    $owner->active = false;
    $owner->save();
    $this->post('/people/companies/'.$company->id.'/notes', ['body' => 'Inactive'])->assertForbidden();
    expect(CompanyNote::count())->toBe(2);
});

it('validates note content without partially creating or editing a company', function () {
    $owner = companyNotesStaff();
    $this->actingAs($owner)->post('/people/companies', ['name' => 'Shalev Studio', 'notes' => str_repeat('x', 10001)])->assertSessionHasErrors('notes');
    expect(Company::count())->toBe(0)->and(CompanyNote::count())->toBe(0);
    $company = app(Identities::class)->create($owner, 'companies', ['name' => 'Shalev Studio']);
    foreach (['', '  ', str_repeat('x', 10001)] as $body) {
        $this->post('/people/companies/'.$company->id.'/notes', ['body' => $body])->assertSessionHasErrors('body');
    }
    expect(CompanyNote::count())->toBe(0);
});

it('rolls back the company and initial note if its audit cannot be persisted', function () {
    $owner = companyNotesStaff();
    $history = Mockery::mock(IdentityHistory::class);
    $history->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit persistence failure.'));
    app()->instance(IdentityHistory::class, $history);
    expect(fn () => app(Identities::class)->create($owner, 'companies', ['name' => 'Levi Studio', 'notes' => 'Must roll back.']))->toThrow(RuntimeException::class);
    expect(Company::count())->toBe(0)->and(CompanyNote::count())->toBe(0)->and(IdentityActivity::count())->toBe(0)->and(Contact::count())->toBe(0);
});
