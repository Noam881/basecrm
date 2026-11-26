<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Leads;
use Awove\Marketing\Attributions;
use Awove\Marketing\Models\Attribution;
use Awove\Marketing\Models\AttributionSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $manifest = Installation::manifest();
    $manifest['modules'] = ['marketing'];
    $manifest['capabilities'] = ['sales' => true];
    $path = tempnam(sys_get_temp_dir(), 'marketing-fields-');
    file_put_contents($path, json_encode($manifest));
    config(['core.manifest' => $path]);
    $this->artisan('crm:install')->assertSuccessful();
});

afterEach(function () {
    @unlink(config('core.manifest'));
});

function mlfUser(): User
{
    $user = User::factory()->create(['name' => 'Noa Levi']);
    $user->assignRole('Employee');
    $user->givePermissionTo(['marketing.attribution.view', 'marketing.attribution.edit']);

    return $user;
}

function mlfLead(User $user): Lead
{
    $contact = Contact::create(['name' => 'Tamar Ben Ami', 'email' => 'lead-fields-'.Str::uuid().'@example.test', 'created_by' => $user->id, 'search_key' => 'tamar']);

    return app(Leads::class)->create($user, ['contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new', 'source' => 'Website']);
}

it('publishes canonical Marketing storage keys and equivalent source-header aliases', function () {
    $schema = Attributions::fieldSchema();
    expect(array_keys($schema))->toContain('landing_page_url', 'referrer_name', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'marketing_consent', 'qualification_reason', 'qualification_business_description', 'qualification_improvement_topics', 'qualification_help_type', 'qualification_current_handler', 'qualification_desired_outcome', 'qualification_current_processes');
    expect($schema)->not->toHaveKeys(['landing_page', '- utm_campaign', '- utm_term', 'source']);
    expect(Attributions::FIELD_ALIASES['landing_page'])->toBe('landing_page_url')
        ->and(Attributions::FIELD_ALIASES['- utm_campaign'])->toBe('utm_campaign')
        ->and(Attributions::FIELD_ALIASES['- utm_term'])->toBe('utm_term')
        ->and(Attributions::FIELD_ALIASES['שאלון: מי מטפל כיום'])->toBe('qualification_current_handler')
        ->and($schema['marketing_consent']['type'])->toBe('boolean')
        ->and($schema['marketing_consent']['nullable'])->toBeTrue()
        ->and($schema['qualification_reason']['type'])->toBe('textarea')
        ->and($schema['qualification_reason']['max_length'])->toBe(5000);
});

it('round-trips the supplied Marketing field meanings without changing frozen attribution or unknown JSON', function () {
    $user = mlfUser();
    $lead = mlfLead($user);
    $raw = '{"utm_campaign":"00001","referrer_name":"Avi Cohen","future":{"opaque_id":999999999999999999999999,"empty":{}},"marketing_consent":"unclassified legacy value"}';
    DB::update('UPDATE crm_leads SET attribution=?::jsonb WHERE id=?', [$raw, $lead->id]);
    $migration = require base_path('packages/awove/marketing/database/migrations/2026_10_25_001110_extract_legacy_attribution.php');
    $migration->up();
    $snapshot = AttributionSnapshot::sole()->getRawOriginal('payload');
    $fields = [
        'utm_source' => 'facebook',
        'utm_medium' => 'cpc',
        'utm_content' => 'office-guide',
        'utm_term' => 'office planning',
        'fbclid' => '0',
        'gclid' => '0000015',
        'landing_page_url' => 'https://example.test/office',
        'referrer' => 'https://social.example.test/office-guide',
        'marketing_consent' => false,
        'qualification_reason' => 'Review the business workflow.',
        'qualification_business_description' => 'An independent design studio.',
        'qualification_improvement_topics' => 'Scheduling and customer follow-up.',
        'qualification_help_type' => 'A practical process review.',
        'qualification_current_handler' => 'The business owner.',
        'qualification_desired_outcome' => 'An agreed next step.',
        'qualification_current_processes' => "Calendar and email.\nA shared checklist.",
    ];
    $url = '/marketing/leads/'.$lead->id.'/attribution';
    $this->actingAs($user)->patchJson($url, ['version' => 1, 'fields' => $fields])->assertOk()
        ->assertJsonPath('fields.marketing_consent', false)
        ->assertJsonPath('fields.fbclid', '0')
        ->assertJsonPath('fields.gclid', '0000015')
        ->assertJsonPath('fields.utm_campaign', '00001')
        ->assertJsonPath('fields.referrer_name', 'Avi Cohen')
        ->assertJsonPath('field_schema.marketing_consent.type', 'boolean');
    expect(app(Attributions::class)->describe($user, $lead->id)['fields'])->toMatchArray($fields);
    $equal = DB::selectOne("SELECT (payload->'future') = (?::jsonb->'future') AS unchanged, jsonb_typeof(payload->'marketing_consent') AS consent_type FROM marketing_attributions WHERE lead_id=?", [$snapshot, $lead->id]);
    expect($equal->unchanged)->toBeTrue()->and($equal->consent_type)->toBe('boolean')
        ->and(AttributionSnapshot::sole()->getRawOriginal('payload'))->toBe($snapshot)
        ->and(DB::table('crm_leads')->where('id', $lead->id)->value('attribution'))->toBe($snapshot)
        ->and($lead->fresh()->version)->toBe(1);
    $this->patchJson($url, ['version' => 2, 'fields' => ['marketing_consent' => null]])->assertOk()->assertJsonPath('fields.marketing_consent', null);
    expect(Attribution::sole()->payload['marketing_consent'])->toBeNull();
    $this->patchJson($url, ['version' => 2, 'fields' => ['marketing_consent' => true]])->assertConflict();
    expect(Attribution::sole()->payload['marketing_consent'])->toBeNull();
});

