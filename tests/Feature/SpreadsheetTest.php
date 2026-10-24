<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Spreadsheet;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
    foreach (['imports.manage', 'exports.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

function spreadsheetUser(bool $tools = true): User
{
    $user = User::factory()->create(['active' => true]);
    $user->assignRole('Employee');
    if ($tools) {
        $user->givePermissionTo(['imports.manage', 'exports.view']);
    }

    return $user;
}

function spreadsheetContact(User $user, string $name = 'Shira Levi', array $extra = []): Contact
{
    return Contact::create([...['name' => $name, 'created_by' => $user->id, 'search_key' => 'shiralevi'], ...$extra]);
}

function spreadsheetCompany(User $user, string $name = 'Erez Studio'): Company
{
    return Company::create(['name' => $name, 'created_by' => $user->id, 'search_key' => 'erezstudio']);
}

it('requires explicit import and export permissions in addition to entity creation rights', function () {
    $user = spreadsheetUser(false);
    $this->actingAs($user)->get('/spreadsheets')->assertForbidden();
    $this->get('/spreadsheets/export/contacts')->assertForbidden();
    $this->get('/spreadsheets/template/contacts')->assertForbidden();
    $this->postJson('/spreadsheets/preview', ['type' => 'contacts'])->assertForbidden();
    $this->postJson('/spreadsheets/confirm', ['token' => str_repeat('a', 64)])->assertForbidden();
    $user->givePermissionTo('imports.manage');
    $user->syncRoles([]);
    $this->get('/spreadsheets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Core/Import')->where('canImport', false)->where('canExport', false));
    $this->get('/spreadsheets/template/contacts')->assertForbidden();
    expect(Contact::count())->toBe(0);
});

it('provides supported templates and exports without granting import access', function () {
    $user = spreadsheetUser(false);
    $user->givePermissionTo('exports.view');
    $this->actingAs($user)->get('/spreadsheets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Core/Import')->where('canImport', false)->where('canExport', true));
    $this->get('/spreadsheets/export/contacts')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $this->get('/spreadsheets/template/contacts')->assertForbidden();
    $user->givePermissionTo('imports.manage');
    $response = $this->get('/spreadsheets/template/contacts')->assertOk();
    expect($response->streamedContent())->toStartWith('name,email,phone,company_id,');
    $this->get('/spreadsheets/export/unknown')->assertNotFound();
    $this->get('/spreadsheets/template/unknown')->assertNotFound();
});

it('previews without writes then imports contacts once with their details and initial notes', function () {
    $user = spreadsheetUser();
    $company = spreadsheetCompany($user);
    $csv = "\xEF\xBB\xBFname,email,phone,company_id,preferred_contact_times,preferred_contact_days,notes\r\nShira Levi,Shira@Example.Test,+357 99000111,{$company->id},mornings|afternoons,sunday|monday,\"A fictional note, with a comma\nAnd a second line\"\r\n";
    $response = $this->actingAs($user)->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('count', 1)->assertJsonPath('errors', [])->assertJsonPath('warnings', []);
    expect(Contact::count())->toBe(0);
    $token = $response->json('token');
    $this->postJson('/spreadsheets/confirm', ['token' => $token])->assertOk()->assertJsonPath('count', 1)->assertJsonPath('type', 'contacts');
    $contact = Contact::sole();
    expect($contact->name)->toBe('Shira Levi')->and($contact->email)->toBe('shira@example.test')->and($contact->phone)->toBe('+357 99000111')->and($contact->company_id)->toBe($company->id)->and($contact->preferred_contact_times)->toBe(['mornings', 'afternoons'])->and($contact->preferred_contact_days)->toBe(['sunday', 'monday'])->and($contact->notes()->sole()->body)->toBe("A fictional note, with a comma\nAnd a second line");
    $this->postJson('/spreadsheets/confirm', ['token' => $token])->assertConflict();
    expect(Contact::count())->toBe(1);
});

