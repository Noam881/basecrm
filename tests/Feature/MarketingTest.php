<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\Leads;
use Awove\Core\Services\WorkReminders;
use Awove\Marketing\Attributions;
use Awove\Marketing\ContentReminders;
use Awove\Marketing\MarketingModule;
use Awove\Marketing\Metrics;
use Awove\Marketing\Models\Attribution;
use Awove\Marketing\Models\AttributionSnapshot;
use Awove\Marketing\Models\Campaign;
use Awove\Marketing\Models\History;
use Awove\Marketing\Work;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $m = Installation::manifest();
    $m['modules'] = ['marketing'];
    $m['capabilities'] = ['sales' => false];
    $m['timezone'] = 'Asia/Jerusalem';
    $path = tempnam(sys_get_temp_dir(), 'marketing-');
    file_put_contents($path, json_encode($m));
    config(['core.manifest' => $path]);
    $this->artisan('crm:install')->assertSuccessful();
    $this->travelTo(new DateTimeImmutable('2026-10-25T12:00:00+02:00'));
});
afterEach(function () {
    @unlink(config('core.manifest'));
});
function mkUser(bool $all = false): User
{
    $u = User::factory()->create();
    $u->assignRole('Employee');
    $p = (new MarketingModule)->permissions();
    if (! $all) {
        $p = array_values(array_filter($p, fn ($p) => ! str_ends_with($p, '.all') && ! str_ends_with($p, '.assign')));
    }$u->givePermissionTo($p);

    return $u;
}
function mkCampaign(User $u, array $extra = []): array
{
    return [...['name' => 'Carmel autumn campaign', 'objective' => 'Invite enquiries about office planning.', 'channel' => 'facebook', 'owner_id' => $u->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-11-30', 'planned_budget' => '900.25', 'materials_url' => 'https://assets.example.test/carmel', 'landing_url' => 'https://example.test/offices', 'status' => 'active'], ...$extra];
}
function mkContent(User $u, array $extra = []): array
{
    return [...['title' => 'Yael office planning guide', 'channel' => 'instagram', 'format' => 'Article', 'owner_id' => $u->id, 'planned_on' => '2026-10-25', 'draft' => 'Review the draft with Tamar.', 'asset_url' => 'https://assets.example.test/guide', 'status' => 'ready', 'publication_url' => null, 'published_on' => null], ...$extra];
}
function mkReport(array $extra = []): array
{
    return [...['starts_on' => '2026-10-01', 'ends_on' => '2026-10-10', 'actual_spend' => '100.05', 'reported_leads' => 3, 'notes' => 'Manually checked campaign report.'], ...$extra];
}
function mkSales(bool $on = true): void
{
    $m = Installation::manifest();
    $m['capabilities']['sales'] = $on;
    file_put_contents(config('core.manifest'), json_encode($m));
}
function mkEnabled(bool $on): void
{
    $m = Installation::manifest();
    $m['modules'] = $on ? ['marketing'] : [];
    file_put_contents(config('core.manifest'), json_encode($m));
}
function mkLead(User $u): Lead
{
    mkSales();
    $c = Contact::create(['name' => 'Tamar Ben Ami', 'email' => uniqid().'@example.test', 'created_by' => $u->id, 'search_key' => 'tamar']);

    return app(Leads::class)->create($u, ['contact_id' => $c->id, 'owner_id' => $u->id, 'status_id' => 'new', 'source' => 'Website']);
}
it('runs campaigns content performance advanced tables and exports independently of Sales and Finance', function () {
    $u = mkUser();
    expect(app(Modules::class)->enabled('sales'))->toBeFalse()->and(app(Modules::class)->enabled('finance'))->toBeFalse();
    $this->actingAs($u)->post('/marketing/campaigns', mkCampaign($u))->assertRedirect();
    $c = Campaign::sole();
    $this->post('/marketing/campaigns/'.$c->id.'/reports', mkReport())->assertRedirect();
    $this->post('/marketing/content', mkContent($u))->assertRedirect();
    $this->get('/marketing')->assertInertia(fn (Assert $p) => $p->where('totals.actual_spend', '100.05')->where('totals.reported_leads', '3')->where('totals.cost_per_lead', '33.35'));
    $this->get('/marketing/campaigns')->assertInertia(fn (Assert $p) => $p->where('table.id', 'marketing.campaigns')->where('table.page.total', 1)->where('table.page.data.0.cells.name', $c->name)->where('table.page.data.0.record_url', '/marketing/campaigns/'.$c->id));
    $this->get('/marketing/reports')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1));
    $this->get('/marketing/content')->assertOk();
    $this->get('/marketing/campaigns/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('record.starts_on', '2026-10-01')->where('reports.0.starts_on', '2026-10-01')->where('linkedOpportunities', null));
    $csv = $this->get('/marketing/reports/export')->assertOk()->streamedContent();
    expect($csv)->toContain('100.05', '33.35', 'Carmel autumn campaign');
    expect(app(Modules::class)->dashboardWidgets($u))->toHaveCount(2);
});
it('uses weighted CPL including zero-lead spend and null for a zero denominator with whole reporting periods', function () {
    $u = mkUser();
    $c = app(Work::class)->save($u, 'campaigns', mkCampaign($u));
    app(Work::class)->report($u, $c->id, mkReport());
    app(Work::class)->report($u, $c->id, mkReport(['starts_on' => '2026-10-11', 'ends_on' => '2026-10-20', 'actual_spend' => '49.95', 'reported_leads' => 0]));
    $this->actingAs($u)->get('/marketing/reports')->assertInertia(fn (Assert $p) => $p->where('totals.actual_spend', '150.00')->where('totals.cost_per_lead', '50.00'));
    $this->get('/marketing/reports?from=2026-10-02&to=2026-10-20')->assertInertia(fn (Assert $p) => $p->where('totals.actual_spend', '49.95')->where('totals.reported_leads', '0')->where('totals.cost_per_lead', null)->where('table.page.total', 1));
    expect(Metrics::cpl('999999999999.99', '3'))->toBe('333333333333.33')->and(Metrics::cpl('1.00', '0'))->toBeNull();
});
it('rejects inclusive overlaps stale corrections and invalid exact amounts while retaining report history', function () {
    $u = mkUser();
    $c = app(Work::class)->save($u, 'campaigns', mkCampaign($u));
    $r = app(Work::class)->report($u, $c->id, mkReport());
    $this->actingAs($u)->post('/marketing/campaigns/'.$c->id.'/reports', mkReport(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-15']))->assertSessionHasErrors('starts_on');
    $this->post('/marketing/campaigns/'.$c->id.'/reports', mkReport(['starts_on' => '2026-10-11', 'ends_on' => '2026-10-15']))->assertRedirect();
    foreach (['-1.00', '1e2', '0.001', 0.1] as $v) {
        $this->post('/marketing/campaigns/'.$c->id.'/reports', mkReport(['starts_on' => '2026-11-01', 'ends_on' => '2026-11-02', 'actual_spend' => $v]))->assertSessionHasErrors('actual_spend');
    }
    $url = '/marketing/campaigns/'.$c->id.'/reports/'.$r->id;
    $this->patch($url, [...mkReport(['actual_spend' => '120.00']), 'version' => 1])->assertRedirect();
    $this->patch($url, [...mkReport(), 'version' => 1])->assertConflict();
    expect(History::where('kind', 'reports')->where('record_id', $r->id)->count())->toBe(2)->and($r->fresh()->actual_spend)->toBe('120.00');
});
it('freshly enforces owner scope all-only navigation and permissions on direct records filters exports files and reports', function () {
    $a = mkUser();
    $b = mkUser();
    $c = app(Work::class)->save($a, 'campaigns', mkCampaign($a));
    $content = app(Work::class)->save($a, 'content', mkContent($a));
    $this->actingAs($b)->get('/marketing/campaigns')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 0));
    foreach (['/marketing/campaigns/'.$c->id, '/marketing/campaigns/'.$c->id.'/document/materials_url', '/marketing/content/'.$content->id] as $url) {
        $this->get($url)->assertForbidden();
    }
    $this->post('/marketing/campaigns/'.$c->id.'/reports', mkReport())->assertForbidden();
    $csv = $this->get('/marketing/campaigns/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain($c->name);
    $viewer = User::factory()->create();
    $viewer->assignRole('Employee');
    $viewer->givePermissionTo(['marketing.campaigns.view.all']);
    expect(collect(app(Modules::class)->navigation($viewer))->contains(fn ($n) => $n->href === '/marketing'))->toBeTrue();
    $this->actingAs($viewer)->get('/marketing/campaigns/'.$c->id)->assertOk();
    $this->patch('/marketing/campaigns/'.$c->id, [...mkCampaign($a), 'version' => 1])->assertForbidden();
    $a->forceFill(['active' => false])->save();
    expect(fn () => app(Work::class)->save($a, 'campaigns', mkCampaign($a)))->toThrow(HttpException::class);
});
it('retains unassigned owners and rejects unauthorized reassignment while preserving archived actual spend', function () {
    $u = mkUser(true);
    $c = app(Work::class)->save($u, 'campaigns', mkCampaign($u, ['owner_id' => null]));
    app(Work::class)->report($u, $c->id, mkReport());
    $u->revokePermissionTo('marketing.campaigns.assign');
    $this->actingAs($u)->patch('/marketing/campaigns/'.$c->id, [...mkCampaign($u, ['owner_id' => null]), 'version' => 1])->assertRedirect();
    expect($c->fresh()->owner_id)->toBeNull();
    $other = mkUser();
    $this->patch('/marketing/campaigns/'.$c->id, [...mkCampaign($u, ['owner_id' => $other->id]), 'version' => 2])->assertForbidden();
    $this->post('/marketing/campaigns/'.$c->id.'/archive', ['version' => 2])->assertRedirect();
    $this->get('/marketing/campaigns')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 0));
    $this->get('/marketing/reports')->assertInertia(fn (Assert $p) => $p->where('totals.actual_spend', '100.05'));
    $this->post('/marketing/campaigns/'.$c->id.'/reports', mkReport())->assertForbidden();
});
it('archives content stops stale reminder delivery and retains publication and planning history', function () {
    $u = mkUser();
    $c = app(Work::class)->save($u, 'content', mkContent($u));
    $this->actingAs($u)->get('/marketing/content?view=due')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1));
    $source = new ContentReminders;
    expect(iterator_to_array($source->due()))->toHaveCount(1);
    (new DeliverWorkReminder('marketing', 'content', $c->id, 1, $u->id))->handle(app(WorkReminders::class));
    expect(WorkReminder::count())->toBe(1);
    $this->patch('/marketing/content/'.$c->id, [...mkContent($u, ['status' => 'published']), 'version' => 1])->assertSessionHasErrors(['publication_url', 'published_on']);
    $this->patch('/marketing/content/'.$c->id, [...mkContent($u, ['status' => 'published', 'publication_url' => 'https://example.test/guide', 'published_on' => '2026-10-25']), 'version' => 1])->assertRedirect();
    expect($source->current($c->id, $u->id))->toBeNull()->and(app(WorkReminders::class)->forUser($u))->toBe([]);
    $this->post('/marketing/content/'.$c->id.'/archive', ['version' => 2])->assertRedirect();
    expect($c->fresh()->published_on->format('Y-m-d'))->toBe('2026-10-25')->and(History::where('kind', 'content')->count())->toBe(3);
});
it('disabling Marketing denies every surface and stale notices without removing retained data', function () {
    $u = mkUser();
    $c = app(Work::class)->save($u, 'campaigns', mkCampaign($u));
    $content = app(Work::class)->save($u, 'content', mkContent($u));
    $lead = mkLead($u);
    app(Attributions::class)->update($u, $lead->id, ['version' => 0, 'fields' => ['ad_id' => 'ad-1']]);
    mkEnabled(false);
    foreach (['/marketing', '/marketing/campaigns', '/marketing/reports', '/marketing/content', '/marketing/campaigns/export', '/marketing/campaigns/'.$c->id, '/marketing/campaigns/'.$c->id.'/document/materials_url', '/marketing/leads/'.$lead->id.'/attribution'] as $url) {
        $this->actingAs($u)->get($url)->assertForbidden();
    }$this->post('/marketing/campaigns', mkCampaign($u))->assertForbidden();
    $this->post('/marketing/campaigns/'.$c->id.'/reports', mkReport())->assertForbidden();
    $this->patch('/marketing/leads/'.$lead->id.'/attribution', ['version' => 1, 'fields' => []])->assertForbidden();
    (new DeliverWorkReminder('marketing', 'content', $content->id, 1, $u->id))->handle(app(WorkReminders::class));
    expect(WorkReminder::count())->toBe(0)->and(Campaign::count())->toBe(1)->and(Attribution::count())->toBe(1);
    $this->get('/leads')->assertOk();
    mkEnabled(true);
    $this->get('/marketing/campaigns/'.$c->id)->assertOk();
    expect(app(Attributions::class)->describe($u, $lead->id)['fields']['ad_id'])->toBe('ad-1');
});
it('blocks changed installation currency totals and monetary writes while retaining rows and content editing', function () {
    $u = mkUser();
    $c = app(Work::class)->save($u, 'campaigns', mkCampaign($u));
    $content = app(Work::class)->save($u, 'content', mkContent($u));
    app(Work::class)->report($u, $c->id, mkReport());
    $m = Installation::manifest();
    $m['currency'] = $c->currency === 'ILS' ? 'USD' : 'ILS';
    file_put_contents(config('core.manifest'), json_encode($m));
    $this->actingAs($u)->get('/marketing/reports')->assertInertia(fn (Assert $p) => $p->where('totals', null)->where('currencyConsistent', false)->where('table.page.total', 1));
    $this->patch('/marketing/campaigns/'.$c->id, [...mkCampaign($u), 'version' => 1])->assertSessionHasErrors('currency');
    $this->post('/marketing/campaigns/'.$c->id.'/reports', mkReport())->assertSessionHasErrors('currency');
    $this->patch('/marketing/content/'.$content->id, [...mkContent($u), 'version' => 1])->assertRedirect();
});
it('copies exact legacy JSON IDs timestamps and versions once without normalizing or linking campaign names', function () {
    $u = mkUser();
    $lead = mkLead($u);
    $c = app(Work::class)->save($u, 'campaigns', mkCampaign($u));
    $raw = ['campaign_id' => '000001', 'campaign_name' => $c->name, 'ad_id' => 456, 'referrer_name' => null, 'unregistered' => ['enabled' => true, 'amount' => '01.00']];
    DB::table('crm_leads')->where('id', $lead->id)->update(['attribution' => json_encode($raw), 'version' => 9, 'created_at' => '2025-01-02T03:04:05Z', 'updated_at' => '2026-02-03T04:05:06Z']);
    $before = DB::table('crm_leads')->where('id', $lead->id)->first();
    $migration = require base_path('packages/awove/marketing/database/migrations/2026_10_25_001110_extract_legacy_attribution.php');
    $migration->up();
    $migration->up();
    $snapshot = AttributionSnapshot::sole();
    $a = Attribution::sole();
    expect($snapshot->payload)->toEqual($raw)->and($snapshot->lead_id)->toBe($lead->id)->and($snapshot->lead_version)->toBe(9)->and($a->payload)->toEqual($raw)->and($a->linked_campaign_id)->toBeNull()->and($a->getRawOriginal('created_at'))->toBe($before->created_at)->and($a->getRawOriginal('updated_at'))->toBe($before->updated_at);
    expect(DB::table('crm_leads')->where('id', $lead->id)->first())->toEqual($before);
    app(Attributions::class)->update($u, $lead->id, ['version' => 1, 'fields' => ['campaign_name' => 'Edited external name'], 'linked_campaign_id' => $c->id]);
    expect($snapshot->fresh()->payload)->toEqual($raw)->and($lead->fresh()->attribution)->toEqual($raw)->and($lead->fresh()->version)->toBe(9)->and(Attribution::sole()->payload['unregistered'])->toEqual($raw['unregistered'])->and(Attribution::sole()->payload['ad_id'])->toBe(456);
    expect(fn () => $snapshot->update(['payload' => []]))->toThrow(LogicException::class);
    $migration->up();
    expect(Attribution::sole()->payload['campaign_name'])->toBe('Edited external name')->and(Attribution::sole()->linked_campaign_id)->toBe($c->id)->and(AttributionSnapshot::sole()->payload)->toEqual($raw);
    expect(DB::table('crm_leads')->where('id', $lead->id)->first())->toEqual($before);
});
it('scopes attribution to current Lead ownership and independently redacts a hidden linked campaign everywhere', function () {
    $a = mkUser();
    $b = mkUser();
    $lead = mkLead($a);
    $campaign = app(Work::class)->save($a, 'campaigns', mkCampaign($a));
    app(Attributions::class)->update($a, $lead->id, ['version' => 0, 'fields' => ['ad_name' => 'Office design'], 'linked_campaign_id' => $campaign->id]);
    $this->actingAs($b)->get('/marketing/leads/'.$lead->id.'/attribution')->assertForbidden();
    $b->givePermissionTo('leads.view.all');
    $response = $this->get('/marketing/leads/'.$lead->id.'/attribution')->assertOk()->assertJsonPath('linked_campaign_id', null)->assertJsonPath('has_hidden_campaign', true)->assertJsonPath('fields.ad_name', 'Office design')->assertJsonPath('history.0.event', 'updated')->assertJsonPath('history.0.changed_fields.0', 'ad_name');
    $this->get('/leads?q=Office%20design')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('table.page.data.0.cells.module:marketing:campaign', null));
    $b->givePermissionTo('leads.edit.all');
    $this->patchJson('/marketing/leads/'.$lead->id.'/attribution', ['version' => 1, 'fields' => ['ad_name' => 'Revised']])->assertOk();
    expect(Attribution::sole()->linked_campaign_id)->toBe($campaign->id);
    $this->patchJson('/marketing/leads/'.$lead->id.'/attribution', ['version' => 2, 'fields' => ['ad_name' => 'Kept relation'], 'linked_campaign_id' => null])->assertOk();
    expect(Attribution::sole()->linked_campaign_id)->toBe($campaign->id);
    $this->patchJson('/marketing/leads/'.$lead->id.'/attribution', ['version' => 3, 'linked_campaign_id' => $campaign->id])->assertForbidden();
    $b->revokePermissionTo('marketing.attribution.view');
    $b->revokePermissionTo('marketing.attribution.edit');
    $this->get('/marketing/leads/'.$lead->id.'/attribution')->assertForbidden();
    $this->get('/leads')->assertInertia(fn (Assert $p) => $p->where('table.columns', fn ($cols) => collect($cols)->every(fn ($col) => ! str_starts_with($col['id'], 'attribution:'))));
});
it('validates module attribution changes and keeps saved attribution column IDs available only when authorized', function () {
    $u = mkUser();
    $lead = mkLead($u);
    $url = '/marketing/leads/'.$lead->id.'/attribution';
    $this->actingAs($u);
    foreach ([['unexpected' => 'x'], ['landing_page_url' => 'javascript:alert(1)'], ['platform' => 'invalid'], ['ad_id' => str_repeat('x', 201)]] as $fields) {
        $this->patchJson($url, ['version' => 0, 'fields' => $fields])->assertUnprocessable();
    }$this->patchJson($url, ['version' => 0, 'fields' => ['ad_id' => 'ad-34']])->assertOk();
    $this->patchJson($url, ['version' => 0, 'fields' => []])->assertConflict();
    $this->postJson('/preferences', ['table' => 'leads', 'columns' => ['contact', 'attribution:ad_id', 'status']])->assertOk();
    expect($this->get('/leads')->inertiaProps('table.preferences.columns'))->toBe(['contact', 'attribution:ad_id', 'status']);
    mkEnabled(false);
    expect($this->get('/leads')->inertiaProps('table.preferences.columns'))->not->toContain('attribution:ad_id');
    mkEnabled(true);
    expect($this->get('/leads')->inertiaProps('table.preferences.columns'))->toContain('attribution:ad_id');
});
it('exports current advanced table filters formula-safe values and denies unsanctioned module permissions', function () {
    $u = mkUser();
    app(Work::class)->save($u, 'campaigns', mkCampaign($u, ['name' => '=HYPERLINK("https://example.test")']));
    app(Work::class)->save($u, 'campaigns', mkCampaign($u, ['name' => 'Hidden from filter', 'channel' => 'google']));
    $response = $this->actingAs($u)->get('/marketing/campaigns?filters[0][id]=channel&filters[0][op]=eq&filters[0][value]=facebook')->assertOk();
    expect($response->inertiaProps('table.page.total'))->toBe(1);
    $csv = $this->get('/marketing/campaigns/export?channel=facebook')->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toContain('Hidden from filter');
    $u->revokePermissionTo('marketing.campaigns.export');
    $this->get('/marketing/campaigns/export')->assertForbidden();
    $plain = User::factory()->create();
    $plain->assignRole('Employee');
    $this->actingAs($plain)->get('/marketing/campaigns')->assertForbidden();
    expect($plain->can('marketing.campaigns.view.own'))->toBeFalse();
});

