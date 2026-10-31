<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Leads;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function optionalTitleEmployee(): User
{
    $user = User::factory()->create(['active' => true]);
    $user->assignRole('Employee');

    return $user;
}

function optionalTitleCustomer(User $user, string $model, string $name): Contact|Company
{
    return $model::create(['name' => $name, 'created_by' => $user->id, 'search_key' => $name]);
}

it('creates contact or company leads with customer names when title is omitted or blank', function () {
    $user = optionalTitleEmployee();
    $contact = optionalTitleCustomer($user, Contact::class, 'איש קשר לדוגמה');
    $company = optionalTitleCustomer($user, Company::class, 'חברה לדוגמה');
    $this->actingAs($user);
    foreach (['contact_id' => $contact, 'company_id' => $company] as $key => $customer) {
        foreach ([[], ['title' => null], ['title' => ''], ['title' => '   ']] as $title) {
            $this->post('/leads', [...$title, $key => $customer->id, 'status_id' => 'new'])->assertRedirect();
            $lead = Lead::latest('id')->firstOrFail();
            expect($lead->title)->toBe($customer->name)->and($lead->getAttribute($key))->toBe($customer->id)->and($lead->owner_id)->toBe($user->id);
        }
    }
    expect(Lead::count())->toBe(8);
});

it('preserves explicit titles and prefers the contact name when both customer types are selected', function () {
    $user = optionalTitleEmployee();
    $contact = optionalTitleCustomer($user, Contact::class, 'Contact name');
    $company = optionalTitleCustomer($user, Company::class, 'Company name');
    $this->actingAs($user)->post('/leads', ['title' => 'A specific opportunity', 'contact_id' => $contact->id, 'company_id' => $company->id, 'status_id' => 'new'])->assertRedirect();
    expect(Lead::latest('id')->firstOrFail()->title)->toBe('A specific opportunity');
    $this->post('/leads', ['contact_id' => $contact->id, 'company_id' => $company->id, 'status_id' => 'new'])->assertRedirect();
    expect(Lead::latest('id')->firstOrFail()->title)->toBe('Contact name');
    $this->post('/leads', ['title' => str_repeat('a', 161), 'contact_id' => $contact->id, 'status_id' => 'new'])->assertSessionHasErrors('title');
    expect(Lead::count())->toBe(2);
});

it('denies inaccessible customer selections before deriving a fallback and still requires a customer', function () {
    $user = optionalTitleEmployee();
    $foreign = optionalTitleEmployee();
    $contact = optionalTitleCustomer($foreign, Contact::class, 'Private contact name');
    $company = optionalTitleCustomer($foreign, Company::class, 'Private company name');
    $own = optionalTitleCustomer($user, Contact::class, 'Visible contact');
    $this->actingAs($user)->post('/leads', ['contact_id' => $contact->id, 'status_id' => 'new'])->assertForbidden();
    $this->post('/leads', ['title' => '', 'company_id' => $company->id, 'status_id' => 'new'])->assertForbidden();
    $this->post('/leads', ['contact_id' => $own->id, 'company_id' => $company->id, 'status_id' => 'new'])->assertForbidden();
    $this->post('/leads', ['status_id' => 'new'])->assertUnprocessable();
    try {
        app(Leads::class)->create($user, ['contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new']);
        $this->fail('The service must reject hidden customer selections.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    expect(Lead::count())->toBe(0);
});

it('preserves omitted titles on update and restores the current customer name when cleared', function () {
    $user = optionalTitleEmployee();
    $contact = optionalTitleCustomer($user, Contact::class, 'Original customer');
    $company = optionalTitleCustomer($user, Company::class, 'Company customer');
    $lead = app(Leads::class)->create($user, ['title' => 'Existing opportunity', 'contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new']);
    $this->actingAs($user)->patch('/leads/'.$lead->id, ['version' => 1, 'source' => 'Referral'])->assertRedirect();
    expect($lead->fresh()->title)->toBe('Existing opportunity');
    $this->patch('/leads/'.$lead->id, ['version' => 2, 'title' => null])->assertRedirect();
    expect($lead->fresh()->title)->toBe('Original customer');
    $this->patch('/leads/'.$lead->id, ['version' => 3, 'title' => '   ', 'contact_id' => null, 'company_id' => $company->id])->assertRedirect();
    expect($lead->fresh()->title)->toBe('Company customer');
    $this->patch('/leads/'.$lead->id, ['version' => 4, 'title' => '', 'company_id' => null])->assertUnprocessable();
    expect($lead->fresh()->title)->toBe('Company customer')->and($lead->fresh()->company_id)->toBe($company->id)->and($lead->fresh()->version)->toBe(4);
    $this->patch('/leads/'.$lead->id, ['version' => 3, 'title' => ''])->assertConflict();
});
