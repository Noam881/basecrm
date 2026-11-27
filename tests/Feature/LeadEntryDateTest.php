<?php

use App\Models\User;
use Awove\Core\LeadDates;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Task;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Spreadsheet;
use Awove\Core\Tables\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function entryDateOwner(string $zone = 'Asia/Jerusalem'): User
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'display_preferences' => ['timezone' => $zone]]);
    $user->assignRole('Employee');

    return $user;
}

function entryDateLead(User $user, ?string $entered, string $created, array $extra = []): Lead
{
    $contact = Contact::create(['name' => 'Noa Cohen', 'email' => 'noa.entry.'.$user->id.'.'.Contact::count().'@example.test', 'created_by' => $user->id, 'search_key' => 'noacohen']);
    $lead = app(Leads::class)->create($user, ['contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new', ...$extra]);
    // Historical dates here belong only to synthetic fixtures; ordinary writers must reject them.
    DB::table('crm_leads')->where('id', $lead->id)->update(['entered_on' => $entered, 'created_at' => $created]);

    return $lead->fresh();
}

function entryDateQuery(array $input): string
{
    return '/leads?'.http_build_query($input);
}

it('adds a nullable date without backfilling or changing retained timestamps and refuses lossy rollback', function () {
    $user = entryDateOwner();
    $lead = entryDateLead($user, null, '2020-02-29T23:41:52+00:00');
    $before = (array) DB::table('crm_leads')->where('id', $lead->id)->first();
    unset($before['entered_on']);
    Schema::table('crm_leads', fn ($table) => $table->dropColumn('entered_on'));
    $migration = require base_path('packages/awove/crm-core/database/migrations/2026_11_27_001730_add_lead_entry_date.php');
    $migration->up();
    $after = (array) DB::table('crm_leads')->where('id', $lead->id)->first();
    expect($after['entered_on'])->toBeNull();
    unset($after['entered_on']);
    expect($after)->toBe($before);
    $type = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'crm_leads' AND column_name = 'entered_on'");
    expect($type->data_type)->toBe('date');
    $lead->update(['entered_on' => '2020-03-01']);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Retained lead-entry dates');
    expect($lead->fresh()->toArray()['entered_on'])->toBe('2020-03-01')
        ->and($lead->fresh()->created_at->format('Y-m-d\TH:i:sP'))->toBe('2020-02-29T23:41:52+00:00');
});

it('casts only exact calendar dates and never silently discards a supplied clock', function () {
    $lead = entryDateLead(entryDateOwner(), null, '2020-02-29T23:41:52.123456+00:00');
    foreach (['2020-02-30', '2020-2-01', '2020-02-29T12:30:00Z', '2020-02-29 00:00:00', 'tomorrow', 20200229] as $invalid) {
        expect(fn () => $lead->update(['entered_on' => $invalid]))->toThrow(ValidationException::class);
    }
    $lead->update(['entered_on' => '2020-02-29']);
    expect($lead->fresh()->entered_on)->toBeInstanceOf(CarbonImmutable::class)
        ->and($lead->fresh()->toArray()['entered_on'])->toBe('2020-02-29');
    $lead->update(['entered_on' => null]);
    expect($lead->fresh()->entered_on)->toBeNull();
});

it('retains Created catalog identity and saved datetime views while ordering by business day and exact fallback', function () {
    $user = entryDateOwner('Pacific/Honolulu');
    $late = entryDateLead($user, null, '2020-02-29T12:00:00+00:00'); // February 29, 02:00 local.
    $early = entryDateLead($user, null, '2020-02-29T11:00:00+00:00');
    $historical = entryDateLead($user, '2020-02-28', '2026-11-27T15:00:00+00:00');
    $future = entryDateLead($user, '2020-03-01', '2010-01-01T15:00:00+00:00');
    $view = ['id' => 'created-history', 'name' => 'Entry history', 'columns' => ['contact', 'created_at'], 'state' => ['sorts' => [['id' => 'created_at', 'direction' => 'asc']], 'filters' => [['id' => 'created_at', 'op' => 'gte', 'value' => '2020-02-28']]]];
    $normalizedView = [...$view, 'state' => ['q' => '', ...$view['state'], 'match' => 'all', 'per_page' => 25]];
    $this->actingAs($user)->postJson('/preferences', ['table' => 'leads', 'columns' => ['created_at', 'contact'], 'views' => [$view]])->assertOk();
    foreach (['asc' => [$historical->id, $early->id, $late->id, $future->id], 'desc' => [$future->id, $late->id, $early->id, $historical->id]] as $direction => $expected) {
        $response = $this->get(entryDateQuery(['sorts' => [['id' => 'created_at', 'direction' => $direction]]]))->assertOk();
        expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toBe($expected)
            ->and($response->inertiaProps('table.preferences.columns'))->toBe(['created_at', 'contact'])
            ->and($response->inertiaProps('table.preferences.views.0'))->toBe($normalizedView);
    }
    $catalog = app(Catalog::class)->columns('leads', $user);
    expect($catalog['created_at']->type)->toBe('datetime')
        ->and($catalog['created_at']->expression)->toBe('crm_leads.created_at')
        ->and($catalog['updated_at']->calendarExpression)->toBeNull()
        ->and(app(Catalog::class)->columns('contacts', $user)['created_at']->calendarExpression)->toBeNull();
    $response = $this->get('/leads/'.$historical->id)->assertOk();
    expect($response->inertiaProps('lead.entered_on'))->toBe('2020-02-28')
        ->and($response->inertiaProps('lead.created_at'))->toBe('2026-11-27T15:00:00.000000Z');
});