it('normalizes only explicit boolean inputs and keeps unknown consent separate from refusal', function () {
    $user = mlfUser();
    $lead = mlfLead($user);
    expect(app(Attributions::class)->describe($user, $lead->id)['fields'])->not->toHaveKey('marketing_consent');
    $version = 0;
    foreach ([[false, false], [true, true], ['0', false], ['1', true], [null, null], ['', null], ['   ', null]] as [$input, $expected]) {
        app(Attributions::class)->update($user, $lead->id, ['version' => $version++, 'fields' => ['marketing_consent' => $input]]);
        expect(Attribution::sole()->payload['marketing_consent'])->toBe($expected)
            ->and(app(Attributions::class)->describe($user, $lead->id)['fields']['marketing_consent'])->toBe($expected);
    }
});

it('rejects unsafe URLs invalid consent and unbounded qualification text atomically', function () {
    $user = mlfUser();
    $lead = mlfLead($user);
    $url = '/marketing/leads/'.$lead->id.'/attribution';
    $this->actingAs($user);
    foreach ([
        ['marketing_consent' => 'yes'],
        ['marketing_consent' => 'false'],
        ['marketing_consent' => 2],
        ['marketing_consent' => []],
        ['referrer' => 'javascript:alert(1)'],
        ['referrer' => 'ftp://example.test/file'],
        ['landing_page_url' => 'javascript:alert(1)'],
        ['fbclid' => str_repeat('a', 501)],
        ['gclid' => ['opaque']],
        ['utm_term' => str_repeat('a', 201)],
        ['qualification_reason' => str_repeat('a', 5001)],
        ['qualification_current_handler' => ['owner']],
        ['landing_page' => 'https://example.test'],
        ['unexpected' => 'value'],
    ] as $fields) {
        $this->patchJson($url, ['version' => 0, 'fields' => $fields])->assertUnprocessable();
        expect(Attribution::count())->toBe(0)->and($lead->fresh()->version)->toBe(1);
    }
});