it('creates companies and multiple independent opportunities for an accessible customer', function () {
    $user = spreadsheetUser();
    $this->actingAs($user);
    $preview = $this->post('/spreadsheets/preview', ['type' => 'companies', 'file' => UploadedFile::fake()->createWithContent('companies.csv', "name,email,phone,notes\nErez Studio,erez@example.test,+357 25000111,Requested a fictional business consultation.\n")], ['Accept' => 'application/json'])->assertOk();
    $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token')])->assertOk();
    $company = Company::sole();
    expect($company->notes()->sole()->body)->toBe('Requested a fictional business consultation.')->and($company->notes()->sole()->author_id)->toBe($user->id);
    expect($this->get('/spreadsheets/export/companies')->assertOk()->streamedContent())->not->toContain('Requested a fictional business consultation.');
    $preview = $this->post('/spreadsheets/preview', ['type' => 'leads', 'file' => UploadedFile::fake()->createWithContent('leads.csv', "company_id,status_id,title\n{$company->id},new,Design consultation\n{$company->id},new,Training workshop\n")], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('errors', []);
    expect(Lead::count())->toBe(0);
    $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token')])->assertOk()->assertJsonPath('count', 2);
    expect(Lead::where('company_id', $company->id)->count())->toBe(2)->and(Lead::first()->owner_id)->toBe($user->id)->and(Lead::first()->activity()->sole()->event)->toBe('lead.created');
});

it('reports all invalid records without partially persisting a valid prefix', function () {
    $user = spreadsheetUser();
    $preview = $this->actingAs($user)->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nShira Levi,shira@example.test\nNoam Erez,invalid-email\n\" \" ,another@example.test\n")], ['Accept' => 'application/json'])->assertUnprocessable();
    expect(Contact::count())->toBe(0);
    $preview = $this->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nShira Levi,shira@example.test\nNoam Erez,invalid-email\n,another@example.test\n")], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('token', null)->assertJsonCount(2, 'errors')->assertJsonCount(1, 'rows');
    expect(Contact::count())->toBe(0);
});

it('warns about scoped and in-file duplicates and requires explicit separate-record confirmation', function () {
    $user = spreadsheetUser();
    $original = spreadsheetContact($user, 'Shira Levi', ['email' => 'shira@example.test', 'phone' => '+357 99 000 111']);
    $csv = "name,email,phone\nYael Shalev,yael@example.test,+357-99-000-111\nNoam Erez,yael@example.test,+357 99000222\n";
    $preview = $this->actingAs($user)->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(2, 'warnings');
    $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token')])->assertUnprocessable()->assertJsonValidationErrors('accept_duplicates');
    expect(Contact::count())->toBe(1);
    $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token'), 'accept_duplicates' => true])->assertOk()->assertJsonPath('count', 2);
    expect(Contact::count())->toBe(3)->and($original->fresh()->email)->toBe('shira@example.test')->and($original->fresh()->phone)->toBe('+357 99 000 111');
});