it('filters and searches Created by the business day with timezone-local timestamp fallback', function (string $zone, string $fallbackDay) {
    $user = entryDateOwner($zone);
    $dated = entryDateLead($user, '2020-02-29', '2026-11-27T15:00:00+00:00');
    $fallback = entryDateLead($user, null, '2020-02-29T09:30:00+00:00');
    $this->actingAs($user);
    $expected = $fallbackDay === '2020-02-29' ? [$dated->id, $fallback->id] : [$dated->id];
    foreach (['eq', 'between'] as $op) {
        $response = $this->get(entryDateQuery(['filters' => [['id' => 'created_at', 'op' => $op, 'value' => '2020-02-29', 'value2' => '2020-02-29']], 'sorts' => [['id' => 'id', 'direction' => 'asc']]]))->assertOk();
        expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toBe($expected);
    }
    $response = $this->get(entryDateQuery(['q' => '2020-02-29', 'sorts' => [['id' => 'id', 'direction' => 'asc']]]))->assertOk();
    expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toContain($dated->id, $fallback->id);
    $response = $this->get(entryDateQuery(['filters' => [['id' => 'created_at', 'op' => 'eq', 'value' => $fallbackDay]]]))->assertOk();
    expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toContain($fallback->id);
})->with([
    ['Pacific/Honolulu', '2020-02-28'],
    ['Pacific/Kiritimati', '2020-02-29'],
    ['Asia/Jerusalem', '2020-02-29'],
]);

it('keeps even a historically skipped civil date distinct in sorting filters and overview', function () {
    $user = entryDateOwner('Pacific/Apia');
    $next = entryDateLead($user, '2011-12-31', '2000-01-01T00:00:00+00:00');
    $skipped = entryDateLead($user, '2011-12-30', '2026-11-27T15:00:00+00:00');
    $previous = entryDateLead($user, '2011-12-29', '2025-01-01T00:00:00+00:00');
    $response = $this->actingAs($user)->get(entryDateQuery(['sorts' => [['id' => 'created_at', 'direction' => 'asc']]]))->assertOk();
    expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toBe([$previous->id, $skipped->id, $next->id]);
    $response = $this->get(entryDateQuery(['filters' => [['id' => 'created_at', 'op' => 'eq', 'value' => '2011-12-30']]]))->assertOk();
    expect(array_column($response->inertiaProps('table.page.data'), 'id'))->toBe([$skipped->id]);
    $response = $this->get('/dashboard?from=2011-12-30&to=2011-12-30')->assertOk();
    expect($response->inertiaProps('metrics.active'))->toBe(1);
});

it('uses the effective entry day for scoped pipeline and follow-ups but retains won closing periods', function () {
    $user = entryDateOwner('Pacific/Honolulu');
    $included = entryDateLead($user, '2020-02-29', '2026-11-27T15:00:00+00:00', ['estimated_value' => '12.34']);
    entryDateLead($user, null, '2020-03-01T09:59:59+00:00', ['estimated_value' => '0.01']); // Still February 29 locally.
    entryDateLead($user, '2020-03-01', '2020-02-29T15:00:00+00:00', ['estimated_value' => '900.00']);
    entryDateLead($user, null, '2020-03-01T10:00:00+00:00', ['estimated_value' => '900.00']);
    entryDateLead(entryDateOwner(), '2020-02-29', '2026-11-27T15:00:00+00:00', ['estimated_value' => '900.00']);
    entryDateLead($user, '2000-01-01', '2026-11-27T15:00:00+00:00', ['status_id' => 'won', 'closing_date' => '2020-02-29', 'final_value' => '23.45']);
    Task::create(['lead_id' => $included->id, 'assignee_id' => $user->id, 'title' => 'Fictional follow-up', 'due_at' => now()->addDay(), 'remind_at' => now()->addHour(), 'priority' => 'normal']);
    $response = $this->actingAs($user)->get('/dashboard?from=2020-02-29&to=2020-02-29')->assertOk();
    expect($response->inertiaProps('metrics.active'))->toBe(2)
        ->and($response->inertiaProps('salesOverview.estimated_pipeline'))->toBe('12.35')
        ->and($response->inertiaProps('tasks.0.lead_id'))->toBe($included->id)
        ->and($response->inertiaProps('salesOverview.won_count'))->toBe(1)
        ->and($response->inertiaProps('salesOverview.won_value'))->toBe('23.45');
});