it('searches and filters typed lead fields while safely treating malformed historical consent as unknown', function () {
    $user = mlfUser();
    $refused = mlfLead($user);
    $accepted = mlfLead($user);
    $malformed = mlfLead($user);
    $unrecorded = mlfLead($user);
    $other = mlfUser();
    $foreign = mlfLead($other);
    app(Attributions::class)->update($user, $refused->id, ['version' => 0, 'fields' => ['marketing_consent' => false, 'qualification_reason' => 'A capacity review', 'fbclid' => '0']]);
    app(Attributions::class)->update($user, $accepted->id, ['version' => 0, 'fields' => ['marketing_consent' => true]]);
    app(Attributions::class)->update($other, $foreign->id, ['version' => 0, 'fields' => ['marketing_consent' => true, 'qualification_reason' => 'A capacity review']]);
    $legacy = Attribution::create(['lead_id' => $malformed->id, 'payload' => ['marketing_consent' => ['unknown']], 'version' => 1, 'updated_by' => $user->id]);
    $url = '/leads?filters[0][id]=attribution:marketing_consent&filters[0][op]=';
    $this->actingAs($user)->get($url.'eq&filters[0][value]=false')->assertOk()->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('table.page.data.0.id', $refused->id)->where('table.page.data.0.cells.attribution:marketing_consent', false));
    $this->get($url.'eq&filters[0][value]=0')->assertOk()->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1));
    $this->get($url.'eq&filters[0][value]=true')->assertOk()->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('table.page.data.0.id', $accepted->id));
    $this->get($url.'empty')->assertOk()->assertInertia(fn (Assert $p) => $p->where('table.page.total', 2));
    $this->get('/leads?q=capacity')->assertOk()->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('table.page.data.0.id', $refused->id));
    expect(app(Attributions::class)->describe($user, $malformed->id)['fields'])->not->toHaveKey('marketing_consent');
    expect($legacy->fresh()->payload['marketing_consent'])->toBe(['unknown']);
    $this->get('/marketing/leads/'.$foreign->id.'/attribution')->assertForbidden();
    $this->patchJson('/marketing/leads/'.$foreign->id.'/attribution', ['version' => 1, 'fields' => ['marketing_consent' => false]])->assertForbidden();
});

it('keeps new field values private after revocation or module disablement without erasing stored values', function () {
    $user = mlfUser();
    $lead = mlfLead($user);
    app(Attributions::class)->update($user, $lead->id, ['version' => 0, 'fields' => ['marketing_consent' => false, 'qualification_reason' => 'A private qualification answer']]);
    $this->actingAs($user);
    $this->postJson('/preferences', ['table' => 'leads', 'columns' => ['contact', 'attribution:marketing_consent', 'attribution:qualification_reason']])->assertOk();
    $manifest = Installation::manifest();
    $manifest['modules'] = [];
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $this->get('/marketing/leads/'.$lead->id.'/attribution')->assertForbidden();
    $this->get('/leads')->assertOk()->assertInertia(fn (Assert $p) => $p->where('table.columns', fn ($columns) => collect($columns)->every(fn ($column) => ! str_starts_with($column['id'], 'attribution:'))));
    expect(Attribution::sole()->payload['marketing_consent'])->toBeFalse();
    $manifest['modules'] = ['marketing'];
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $this->get('/leads')->assertOk()->assertInertia(fn (Assert $p) => $p->where('table.preferences.columns', ['contact', 'attribution:marketing_consent', 'attribution:qualification_reason']));
    $user->revokePermissionTo(['marketing.attribution.view', 'marketing.attribution.edit']);
    $this->get('/marketing/leads/'.$lead->id.'/attribution')->assertForbidden();
    $this->get('/leads')->assertOk()->assertInertia(fn (Assert $p) => $p->where('table.page.data.0.cells', fn ($cells) => ! collect($cells)->has('attribution:marketing_consent') && ! collect($cells)->has('attribution:qualification_reason')));
    expect(Attribution::sole()->payload['qualification_reason'])->toBe('A private qualification answer');
});

it('localizes the code-owned field schema without translating stored questionnaire answers or UTM values', function () {
    $user = mlfUser();
    $lead = mlfLead($user);
    app(Attributions::class)->update($user, $lead->id, ['version' => 0, 'fields' => ['marketing_consent' => false, 'qualification_business_description' => 'A studio run by Hadassah Cohen.', 'utm_source' => 'facebook']]);
    $manifest = Installation::manifest();
    $manifest['locale'] = 'he';
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $url = '/marketing/leads/'.$lead->id.'/attribution';
    $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('field_schema.qualification_business_description.label', 'תיאור העסק')->assertJsonPath('field_schema.marketing_consent.label', 'הסכמה לקבלת תוכן שיווקי')->assertJsonPath('fields.qualification_business_description', 'A studio run by Hadassah Cohen.')->assertJsonPath('fields.utm_source', 'facebook')->assertJsonPath('fields.marketing_consent', false);
    $manifest['locale'] = 'en';
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $this->getJson($url)->assertOk()->assertJsonPath('field_schema.qualification_business_description.label', 'Business description')->assertJsonPath('fields.qualification_business_description', 'A studio run by Hadassah Cohen.');
});