it('does not reveal hidden duplicates or permit inaccessible relations and owner overrides', function () {
    $user = spreadsheetUser();
    $other = spreadsheetUser();
    $hidden = spreadsheetContact($other, 'Shira Levi', ['email' => 'shira@example.test']);
    $company = spreadsheetCompany($other);
    $preview = $this->actingAs($user)->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nShira Levi,shira@example.test\n")], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('warnings', []);
    expect(json_encode($preview->json()))->not->toContain((string) $hidden->id.'"');
    foreach ([['contacts', "name,company_id\nYael Shalev,{$company->id}\n"], ['leads', "title,contact_id,status_id\nA fictional request,{$hidden->id},new\n"], ['leads', "company_id,status_id,owner_id\n{$company->id},new,{$other->id}\n"]] as [$type, $csv]) {
        $this->post('/spreadsheets/preview', ['type' => $type, 'file' => UploadedFile::fake()->createWithContent('records.csv', $csv)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('token', null)->assertJsonCount(1, 'errors');
    }
    expect(Lead::count())->toBe(0)->and(Contact::count())->toBe(1);
});

it('binds confirmation tokens to their user and rechecks access and newly appearing duplicates', function () {
    $user = spreadsheetUser();
    $other = spreadsheetUser();
    $preview = $this->actingAs($user)->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nYael Shalev,yael@example.test\n")], ['Accept' => 'application/json'])->assertOk();
    $token = $preview->json('token');
    $this->actingAs($other)->postJson('/spreadsheets/confirm', ['token' => $token])->assertConflict();
    spreadsheetContact($user, 'Yael Shalev');
    $this->actingAs($user)->postJson('/spreadsheets/confirm', ['token' => $token, 'accept_duplicates' => true])->assertConflict();
    expect(Contact::count())->toBe(1);
    $user->revokePermissionTo('imports.manage');
    $this->postJson('/spreadsheets/confirm', ['token' => $token])->assertForbidden();
});

it('expires previews and rejects fabricated tokens without importing records', function () {
    $user = spreadsheetUser();
    $preview = $this->actingAs($user)->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('contacts.csv', "name\nYael Shalev\n")], ['Accept' => 'application/json'])->assertOk();
    $this->travel(16)->minutes();
    $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token')])->assertConflict();
    $this->postJson('/spreadsheets/confirm', ['token' => str_repeat('f', 64)])->assertConflict();
    $this->postJson('/spreadsheets/confirm', ['token' => 'invalid'])->assertConflict();
    expect(Contact::count())->toBe(0);
});

it('rejects unsupported formats malformed headers quotes encoding and column counts', function () {
    $user = spreadsheetUser();
    $this->actingAs($user);
    foreach ([['records.xlsx', "name\nYael Shalev\n"], ['records.csv', "name,name\nYael,Shalev\n"], ['records.csv', "name,created_by\nYael Shalev,1\n"], ['records.csv', "email\nyael@example.test\n"], ['records.csv', "name\nYael,Shalev\n"], ['records.csv', "name\n\"Yael Shalev\n"], ['records.csv', "name\nYael \"Shalev\"\n"], ['records.csv', "name\nYael\xFF\n"], ['records.csv', "name\nYael\0Shalev\n"], ['records.csv', "name\n\n"]] as [$filename, $csv]) {
        $this->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent($filename, $csv)], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    }
    expect(Contact::count())->toBe(0);
});

it('bounds uploaded files and record counts', function () {
    $user = spreadsheetUser();
    $this->actingAs($user)->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->create('records.csv', 1025, 'text/csv')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->post('/spreadsheets/preview', ['type' => 'contacts', 'file' => UploadedFile::fake()->createWithContent('records.csv', "name\n".str_repeat("Yael Shalev\n", 501))], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(Contact::count())->toBe(0);
});

it('exports only accessible records and neutralizes spreadsheet formula cells', function () {
    $user = spreadsheetUser();
    $other = spreadsheetUser();
    $hiddenCompany = spreadsheetCompany($other, 'Restricted studio');
    $contact = spreadsheetContact($user, '=HYPERLINK("https://example.test")', ['email' => 'yael@example.test', 'phone' => '+357 99000111', 'company_id' => $hiddenCompany->id]);
    spreadsheetContact($other, 'Hidden contact');
    $lead = app(Leads::class)->create($user, ['contact_id' => $contact->id, 'title' => '@SUM(1,2)', 'owner_id' => $user->id, 'status_id' => 'new']);
    app(Leads::class)->create($other, ['title' => 'Hidden opportunity', 'company_id' => $hiddenCompany->id, 'owner_id' => $other->id, 'status_id' => 'new']);
    $archived = app(Leads::class)->create($user, ['title' => 'Archived opportunity', 'contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new']);
    $archived->update(['archived_at' => now()]);
    $response = $this->actingAs($user)->get('/spreadsheets/export/contacts')->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain("'=HYPERLINK", "'+357 99000111")->not->toContain('Hidden contact', 'Restricted studio');
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, null, ',', '"', '');
    $row = array_combine($header, fgetcsv($stream, null, ',', '"', ''));
    fclose($stream);
    expect($row['company_id'])->toBe('');
    $csv = $this->get('/spreadsheets/export/leads')->assertOk()->streamedContent();
    expect($csv)->toContain("'@SUM(1,2)")->not->toContain('Hidden opportunity', 'Archived opportunity', 'Restricted studio');
    expect($lead->fresh()->title)->toBe('@SUM(1,2)');
    $user->forceFill(['active' => false])->save();
    $this->get('/spreadsheets/export/contacts')->assertForbidden();
});

