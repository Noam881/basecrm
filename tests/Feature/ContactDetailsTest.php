<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\ContactNote;
use Awove\Core\Services\Leads;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function contactDetailsEmployee(string $name = 'Noa Cohen'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');

    return $user;
}

function contactDetailsPayload(array $overrides = []): array
{
    return [...[
        'name' => 'Shira Levi',
        'email' => 'Shira@Example.Test',
        'phone' => '+1 202-555-0170',
        'secondary_phone' => '+1 202-555-0171',
        'secondary_phone_label' => 'office',
        'preferred_contact_method' => 'whatsapp',
        'preferred_contact_times' => ['mornings', 'evenings'],
        'preferred_contact_days' => ['monday', 'saturday', 'sunday'],
        'address' => '12 Olive Street, Office 4',
        'city' => 'Tel Aviv',
        'country' => 'Israel',
        'source' => 'referral',
    ], ...$overrides];
}

it('creates a complete contact with an authored initial note and exposes the saved profile', function () {
    $user = contactDetailsEmployee();
    $company = Company::create(['name' => 'Levi Solutions', 'created_by' => $user->id, 'search_key' => 'levisolutions']);
    $details = contactDetailsPayload(['company_id' => $company->id]);
    $response = $this->actingAs($user)->post('/people/contacts', [...$details, 'notes' => '  Ask about the new office.  ', 'created_by' => 999]);
    $contact = Contact::sole();
    $response->assertRedirect('/people/contacts/'.$contact->id);
    expect($contact->only(array_keys($details)))->toBe([...$details, 'email' => 'shira@example.test'])
        ->and($contact->created_by)->toBe($user->id);
    $note = ContactNote::sole();
    expect($note->contact_id)->toBe($contact->id)->and($note->author_id)->toBe($user->id)->and($note->body)->toBe('Ask about the new office.');
    $this->get('/people/contacts/'.$contact->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Core/Person')->where('editable', true)->where('identity.email', 'shira@example.test')
        ->where('identity.secondary_phone', $details['secondary_phone'])->where('identity.secondary_phone_label', 'office')
        ->where('identity.preferred_contact_method', 'whatsapp')->where('identity.preferred_contact_times', ['mornings', 'evenings'])
        ->where('identity.preferred_contact_days', ['monday', 'saturday', 'sunday'])->where('identity.address', $details['address'])
        ->where('identity.city', 'Tel Aviv')->where('identity.country', 'Israel')->where('identity.source', 'referral')
        ->where('identity.company_id', $company->id)->where('identity.notes.0.body', 'Ask about the new office.')
        ->where('identity.notes.0.author.id', $user->id)->where('identity.notes.0.author.name', $user->name)
        ->where('noteUrl', '/people/contacts/'.$contact->id.'/notes')->has('contactOptions.sources')
        ->has('contactOptions.times', 4)->has('contactOptions.days', 7));
});

it('preserves omitted details, clears optional fields explicitly and keeps contact note history', function () {
    $user = contactDetailsEmployee();
    $this->actingAs($user)->post('/people/contacts', [...contactDetailsPayload(), 'notes' => 'First conversation.'])->assertRedirect();
    $contact = Contact::sole();
    $this->patch('/people/contacts/'.$contact->id, ['city' => 'Jerusalem'])->assertRedirect();
    expect($contact->fresh()->city)->toBe('Jerusalem')->and($contact->fresh()->secondary_phone)->toBe('+1 202-555-0171')
        ->and($contact->fresh()->preferred_contact_times)->toBe(['mornings', 'evenings'])->and(ContactNote::sole()->body)->toBe('First conversation.');
    $this->post('/people/contacts/'.$contact->id.'/notes', ['body' => '  Call after the office move.  ', 'author_id' => 999, 'contact_id' => 999])->assertRedirect('/people/contacts/'.$contact->id);
    expect(ContactNote::where('contact_id', $contact->id)->count())->toBe(2)
        ->and(ContactNote::where('body', 'Call after the office move.')->sole()->author_id)->toBe($user->id);
    $clear = array_fill_keys(['email', 'phone', 'secondary_phone', 'secondary_phone_label', 'preferred_contact_method', 'address', 'city', 'country', 'source', 'company_id'], null);
    $clear['preferred_contact_times'] = [];
    $clear['preferred_contact_days'] = [];
    $this->patch('/people/contacts/'.$contact->id, $clear)->assertRedirect();
    expect($contact->fresh()->only(array_keys($clear)))->toBe($clear)->and(ContactNote::count())->toBe(2);
    $this->get('/people/contacts/'.$contact->id)->assertInertia(fn (Assert $page) => $page->has('identity.notes', 2)->where('identity.notes.0.body', 'Call after the office move.')->where('identity.notes.1.body', 'First conversation.'));
});

