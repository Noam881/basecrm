<?php

use App\Models\User;
use Awove\Core\Access;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Task;
use Awove\Core\Services\Leads;
use Awove\Core\Tables\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
    $this->artisan('crm:install');
});
function tableEmployee(string $name = 'Table user'): User
{
    $u = User::factory()->create(['name' => $name]);
    $u->assignRole('Employee');

    return $u;
}
function tableLead(User $u, string $title, string $priority = 'normal', array $extra = []): Lead
{
    $contact = Contact::create(['name' => $title.' person', 'email' => strtolower(str_replace(' ', '', $title)).'@example.test', 'phone' => '+357 99123456', 'created_by' => $u->id, 'search_key' => strtolower($title)]);

    return app(Leads::class)->create($u, [...['title' => $title, 'owner_id' => $u->id, 'contact_id' => $contact->id, 'status_id' => 'new', 'priority' => $priority, 'source' => 'Referral'], ...$extra]);
}
function tableUrl(array $query): string
{
    return '/leads?'.http_build_query($query);
}
function tableIdentityQuery(User $user, array $input = [], string $type = 'contacts'): array
{
    $user = $user->fresh();
    $request = Request::create('/', 'GET', $input);
    $request->setUserResolver(fn () => $user);

    return app(Query::class)->run(Access::identities($user, $type === 'contacts' ? Contact::class : Company::class), $type, $request);
}
it('sorts priorities semantically and supports deterministic multi-column sorting across pages', function () {
    $u = tableEmployee();
    foreach ([['zebra', 'low'], ['Beta', 'high'], ['alpha', 'high'], ['Middle', 'normal']] as [$title,$priority]) {
        tableLead($u, $title, $priority);
    }
    $r = $this->actingAs($u)->get(tableUrl(['sorts' => [['id' => 'priority', 'direction' => 'desc'], ['id' => 'title', 'direction' => 'asc']], 'per_page' => 10]))->assertOk();
    expect(array_column($r->inertiaProps('table.page.data'), 'title'))->toBe(['alpha', 'Beta', 'Middle', 'zebra']);
    $r = $this->get(tableUrl(['sorts' => [['id' => 'title', 'direction' => 'desc']]]))->assertOk();
    expect(array_column($r->inertiaProps('table.page.data'), 'title'))->toBe(['zebra', 'Middle', 'Beta', 'alpha']);
});
it('filters and searches the entire permission-scoped dataset rather than the visible page', function () {
    $u = tableEmployee();
    for ($i = 0; $i < 26; $i++) {
        tableLead($u, 'Prospect '.$i);
    }$foreign = tableEmployee('Other user');
    tableLead($foreign, 'Secret target');
    $r = $this->actingAs($u)->get(tableUrl(['q' => 'prospect25@example.test', 'per_page' => 10]))->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(1)->and($r->inertiaProps('table.page.data.0.title'))->toBe('Prospect 25');
    $r = $this->get(tableUrl(['q' => 'Secret target']))->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(0);
    $r = $this->get(tableUrl(['match' => 'any', 'filters' => [['id' => 'title', 'op' => 'contains', 'value' => 'Secret'], ['id' => 'owner', 'op' => 'eq', 'value' => 'Other user']]]))->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(0);
    $r = $this->get(tableUrl(['per_page' => 10, 'page' => 3, 'sorts' => [['id' => 'id', 'direction' => 'asc']]]))->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(26)->and($r->inertiaProps('table.page.data'))->toHaveCount(6);
});
it('supports numeric ranges, empty relations, multiple values, and AND or OR conditions', function () {
    $u = tableEmployee();
    $a = tableLead($u, 'Alpha', 'high');
    $b = tableLead($u, 'Beta', 'low');
    $c = tableLead($u, 'Gamma', 'normal');
    $r = $this->actingAs($u)->get(tableUrl(['filters' => [['id' => 'id', 'op' => 'between', 'value' => $a->id, 'value2' => $b->id], ['id' => 'priority', 'op' => 'in', 'value' => ['high', 'low']]]]))->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(2);
    $r = $this->get(tableUrl(['filters' => [['id' => 'contact', 'op' => 'not_empty'], ['id' => 'company', 'op' => 'empty']]]))->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(3);
    $r = $this->get(tableUrl(['match' => 'any', 'filters' => [['id' => 'title', 'op' => 'starts', 'value' => 'Al'], ['id' => 'title', 'op' => 'ends', 'value' => 'mma']]]))->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(2);
});
it('uses timezone-local calendar days for timestamp filters and keeps empty values last', function () {
    $u = tableEmployee();
    $a = tableLead($u, 'With action');
    $b = tableLead($u, 'No action');
    Task::create(['lead_id' => $a->id, 'assignee_id' => $u->id, 'title' => 'Local-day task', 'due_at' => '2026-10-21T22:30:00+00:00', 'remind_at' => '2026-10-21T22:00:00+00:00', 'priority' => 'normal']);
    $r = $this->actingAs($u)->get(tableUrl(['filters' => [['id' => 'next_action_at', 'op' => 'eq', 'value' => '2026-10-22']]]))->assertOk();
    expect($r->inertiaProps('table.page.data.0.id'))->toBe($a->id)->and($r->inertiaProps('table.page.total'))->toBe(1);
    foreach (['asc', 'desc'] as $direction) {
        $r = $this->get(tableUrl(['sorts' => [['id' => 'next_action_at', 'direction' => $direction]]]))->assertOk();
        expect(array_column($r->inertiaProps('table.page.data'), 'id'))->toBe([$a->id, $b->id]);
    }
});
it('exposes configured and enabled-module fields only when permitted and retains preferences safely on disable', function () {
    config(['core.installation' => 'beta']);
    $u = tableEmployee();
    $u->givePermissionTo(['service-details.view', 'service-details.edit']);
    $lead = tableLead($u, 'Service');
    DB::table('service_details_leads')->updateOrInsert(['lead_id' => $lead->id], ['service_reference' => 'PRIVATE-REF', 'requested_service' => 'Maintenance']);
    $field = 'module:service-details:service_reference';
    $r = $this->actingAs($u)->get(tableUrl(['filters' => [['id' => $field, 'op' => 'eq', 'value' => 'PRIVATE-REF']]]))->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(1)->and(array_column($r->inertiaProps('table.columns'), 'id'))->toContain('custom:service_type', $field);
    $this->postJson('/preferences', ['table' => 'leads', 'columns' => ['title', $field], 'views' => [['id' => 'v1', 'name' => 'Module view', 'columns' => [$field], 'state' => ['filters' => [['id' => $field, 'op' => 'contains', 'value' => 'REF']]]]]])->assertOk();
    config(['core.installation' => 'alpha']);
    $r = $this->get('/leads')->assertOk();
    expect(array_column($r->inertiaProps('table.columns'), 'id'))->not->toContain($field)->and($r->inertiaProps('table.preferences.columns'))->toBe(['title'])->and($r->inertiaProps('table.preferences.views.0.state.filters'))->toBe([]);
    $this->getJson(tableUrl(['sorts' => [['id' => $field, 'direction' => 'asc']]]))->assertUnprocessable();
    config(['core.installation' => 'beta']);
    $u->revokePermissionTo('service-details.view');
    $r = $this->get('/leads')->assertOk();
    expect(array_column($r->inertiaProps('table.columns'), 'id'))->not->toContain($field);
});
it('validates all query inputs and rejects secret fields and SQL-shaped identifiers', function () {
    $u = tableEmployee();
    $this->actingAs($u);
    foreach ([['sorts' => [['id' => 'password', 'direction' => 'asc']]], ['sorts' => [['id' => 'title', 'direction' => 'desc NULLS LAST; SELECT 1']]], ['filters' => [['id' => 'title', 'op' => 'raw', 'value' => 'x']]], ['filters' => [['id' => 'id', 'op' => 'gt', 'value' => 'not-number']]], ['filters' => [['id' => 'created_at', 'op' => 'eq', 'value' => 'tomorrow']]], ['per_page' => 100000]] as $query) {
        $this->getJson(tableUrl($query))->assertUnprocessable();
    }
    $this->postJson('/preferences', ['table' => 'leads', 'columns' => ['title', 'password']])->assertUnprocessable();
});
it('persists independent per-table and per-user layouts, widths, pins and named query views', function () {
    $u = tableEmployee();
    $other = tableEmployee();
    $this->actingAs($u)->postJson('/preferences', ['table' => 'leads', 'columns' => ['priority', 'title', 'contact_email'], 'widths' => ['title' => 360], 'pinned' => ['title'], 'density' => 'compact', 'wrap' => true, 'views' => [['id' => 'priority-view', 'name' => 'High first', 'columns' => ['priority', 'title'], 'state' => ['sorts' => [['id' => 'priority', 'direction' => 'desc']], 'filters' => [['id' => 'priority', 'op' => 'eq', 'value' => 'high']]]]]])->assertOk();
    $this->postJson('/preferences', ['table' => 'contacts', 'columns' => ['email', 'name']])->assertOk();
    $r = $this->get('/leads')->assertOk();
    expect($r->inertiaProps('table.preferences.columns'))->toBe(['priority', 'title', 'contact_email'])->and($r->inertiaProps('table.preferences.widths.title'))->toBe(360)->and($r->inertiaProps('table.preferences.views.0.name'))->toBe('High first');
    $contactTable = tableIdentityQuery($u);
    expect($contactTable['preferences']['columns'])->toBe(['email', 'name']);
    $r = $this->actingAs($other)->get('/leads')->assertOk();
    expect($r->inertiaProps('table.preferences.columns'))->not->toBe(['priority', 'title', 'contact_email']);
});
it('uses the same field catalog for restricted contacts and assigned follow-ups', function () {
    $u = tableEmployee();
    $other = tableEmployee();
    $own = tableLead($u, 'Own');
    $foreign = tableLead($other, 'Foreign');
    foreach ([[$own, $u], [$foreign, $other]] as [$lead,$assignee]) {
        Task::create(['title' => $lead->title.' follow-up', 'lead_id' => $lead->id, 'assignee_id' => $assignee->id, 'due_at' => now()->addDay(), 'remind_at' => now(), 'priority' => 'normal']);
    }
    $contactTable = tableIdentityQuery($u);
    expect($contactTable['page']->total())->toBe(1)->and(array_column($contactTable['columns'], 'id'))->toContain('email', 'phone', 'company');
    $r = $this->actingAs($u)->get('/tasks')->assertOk();
    expect($r->inertiaProps('table.page.total'))->toBe(1)->and($r->inertiaProps('table.page.data.0.cells.title'))->toBe('Own follow-up');
});