it('rejects historical dates through ordinary create and edit services including null', function (string $field, mixed $value) {
    $user = entryDateOwner();
    $lead = entryDateLead($user, '2020-02-29', '2020-02-29T23:41:52.123456+00:00');
    $snapshot = $lead->getRawOriginal();
    $create = ['contact_id' => $lead->contact_id, 'owner_id' => $user->id, 'status_id' => 'new', $field => $value];
    $update = ['version' => $lead->version, $field => $value];
    expect(fn () => app(Leads::class)->create($user, $create))->toThrow(ValidationException::class);
    expect(fn () => app(Leads::class)->update($user, $lead->id, $update))->toThrow(ValidationException::class);
    expect($lead->fresh()->getRawOriginal())->toBe($snapshot)->and(Lead::count())->toBe(1);
})->with([
    ['entered_on', '2020-01-01'], ['entered_on', null],
    ['created_at', '2020-01-01T00:00:00Z'], ['updated_at', '2020-01-01T00:00:00Z'],
]);

it('rejects the new entry date in ordinary HTTP requests instead of setting or clearing it', function (mixed $value) {
    $user = entryDateOwner();
    $lead = entryDateLead($user, '2020-02-29', '2020-02-29T23:41:52+00:00');
    $snapshot = $lead->getRawOriginal();
    $this->actingAs($user)->postJson('/leads', ['contact_id' => $lead->contact_id, 'owner_id' => $user->id, 'status_id' => 'new', 'entered_on' => $value])->assertUnprocessable()->assertJsonValidationErrors('entered_on');
    $this->patchJson('/leads/'.$lead->id, ['version' => 1, 'business_sector' => 'Must not partially save', 'entered_on' => $value])->assertUnprocessable()->assertJsonValidationErrors('entered_on');
    expect($lead->fresh()->getRawOriginal())->toBe($snapshot)->and(Lead::count())->toBe(1);
})->with(['2020-01-01', null]);

it('preserves HTTP timestamp-ignore compatibility while saving valid fields and retaining correct versions and audit actors', function () {
    $user = entryDateOwner();
    $lead = entryDateLead($user, '2020-02-29', '2020-02-29T23:41:52+00:00');
    $created = $lead->getRawOriginal('created_at');
    $this->travelTo(CarbonImmutable::parse('2026-11-27T12:34:56Z'));
    $readonly = ['created_at' => '1990-01-01T00:00:00Z', 'updated_at' => '1990-01-01T00:00:00Z'];
    $this->actingAs($user)->patchJson('/leads/'.$lead->id, ['version' => 1, 'business_sector' => 'Consulting', ...$readonly])->assertRedirect();
    $lead->refresh();
    expect($lead->business_sector)->toBe('Consulting')->and($lead->version)->toBe(2)
        ->and($lead->getRawOriginal('created_at'))->toBe($created)
        ->and($lead->entered_on->format('Y-m-d'))->toBe('2020-02-29')
        ->and($lead->updated_at->toISOString())->toBe('2026-11-27T12:34:56.000000Z');
    $audit = Activity::where('lead_id', $lead->id)->where('event', 'lead.sales_details_updated')->sole();
    expect($audit->actor_id)->toBe($user->id)->and($audit->details)->toBe(['fields' => ['business_sector']]);
    $this->postJson('/leads', ['contact_id' => $lead->contact_id, 'owner_id' => $user->id, 'status_id' => 'new', 'business_sector' => 'Legal', ...$readonly])->assertRedirect();
    $new = Lead::whereKeyNot($lead->id)->sole();
    expect($new->business_sector)->toBe('Legal')->and($new->version)->toBe(1)->and($new->entered_on)->toBeNull()
        ->and($new->created_by)->toBe($user->id)
        ->and($new->created_at->toISOString())->toBe('2026-11-27T12:34:56.000000Z')
        ->and($new->updated_at->toISOString())->toBe('2026-11-27T12:34:56.000000Z');
    $audit = Activity::where('lead_id', $new->id)->where('event', 'lead.created')->sole();
    expect($audit->actor_id)->toBe($user->id)->and($audit->details)->toBe([])
        ->and($audit->created_at->year)->not->toBe(1990);
});

it('rejects unrecognized timezone SQL input instead of interpolating it', function () {
    expect(fn () => LeadDates::calendarExpression("UTC'; SELECT 1; --"))->toThrow(InvalidArgumentException::class);
});

it('exports the business date separately without enabling historical fields in ordinary CSV intake', function () {
    $user = entryDateOwner();
    $user->givePermissionTo(['exports.view', 'imports.manage']);
    entryDateLead($user, '2011-12-30', '2020-02-29T09:30:52+00:00');
    $csv = $this->actingAs($user)->get('/spreadsheets/export/leads')->assertOk()->streamedContent();
    $lines = preg_split('/\r?\n/', trim($csv));
    $row = array_combine(str_getcsv($lines[0], escape: ''), str_getcsv($lines[1], escape: ''));
    expect($row['entered_on'])->toBe('2011-12-30')->and($row['created_at'])->toContain('2020-02-29');
    $columns = app(Spreadsheet::class)->definitions()['leads']['columns'];
    expect($columns)->not->toContain('entered_on', 'created_at');
});