it('rejects invalid contact choices and oversized input without partially creating or updating a record', function (array $invalid, string $error) {
    $user = contactDetailsEmployee();
    $this->actingAs($user)->post('/people/contacts', [...contactDetailsPayload(), 'notes' => 'Not committed.', ...$invalid])->assertSessionHasErrors($error);
    expect(Contact::count())->toBe(0)->and(ContactNote::count())->toBe(0);
    $contact = Contact::create(['name' => 'Uri Ben David', 'created_by' => $user->id, 'search_key' => 'uribendavid']);
    $this->patch('/people/contacts/'.$contact->id, ['city' => 'Must not be saved', ...$invalid])->assertSessionHasErrors($error);
    expect($contact->fresh()->city)->toBeNull()->and(ContactNote::count())->toBe(0);
})->with([
    'unknown method' => [['preferred_contact_method' => 'telegram'], 'preferred_contact_method'],
    'unknown secondary phone type' => [['secondary_phone_label' => 'fax'], 'secondary_phone_label'],
    'unknown time' => [['preferred_contact_times' => ['midnight']], 'preferred_contact_times.0'],
    'duplicate times' => [['preferred_contact_times' => ['mornings', 'mornings']], 'preferred_contact_times.0'],
    'too many times' => [['preferred_contact_times' => ['mornings', 'afternoons', 'evenings', 'anytime', 'mornings']], 'preferred_contact_times'],
    'time must be an array' => [['preferred_contact_times' => 'mornings'], 'preferred_contact_times'],
    'anytime mixed with time' => [['preferred_contact_times' => ['anytime', 'mornings']], 'preferred_contact_times'],
    'unknown weekday' => [['preferred_contact_days' => ['weekends']], 'preferred_contact_days.0'],
    'duplicate weekdays' => [['preferred_contact_days' => ['monday', 'monday']], 'preferred_contact_days.0'],
    'too many weekdays' => [['preferred_contact_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'monday']], 'preferred_contact_days'],
    'unknown discovery source' => [['source' => 'unknown-source'], 'source'],
    'long secondary phone' => [['secondary_phone' => str_repeat('1', 41)], 'secondary_phone'],
    'long address' => [['address' => str_repeat('a', 501)], 'address'],
    'long city' => [['city' => str_repeat('a', 121)], 'city'],
    'long country' => [['country' => str_repeat('a', 121)], 'country'],
]);

it('validates contact notes on create and append and never replaces history through an edit', function () {
    $user = contactDetailsEmployee();
    $this->actingAs($user)->post('/people/contacts', [...contactDetailsPayload(), 'notes' => str_repeat('a', 10001)])->assertSessionHasErrors('notes');
    expect(Contact::count())->toBe(0);
    $this->post('/people/contacts', [...contactDetailsPayload(), 'notes' => 'Original conversation.'])->assertRedirect();
    $contact = Contact::sole();
    foreach (['', '   ', str_repeat('a', 10001)] as $body) {
        $this->post('/people/contacts/'.$contact->id.'/notes', ['body' => $body])->assertSessionHasErrors('body');
    }
    $this->patch('/people/contacts/'.$contact->id, ['city' => 'Haifa', 'notes' => 'Replace history'])->assertSessionHasErrors('notes');
    expect(ContactNote::sole()->body)->toBe('Original conversation.')->and($contact->fresh()->city)->toBe('Tel Aviv');
});

it('ignores empty notes fields on contact edits without replacing history', function () {
    $user = contactDetailsEmployee();
    $this->actingAs($user)->post('/people/contacts', [...contactDetailsPayload(), 'notes' => 'Keep the original conversation.'])->assertRedirect();
    $contact = Contact::sole();
    foreach ([null, '', []] as $empty) {
        $this->patch('/people/contacts/'.$contact->id, ['city' => 'Haifa', 'notes' => $empty])->assertRedirect('/people/contacts/'.$contact->id)->assertSessionHasNoErrors();
        expect($contact->fresh()->city)->toBe('Haifa')
            ->and(ContactNote::sole()->body)->toBe('Keep the original conversation.');
    }
});

it('strips empty contact-only fields from company create and edit payloads', function () {
    $user = contactDetailsEmployee();
    $contactOnly = array_diff(array_keys(contactDetailsPayload()), ['name', 'email', 'phone']);
    foreach ([null, '', []] as $index => $empty) {
        $emptyFields = array_fill_keys([...$contactOnly, 'company_id', 'notes'], $empty);
        $email = 'office'.$index.'@example.test';
        $response = $this->actingAs($user)->post('/people/companies', ['name' => 'Levi Solutions', 'email' => $email, ...$emptyFields, 'accept_duplicate' => true]);
        $company = Company::where('email', $email)->sole();
        $response->assertRedirect('/people/companies/'.$company->id)->assertSessionHasNoErrors();
        $this->patch('/people/companies/'.$company->id, ['phone' => '+1 202-555-0172', ...$emptyFields, 'accept_duplicate' => true])
            ->assertRedirect('/people/companies/'.$company->id)->assertSessionHasNoErrors();
        expect($company->fresh()->phone)->toBe('+1 202-555-0172')->and($company->fresh()->email)->toBe($email);
    }
    expect(Company::count())->toBe(3)->and(ContactNote::count())->toBe(0);
});

it('keeps contact details and notes scoped, allowing readers to view but never change a shared contact', function () {
    $owner = contactDetailsEmployee('Shira Levi');
    $reader = contactDetailsEmployee('Uri Ben David');
    $unrelated = contactDetailsEmployee('Noa Cohen');
    $this->actingAs($owner)->post('/people/contacts', [...contactDetailsPayload(), 'notes' => 'Private contact conversation.'])->assertRedirect();
    $contact = Contact::sole();
    app(Leads::class)->create($owner, ['contact_id' => $contact->id, 'owner_id' => $owner->id, 'status_id' => 'new']);
    $reader->givePermissionTo('leads.view.all');
    app(Leads::class)->create($reader, ['contact_id' => $contact->id, 'owner_id' => $reader->id, 'status_id' => 'new']);
    $reader->revokePermissionTo('leads.view.all');
    $this->actingAs($reader)->get('/people/contacts/'.$contact->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('editable', false)->where('identity.secondary_phone', '+1 202-555-0171')->where('identity.notes.0.body', 'Private contact conversation.'));
    $this->patch('/people/contacts/'.$contact->id, ['secondary_phone' => 'forged'])->assertForbidden();
    $this->post('/people/contacts/'.$contact->id.'/notes', ['body' => 'Forged note'])->assertForbidden();
    $this->actingAs($unrelated)->get('/people/contacts/'.$contact->id)->assertNotFound();
    $this->post('/people/contacts/'.$contact->id.'/notes', ['body' => 'Forged note'])->assertForbidden();
    expect($contact->fresh()->secondary_phone)->toBe('+1 202-555-0171')->and(ContactNote::count())->toBe(1);
    $owner->forceFill(['active' => false])->save();
    $this->actingAs($owner)->post('/people/contacts/'.$contact->id.'/notes', ['body' => 'Inactive note'])->assertForbidden();
    expect(ContactNote::count())->toBe(1);
});

it('rejects contact-only details on companies while retaining ordinary company edits', function () {
    $user = contactDetailsEmployee();
    $this->actingAs($user)->post('/people/companies', ['name' => 'Levi Solutions', 'email' => 'office@example.test', 'phone' => '+1 202-555-0170'])->assertRedirect();
    $company = Company::sole();
    $contactOnly = contactDetailsPayload();
    unset($contactOnly['name'], $contactOnly['email'], $contactOnly['phone']);
    $contactOnly['notes'] = 'A contact-only initial note.';
    foreach ($contactOnly as $field => $value) {
        $this->post('/people/companies', ['name' => 'Cohen Studio', $field => $value])->assertSessionHasErrors($field);
        $this->patch('/people/companies/'.$company->id, [$field => $value])->assertSessionHasErrors($field);
    }
    expect(Company::count())->toBe(1)->and(ContactNote::count())->toBe(0);
    $this->patch('/people/companies/'.$company->id, ['phone' => '+1 202-555-0172'])->assertRedirect();
    expect($company->fresh()->phone)->toBe('+1 202-555-0172');
    $this->post('/people/companies/'.$company->id.'/notes', ['body' => 'No company note endpoint'])->assertNotFound();
});

it('warns about normalized phone duplicates across both contact phone slots without making shared numbers unique', function (string $existingSlot, string $newSlot) {
    $user = contactDetailsEmployee();
    $this->actingAs($user)->post('/people/contacts', ['name' => 'Shira Levi', $existingSlot => '+1 202-555-0170'])->assertRedirect();
    $this->post('/people/contacts', ['name' => 'Uri Ben David', $newSlot => '+1 (202) 555 0170'])->assertSessionHasErrors('name');
    expect(Contact::count())->toBe(1);
    $this->post('/people/contacts', ['name' => 'Uri Ben David', $newSlot => '+1 (202) 555 0170', 'accept_duplicate' => true])->assertRedirect();
    expect(Contact::count())->toBe(2);
    $other = Contact::create(['name' => 'Noa Cohen', 'created_by' => $user->id, 'search_key' => 'noacohen']);
    $this->patch('/people/contacts/'.$other->id, [$newSlot => '+1.202.555.0170'])->assertSessionHasErrors('name');
    expect($other->fresh()->getAttribute($newSlot))->toBeNull();
})->with([
    'primary to primary' => ['phone', 'phone'],
    'primary to secondary' => ['phone', 'secondary_phone'],
    'secondary to primary' => ['secondary_phone', 'phone'],
    'secondary to secondary' => ['secondary_phone', 'secondary_phone'],
]);

it('keeps duplicate warnings scoped and returns contact edits and appended notes to an authorized lead', function () {
    $owner = contactDetailsEmployee();
    $other = contactDetailsEmployee();
    $this->actingAs($owner)->post('/people/contacts', contactDetailsPayload())->assertRedirect();
    $contact = Contact::sole();
    $this->actingAs($other)->post('/people/contacts', ['name' => 'Uri Ben David', 'secondary_phone' => '+1 202 555 0171'])->assertRedirect();
    $lead = app(Leads::class)->create($owner, ['contact_id' => $contact->id, 'owner_id' => $owner->id, 'status_id' => 'new']);
    $hiddenLead = app(Leads::class)->create($other, ['contact_id' => Contact::latest('id')->firstOrFail()->id, 'owner_id' => $other->id, 'status_id' => 'new']);
    $this->actingAs($owner)->get('/people/contacts/'.$contact->id.'?from_lead='.$lead->id)->assertInertia(fn (Assert $page) => $page->where('returnTo', '/leads/'.$lead->id)->where('noteUrl', '/people/contacts/'.$contact->id.'/notes?from_lead='.$lead->id));
    $this->patch('/people/contacts/'.$contact->id.'?from_lead='.$lead->id, ['secondary_phone' => '+1 202-555-0172'])->assertRedirect('/leads/'.$lead->id);
    $this->post('/people/contacts/'.$contact->id.'/notes?from_lead='.$lead->id, ['body' => 'Added while reviewing the enquiry.'])->assertRedirect('/people/contacts/'.$contact->id.'?from_lead='.$lead->id);
    $this->post('/people/contacts/'.$contact->id.'/notes?from_lead='.$hiddenLead->id, ['body' => 'Must not be saved.'])->assertForbidden();
    expect(ContactNote::sole()->body)->toBe('Added while reviewing the enquiry.');
});
