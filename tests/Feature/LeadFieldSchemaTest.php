<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Quotation;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Awove\Core\Tables\Catalog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
    foreach (['imports.manage', 'exports.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});

function leadSchemaUser(string $name = 'Tamar Levi', bool $tools = false): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');
    if ($tools) {
        $user->givePermissionTo(['imports.manage', 'exports.view']);
    }

    return $user;
}

function leadSchemaLead(User $owner, string $name = 'Noa Cohen', array $fields = []): Lead
{
    $contact = Contact::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.test', 'created_by' => $owner->id, 'search_key' => strtolower($name)]);

    return app(Leads::class)->create($owner, [...['contact_id' => $contact->id, 'owner_id' => $owner->id, 'status_id' => 'new'], ...$fields]);
}

function leadSchemaUrl(array $query): string
{
    return '/leads?'.http_build_query($query);
}

function leadSchemaCsv(array $headers, array $rows): string
{
    $stream = fopen('php://temp', 'w+');
    foreach ([$headers, ...$rows] as $row) {
        fputcsv($stream, $row, ',', '"', '', "\r\n");
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);

    return $csv;
}

function leadSchemaCsvRows(string $csv): array
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream, null, ',', '"', '');
    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = array_combine($headers, $row);
    }
    fclose($stream);

    return $rows;
}

it('maps every source header once to canonical storage and makes all core projections selectable', function () {
    $schema = json_decode(file_get_contents(base_path('docs/lead-field-schema.json')), true, flags: JSON_THROW_ON_ERROR);
    $fields = $schema['fields'];
    $sourceColumns = array_merge(...array_column($fields, 'source_columns'));
    $expected = array_map(fn ($i) => $i < 26 ? chr(65 + $i) : 'A'.chr(65 + $i - 26), range(0, 41));
    sort($sourceColumns);
    sort($expected);
    expect($schema['source']['column_count'])->toBe(42)->and($schema['distinct_field_count'])->toBe(41)
        ->and($fields)->toHaveCount(41)->and(array_unique(array_column($fields, 'key')))->toHaveCount(41)
        ->and($sourceColumns)->toBe($expected)->and(array_unique($sourceColumns))->toHaveCount(42)
        ->and(array_merge(...array_column($fields, 'source_headers')))->toHaveCount(42)
        ->and($schema['policy']['customer_rows_imported'])->toBeFalse();
    $proposalDate = array_values(array_filter($fields, fn ($field) => $field['key'] === 'proposal_sent_date'))[0];
    expect($proposalDate['source_columns'])->toBe(['L', 'R'])->and($proposalDate['storage'])->toBe(['table' => 'sales_quotations', 'column' => 'sent_date']);
    $user = leadSchemaUser();
    $catalog = app(Catalog::class)->columns('leads', $user);
    $coreFields = array_filter($fields, fn ($field) => $field['module'] !== 'marketing');
    foreach ($coreFields as $field) {
        $storage = $field['storage'];
        expect(Schema::hasColumn($storage['table'], $storage['column']))->toBeTrue()
            ->and($catalog)->toHaveKey($field['table_column_id']);
        $type = Schema::getColumnType($storage['table'], $storage['column']);
        $allowed = match ($field['type']) {
            'date' => ['date'], 'datetime' => ['timestamptz'], 'boolean' => ['bool'],
            'reference' => ['int8', 'varchar'], default => ['varchar', 'text'],
        };
        expect($type)->toBeIn($allowed);
    }
    expect($catalog['privacy_policy_accepted']->type)->toBe('boolean')
        ->and($catalog['contract_sent_on']->type)->toBe('date')
        ->and($catalog['company_registration_number']->type)->toBe('text');
    $selected = array_values(array_unique(array_column($coreFields, 'table_column_id')));
    $this->actingAs($user)->postJson('/preferences', ['table' => 'leads', 'columns' => $selected])->assertOk();
    expect($user->fresh()->display_preferences['tables']['leads']['columns'])->toBe($selected);
});