it('requires assignment permission for changed null but preserves an unchanged unassigned owner', function () {
    $u = mkUser();
    $c = app(Work::class)->save($u, 'campaigns', mkCampaign($u));
    $this->actingAs($u)->patch('/marketing/campaigns/'.$c->id, [...mkCampaign($u, ['owner_id' => null]), 'version' => 1])->assertForbidden();
    $this->post('/marketing/content', mkContent($u, ['owner_id' => null]))->assertForbidden();
});
it('calculates performance totals from the same advanced filters as the visible table and export', function () {
    $u = mkUser();
    $c = app(Work::class)->save($u, 'campaigns', mkCampaign($u));
    app(Work::class)->report($u, $c->id, mkReport());
    app(Work::class)->report($u, $c->id, mkReport(['starts_on' => '2026-10-11', 'ends_on' => '2026-10-20', 'actual_spend' => '49.95', 'reported_leads' => 0]));
    $this->actingAs($u)->get('/marketing/reports?filters[0][id]=reported_leads&filters[0][op]=eq&filters[0][value]=0')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('totals.actual_spend', '49.95')->where('totals.cost_per_lead', null));
});

it('extracts JSON arrays and JSON null without string casts or changing raw source rows', function () {
    $u = mkUser();
    $arrayLead = mkLead($u);
    $nullLead = mkLead($u);
    $raw = ['0001', 42, null, ['unknown' => ['a', false]]];
    DB::table('crm_leads')->where('id', $arrayLead->id)->update(['attribution' => json_encode($raw)]);
    DB::table('crm_leads')->where('id', $nullLead->id)->update(['attribution' => 'null']);
    $before = DB::table('crm_leads')->whereIn('id', [$arrayLead->id, $nullLead->id])->orderBy('id')->get();
    $migration = require base_path('packages/awove/marketing/database/migrations/2026_10_25_001110_extract_legacy_attribution.php');
    $migration->up();
    expect(AttributionSnapshot::where('lead_id', $arrayLead->id)->sole()->payload)->toEqual($raw)->and(Attribution::where('lead_id', $arrayLead->id)->sole()->payload)->toEqual($raw)->and(AttributionSnapshot::where('lead_id', $nullLead->id)->sole()->getRawOriginal('payload'))->toBe('null');
    app(Attributions::class)->update($u, $arrayLead->id, ['version' => 1, 'fields' => []]);
    expect(Attribution::where('lead_id', $arrayLead->id)->sole()->payload)->toEqual($raw);
    expect(DB::table('crm_leads')->whereIn('id', [$arrayLead->id, $nullLead->id])->orderBy('id')->get())->toEqual($before);
});