it('sorts configured number fields numerically rather than as text', function () {
    $manifest = Installation::manifest();
    $manifest['custom_fields'] = ['score' => ['type' => 'number', 'label' => 'Score']];
    $file = tempnam(sys_get_temp_dir(), 'base-table-manifest-');
    file_put_contents($file, json_encode($manifest));
    config(['core.manifest' => $file]);
    try {
        $u = tableEmployee();
        tableLead($u, 'Score ten', 'normal', ['custom_fields' => ['score' => 10]]);
        tableLead($u, 'Score two', 'normal', ['custom_fields' => ['score' => 2]]);
        $r = $this->actingAs($u)->get(tableUrl(['sorts' => [['id' => 'custom:score', 'direction' => 'desc']], 'filters' => [['id' => 'custom:score', 'op' => 'gte', 'value' => 2]]]))->assertOk();
        expect(array_column($r->inertiaProps('table.page.data'), 'title'))->toBe(['Score ten', 'Score two']);
    } finally {
        unlink($file);
    }
});

it('starts leads with customer identities while keeping an optional enquiry title available', function () {
    $user = tableEmployee();
    tableLead($user, 'Website refresh');
    $response = $this->actingAs($user)->get('/leads')->assertOk();
    $columns = $response->inertiaProps('table.preferences.columns');
    expect(array_slice($columns, 0, 2))->toBe(['contact', 'company'])
        ->and($columns)->not->toContain('title')
        ->and(array_column($response->inertiaProps('table.columns'), 'id'))->toContain('title');
});