it('escapes formula prefixes even after leading whitespace and preserves ordinary CSV text', function () {
    foreach (['=1+1', '+SUM(1,2)', '-1+2', '@SUM(A1)', '  =1+1', "\ttext", "\n=1+1", "\r=1+1", "\u{FEFF}=1+1"] as $cell) {
        expect(Spreadsheet::safeCell($cell))->toBe("'".$cell);
    }
    foreach (['Shira Levi', '123', 'hello@example.test', 'A note, with comma', ''] as $cell) {
        expect(Spreadsheet::safeCell($cell))->toBe($cell);
    }
});

it('denies lead CSV access and stale confirmation when Sales is disabled while identity tools still work', function () {
    $user = spreadsheetUser();
    $contact = spreadsheetContact($user);
    $this->actingAs($user);
    $preview = $this->post('/spreadsheets/preview', ['type' => 'leads', 'file' => UploadedFile::fake()->createWithContent('leads.csv', "contact_id,status_id\n{$contact->id},new\n")], ['Accept' => 'application/json'])->assertOk();
    $manifest = Installation::manifest();
    $manifest['capabilities']['sales'] = false;
    $path = tempnam(sys_get_temp_dir(), 'base-crm-csv-disabled-');
    file_put_contents($path, json_encode($manifest));
    config(['core.manifest' => $path]);
    try {
        $this->get('/spreadsheets')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('definitions.leads')->has('definitions.contacts'));
        $this->get('/spreadsheets/export/leads')->assertNotFound();
        $this->get('/spreadsheets/template/leads')->assertNotFound();
        $this->post('/spreadsheets/preview', ['type' => 'leads', 'file' => UploadedFile::fake()->createWithContent('leads.csv', "contact_id,status_id\n{$contact->id},new\n")], ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token')])->assertNotFound();
        $this->get('/spreadsheets/export/contacts')->assertOk();
        $this->get('/spreadsheets/template/companies')->assertOk();
        expect(Lead::count())->toBe(0)->and(Contact::count())->toBe(1);
    } finally {
        unlink($path);
    }
});

it('imports and exports Sales detail fields without floating point conversion', function () {
    $user = spreadsheetUser();
    $contact = spreadsheetContact($user);
    $preview = $this->actingAs($user)->post('/spreadsheets/preview', ['type' => 'leads', 'file' => UploadedFile::fake()->createWithContent('leads.csv', "contact_id,status_id,needs,estimated_value,closing_date,final_value,loss_reason\n{$contact->id},new,Consultation,1234.50,2026-11-12,1200.10,\n")], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('errors', []);
    $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token')])->assertOk();
    $lead = Lead::sole();
    expect($lead->estimated_value)->toBe('1234.50')->and($lead->final_value)->toBe('1200.10')->and($lead->closing_date->format('Y-m-d'))->toBe('2026-11-12')->and($lead->needs)->toBe('Consultation');
    $csv = $this->get('/spreadsheets/export/leads')->assertOk()->streamedContent();
    expect($csv)->toContain('1234.50', '1200.10', '2026-11-12')->not->toContain('2026-11-12T');
});