it('patches known attribution fields in PostgreSQL without rounding unknown JSON numbers or changing empty objects', function () {
    $u = mkUser();
    $lead = mkLead($u);
    $raw = '{"campaign_id":"00001","ad_id":922337203685477580712345,"precision":0.12345678901234567890123456789,"empty":{},"nested":[null,{"n":9999999999999999999999999999}]}';
    DB::update('UPDATE crm_leads SET attribution=?::jsonb WHERE id=?', [$raw, $lead->id]);
    $migration = require base_path('packages/awove/marketing/database/migrations/2026_10_25_001110_extract_legacy_attribution.php');
    $migration->up();
    $original = DB::table('marketing_attributions')->where('lead_id', $lead->id)->value('payload');
    $campaign = app(Work::class)->save($u, 'campaigns', mkCampaign($u));
    app(Attributions::class)->update($u, $lead->id, ['version' => 1, 'linked_campaign_id' => $campaign->id]);
    expect(DB::table('marketing_attributions')->where('lead_id', $lead->id)->value('payload'))->toBe($original);
    app(Attributions::class)->update($u, $lead->id, ['version' => 2, 'fields' => ['ad_name' => 'Exact patch']]);
    $equal = DB::selectOne("SELECT (payload - 'ad_name') = ?::jsonb AS unchanged FROM marketing_attributions WHERE lead_id=?", [$original, $lead->id]);
    expect($equal->unchanged)->toBeTrue()->and(DB::table('marketing_attribution_snapshots')->where('lead_id', $lead->id)->value('payload'))->toBe($original)->and(DB::table('crm_leads')->where('id', $lead->id)->value('attribution'))->toBe($original);
});