it('exposes contact availability and secondary details as scoped searchable and filterable columns', function () {
    $user = tableEmployee('Noa Cohen');
    $contact = Contact::create(['name' => 'Shira Levi', 'created_by' => $user->id, 'search_key' => 'shiralevi', 'secondary_phone' => '+1 202-555-0171', 'secondary_phone_label' => 'office', 'preferred_contact_method' => 'whatsapp', 'preferred_contact_times' => ['mornings', 'evenings'], 'preferred_contact_days' => ['monday', 'saturday', 'sunday'], 'address' => '12 Olive Street', 'city' => 'Tel Aviv', 'country' => 'Israel', 'source' => 'referral']);
    $contact->notes()->create(['body' => 'Call after the office opens.', 'author_id' => $user->id]);
    $foreign = tableEmployee('Uri Ben David');
    Contact::create(['name' => 'Private contact', 'created_by' => $foreign->id, 'search_key' => 'privatecontact', 'city' => 'Tel Aviv']);
    $contactTable = tableIdentityQuery($user, ['q' => 'Tel Aviv']);
    expect($contactTable['page']->total())->toBe(1);
    $cells = $contactTable['page']->items()[0]->getAttribute('cells');
    expect($cells)->toMatchArray(['secondary_phone' => '+1 202-555-0171', 'preferred_contact_times' => 'Mornings, Evenings', 'preferred_contact_days' => 'Monday, Saturday, Sunday', 'city' => 'Tel Aviv', 'country' => 'Israel', 'notes_count' => 1]);
    expect(array_column($contactTable['columns'], 'id'))->toContain('secondary_phone', 'secondary_phone_label', 'preferred_contact_method', 'preferred_contact_times', 'preferred_contact_days', 'address', 'city', 'country', 'source', 'notes_count');
    $contactTable = tableIdentityQuery($user, ['filters' => [['id' => 'preferred_contact_method', 'op' => 'eq', 'value' => 'whatsapp'], ['id' => 'preferred_contact_days', 'op' => 'contains', 'value' => 'Monday']]]);
    expect($contactTable['page']->total())->toBe(1);
    $lead = app(Leads::class)->create($user, ['contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new']);
    $response = $this->actingAs($user)->get(tableUrl(['filters' => [['id' => 'contact_secondary_phone', 'op' => 'ends', 'value' => '0171']]]))->assertOk();
    expect($response->inertiaProps('table.page.data.0.id'))->toBe($lead->id)
        ->and($response->inertiaProps('table.page.data.0.cells.contact_preferred_contact_times'))->toBe('Mornings, Evenings')
        ->and($response->inertiaProps('table.page.data.0.cells.contact_notes_count'))->toBe(1);
});

it('retains scoped company catalog queries without exposing a company directory route', function () {
    $user = tableEmployee('Noa Cohen');
    $other = tableEmployee('Uri Ben David');
    foreach ([[$user, 'Levi Solutions', 'office@levi.example.test'], [$other, 'Hidden company', 'office@hidden.example.test']] as [$owner, $name, $email]) {
        Company::create(['name' => $name, 'email' => $email, 'phone' => '+1 202-555-0170', 'search_key' => strtolower($name), 'created_by' => $owner->id]);
    }
    $companyTable = tableIdentityQuery($user, ['q' => 'office@'], 'companies');
    expect($companyTable['page']->total())->toBe(1)
        ->and($companyTable['page']->items()[0]->getAttribute('cells')['email'])->toBe('office@levi.example.test')
        ->and(array_column($companyTable['columns'], 'id'))->toContain('name', 'email', 'phone')
        ->and(array_column($companyTable['columns'], 'id'))->not->toContain('secondary_phone', 'preferred_contact_method');
});