it('sorts searches and filters new fields within lead scope without conflating unknown and declined consent', function () {
    $user = leadSchemaUser();
    $company = app(Identities::class)->create($user, 'companies', ['name' => 'Cohen Studio', 'registration_number' => '000123456']);
    $declined = leadSchemaLead($user, 'Noa Cohen', ['company_id' => $company->id, 'business_sector' => 'Consulting', 'product_sold' => 'Alpha review', 'contract_sent_on' => '2026-11-20', 'privacy_policy_accepted' => false]);
    $accepted = leadSchemaLead($user, 'Yael Shalev', ['business_sector' => 'Design', 'product_sold' => 'Zeta workshop', 'contract_sent_on' => '2026-11-27', 'privacy_policy_accepted' => true]);
    $unknown = leadSchemaLead($user, 'Uri Ben David', ['company_id' => $company->id, 'business_sector' => 'Consulting', 'product_sold' => 'Beta review']);
    $other = leadSchemaUser('Shira Erez');
    leadSchemaLead($other, 'Oren Levi', ['business_sector' => 'Consulting', 'product_sold' => 'Alpha private', 'contract_sent_on' => '2026-11-29', 'privacy_policy_accepted' => false]);
    $this->actingAs($user);
    $sorted = $this->get(leadSchemaUrl(['sorts' => [['id' => 'contract_sent_on', 'direction' => 'desc']]]))->assertOk();
    expect(array_column($sorted->inertiaProps('table.page.data'), 'id'))->toBe([$accepted->id, $declined->id, $unknown->id]);
    foreach ([['eq', 'false', $declined->id], ['eq', 'true', $accepted->id], ['empty', null, $unknown->id]] as [$op, $value, $id]) {
        $response = $this->get(leadSchemaUrl(['filters' => [['id' => 'privacy_policy_accepted', 'op' => $op, 'value' => $value]]]))->assertOk();
        expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toBe([$id]);
    }
    $response = $this->get(leadSchemaUrl(['filters' => [['id' => 'privacy_policy_accepted', 'op' => 'in', 'value' => ['true', 'false']]], 'sorts' => [['id' => 'product_sold', 'direction' => 'asc']]]))->assertOk();
    expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toBe([$declined->id, $accepted->id]);
    $response = $this->get(leadSchemaUrl(['q' => '000123456', 'sorts' => [['id' => 'product_sold', 'direction' => 'asc']]]))->assertOk();
    expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toBe([$declined->id, $unknown->id])
        ->and($response->inertiaProps('table.page.data.0.cells.company_registration_number'))->toBe('000123456');
    $response = $this->get(leadSchemaUrl(['filters' => [['id' => 'contract_sent_on', 'op' => 'gte', 'value' => '2026-11-25']]]))->assertOk();
    expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toBe([$accepted->id]);
    $this->get(leadSchemaUrl(['q' => 'Alpha private']))->assertOk()->assertInertia(fn ($page) => $page->where('table.page.total', 0));
});

it('projects proposal link and sent date from the same latest quotation without borrowing an older date', function () {
    $user = leadSchemaUser();
    $lead = leadSchemaLead($user);
    $old = Quotation::create(['lead_id' => $lead->id, 'created_by' => $user->id, 'number' => 'Q-OLD', 'amount' => '1000.00', 'status' => 'accepted', 'document_url' => 'https://example.test/proposals/old', 'sent_date' => '2026-11-20']);
    $draft = Quotation::create(['lead_id' => $lead->id, 'created_by' => $user->id, 'number' => 'Q-NEW', 'amount' => '1500.00', 'status' => 'draft', 'document_url' => 'https://example.test/proposals/new']);
    $response = $this->actingAs($user)->get('/leads')->assertOk();
    expect($response->inertiaProps('table.page.data.0.cells.proposal_link'))->toBe($draft->document_url)
        ->and($response->inertiaProps('table.page.data.0.cells.proposal_sent_date'))->toBeNull();
    $this->get(leadSchemaUrl(['filters' => [['id' => 'proposal_sent_date', 'op' => 'eq', 'value' => '2026-11-20']]]))->assertOk()->assertInertia(fn ($page) => $page->where('table.page.total', 0));
    $draft->update(['sent_date' => '2026-11-26']);
    $response = $this->get(leadSchemaUrl(['filters' => [['id' => 'proposal_sent_date', 'op' => 'eq', 'value' => '2026-11-26']]]))->assertOk();
    expect($response->inertiaProps('table.page.data.0.cells.proposal_link'))->toBe($draft->document_url)
        ->and($response->inertiaProps('table.page.data.0.cells.proposal_sent_date'))->toBe('2026-11-26')
        ->and($old->fresh()->sent_date->format('Y-m-d'))->toBe('2026-11-20');
});

it('preserves baseline default columns and saved layouts when additional fields become available', function () {
    $user = leadSchemaUser();
    leadSchemaLead($user);
    $defaults = ['contact', 'company', 'status', 'owner', 'contact_phone', 'contact_email', 'priority', 'next_action_at', 'created_at', 'proposal'];
    $response = $this->actingAs($user)->get('/leads')->assertOk();
    expect($response->inertiaProps('table.defaultColumns'))->toBe($defaults)
        ->and($response->inertiaProps('table.preferences.columns'))->toBe($defaults);
    $layout = ['table' => 'leads', 'columns' => ['title', 'company', 'status'], 'widths' => ['title' => 310], 'pinned' => ['company'], 'density' => 'compact', 'wrap' => true, 'views' => [['id' => 'existing-view', 'name' => 'Existing view', 'columns' => ['status', 'title'], 'state' => ['sorts' => [['id' => 'title', 'direction' => 'asc']], 'filters' => [['id' => 'status', 'op' => 'eq', 'value' => 'New']]]]]];
    $this->postJson('/preferences', $layout)->assertOk();
    $stored = $user->fresh()->display_preferences;
    $response = $this->get('/leads')->assertOk();
    expect($response->inertiaProps('table.preferences'))->toEqual($stored['tables']['leads'])
        ->and($user->fresh()->display_preferences)->toBe($stored)
        ->and(array_column($response->inertiaProps('table.columns'), 'id'))->toContain('business_sector', 'privacy_policy_accepted', 'signed_contract', 'proposal_link');
    $this->postJson('/preferences', ['table' => 'leads', 'columns' => ['title', 'company', 'status', 'product_sold']])->assertOk();
    expect($user->fresh()->display_preferences['tables']['leads']['views'])->toBe($stored['tables']['leads']['views']);
});

it('round trips baseline CSV fields and preserves nullable dates and explicit false consent', function () {
    $user = leadSchemaUser(tools: true);
    $contact = Contact::create(['name' => 'Noa Cohen', 'created_by' => $user->id, 'search_key' => 'noacohen']);
    $headers = ['contact_id', 'status_id', 'business_sector', 'product_sold', 'contract_sent_on', 'invoice_sent_on', 'payment_received_on', 'process_started_on', 'contract_url', 'privacy_policy_accepted'];
    $rows = [[$contact->id, 'new', 'Consulting', "Review, with a meeting\nAnd a report", '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23', 'https://example.test/contracts/noa', '0'], [$contact->id, 'new', '', '', '', '', '', '', '', '']];
    $this->actingAs($user);
    $template = $this->get('/spreadsheets/template/leads')->assertOk()->streamedContent();
    foreach (array_slice($headers, 2) as $field) {
        expect(str_getcsv(trim($template), ',', '"', ''))->toContain($field);
    }
    $preview = $this->post('/spreadsheets/preview', ['type' => 'leads', 'file' => UploadedFile::fake()->createWithContent('leads.csv', leadSchemaCsv($headers, $rows))], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('errors', [])->assertJsonPath('count', 2);
    expect(Lead::count())->toBe(0);
    $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token')])->assertOk()->assertJsonPath('count', 2);
    $stored = Lead::orderBy('id')->get();
    expect($stored[0]->privacy_policy_accepted)->toBeFalse()->and($stored[1]->privacy_policy_accepted)->toBeNull();
    $other = leadSchemaUser('Shira Erez');
    leadSchemaLead($other, 'Oren Levi', ['product_sold' => 'Restricted sale']);
    $exported = leadSchemaCsvRows($this->get('/spreadsheets/export/leads')->assertOk()->streamedContent());
    expect($exported)->toHaveCount(2);
    foreach ($rows as $index => $expected) {
        foreach (array_combine($headers, $expected) as $key => $value) {
            expect($exported[$index][$key])->toBe((string) $value);
        }
    }
    expect($stored[0]->fresh()->getRawOriginal('contract_sent_on'))->toBe('2026-11-20')
        ->and($stored[1]->fresh()->process_started_on)->toBeNull();
});

it('round trips a leading-zero company registration through the CSV template import and export', function () {
    $user = leadSchemaUser(tools: true);
    $this->actingAs($user);
    $template = $this->get('/spreadsheets/template/companies')->assertOk()->streamedContent();
    expect(str_getcsv(trim($template), ',', '"', ''))->toContain('registration_number');
    $preview = $this->post('/spreadsheets/preview', ['type' => 'companies', 'file' => UploadedFile::fake()->createWithContent('companies.csv', "name,registration_number\nCohen Studio,000123456\n")], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('errors', []);
    expect(Company::count())->toBe(0);
    $this->postJson('/spreadsheets/confirm', ['token' => $preview->json('token')])->assertOk();
    expect(Company::sole()->registration_number)->toBe('000123456');
    $exported = leadSchemaCsvRows($this->get('/spreadsheets/export/companies')->assertOk()->streamedContent());
    expect($exported)->toHaveCount(1)->and($exported[0]['registration_number'])->toBe('000123456');
});
