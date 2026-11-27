<?php

use App\Models\User;
use Awove\Core\Imports\AutomationHold;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Operation;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Leads;
use Awove\Marketing\Imports\AttributionImporter;
use Awove\Marketing\Imports\Originals;
use Awove\Marketing\Imports\SourcePolicy;
use Awove\Marketing\Imports\Sources;
use Awove\Marketing\MarketingModule;
use Awove\Marketing\Models\Attribution;
use Awove\Marketing\Models\History;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $m = Installation::manifest();
    $m['modules'] = ['marketing'];
    $m['capabilities']['sales'] = true;
    $path = tempnam(sys_get_temp_dir(), 'marketing-original-test-');
    file_put_contents($path, json_encode($m));
    config(['core.manifest' => $path]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(fn () => @unlink(config('core.manifest')));

function marketingOriginalFixture(bool $internal = false): array
{
    $user = User::factory()->create(['name' => 'Yael Levy', 'active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo([...(new MarketingModule)->permissions(), 'imports.manage', 'identities.manage', 'leads.view.all', 'leads.edit.all', 'leads.assign']);
    $target = $internal ? Company::create(['name' => 'Mymisrad internal fixture', 'created_by' => $user->id, 'search_key' => 'internal'])
        : app(Leads::class)->create($user, ['contact_id' => Contact::create(['name' => 'Noa Cohen', 'created_by' => $user->id, 'search_key' => 'noa'])->id, 'owner_id' => $user->id, 'status_id' => 'new', 'source' => 'Website']);
    app(Sources::class)->register('fixture-marketing', 'originals', new class implements SourcePolicy
    {
        public function authorize($actor, $batch, $parent, string $operation): void
        {
            abort_unless($batch->provider === 'fixture-marketing' && $parent->board_id === 'ordinary-board', 403);
        }

        public function columns($batch, $parent): array
        {
            return ['reason', 'consent'];
        }

        public function canonical($batch, $parent, array $original): array
        {
            $values = array_column($original['cells'], 'value', 'id');

            return ['qualification_reason' => ['available' => true, 'value' => json_decode($values['reason'], true)],
                'marketing_consent' => ['available' => $values['consent'] === '"yes"', 'value' => $values['consent'] === '"yes"' ? true : null]];
        }
    });
    $ledger = app(Ledger::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'fixture-marketing', 'source_account' => 'fixture-account', 'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)]);
    $key = (string) Str::uuid();
    $parent = $ledger->claim($user, $boundary, $batch['id'], ['board_id' => 'ordinary-board', 'entity_type' => 'item', 'entity_id' => 'source-item', 'target_role' => 'originals', 'relation_key' => 'primary'], ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => str_repeat('d', 64), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $ledger->finish($user, $boundary, $parent['id'], $key, ['state' => 'verified', 'target_type' => $internal ? 'core.company' : 'core.lead', 'target_id' => (string) $target->id, 'result_fingerprint' => str_repeat('e', 64)]);
    $input = ['request_key' => (string) Str::uuid(), 'revision' => 1, 'previous_receipt_id' => null, 'author' => ['source_id' => 'author-source', 'name' => 'Tamar Levi'], 'source_created_at' => '2020-01-02T09:00:00+02:00', 'source_updated_at' => null];
    $payload = ['cells' => [['id' => 'reason', 'label' => 'סיבת הפנייה', 'type' => 'text', 'representation' => 'json-string', 'available' => true, 'value' => '"<script>exact original</script>"'], ['id' => 'consent', 'label' => 'Consent', 'type' => 'status', 'representation' => 'json-string', 'available' => true, 'value' => '"unknown-not-refusal"']]];

    return compact('user', 'target', 'boundary', 'batch', 'parent', 'input', 'payload');
}
function preserveMarketingOriginal(array $f): array
{
    return app(Originals::class)->preserve($f['user'], $f['boundary'], $f['batch']['id'], $f['parent']['mapping_id'], $f['input'], $f['payload']);
}

it('retains exact Marketing originals and applies only validated module fields with verified receipts', function () {
    $f = marketingOriginalFixture();
    $saved = preserveMarketingOriginal($f);
    $replay = preserveMarketingOriginal($f);
    expect($saved['state'])->toBe('verified')->and($replay['history_id'])->toBe($saved['history_id'])->and(History::where('kind', 'source-lead')->count())->toBe(1);
    $applied = app(AttributionImporter::class)->apply($f['user'], $f['boundary'], $f['batch']['id'], $saved['history_id'], ['qualification_reason' => ['request_key' => (string) Str::uuid(), 'previous_receipt_id' => null], 'marketing_consent' => ['request_key' => (string) Str::uuid(), 'previous_receipt_id' => null]]);
    expect($applied['fields']['qualification_reason']['state'])->toBe('verified')->and($applied['fields']['marketing_consent']['state'])->toBe('unresolved')
        ->and(Attribution::sole()->payload['qualification_reason'])->toBe('<script>exact original</script>')->and(Attribution::sole()->payload)->not->toHaveKey('marketing_consent');
    $this->actingAs($f['user'])->getJson('/marketing/leads/'.$f['target']->id.'/originals')->assertOk()->assertJsonCount(1, 'entries')->assertJsonPath('entries.0.source.item_id', 'source-item')->assertJsonPath('entries.0.source.board_id', 'ordinary-board')->assertJsonMissingPath('entries.0.cells');
    $this->getJson('/marketing/leads/'.$f['target']->id.'/originals/'.$saved['history_id'])->assertOk()->assertJsonPath('cells.1.value', '"unknown-not-refusal"');
    expect(DB::table('crm_documents')->count())->toBe(0)->and(DB::table('marketing_attribution_snapshots')->count())->toBe(0);
});
it('keeps internal Marketing originals on the explicit Company without fabricating a Lead and enforces module access', function () {
    $f = marketingOriginalFixture(true);
    $saved = preserveMarketingOriginal($f);
    expect(Lead::count())->toBe(0)->and(History::sole()->kind)->toBe('source-company')->and(History::sole()->record_id)->toBe($f['target']->id);
    $this->actingAs($f['user'])->getJson('/marketing/companies/'.$f['target']->id.'/originals/'.$saved['history_id'])->assertOk();
    $f['user']->revokePermissionTo('marketing.attribution.view');
    $this->getJson('/marketing/companies/'.$f['target']->id.'/originals/'.$saved['history_id'])->assertForbidden();
});
it('refuses unregistered ownership and cross-module fields before preserving source values', function () {
    $f = marketingOriginalFixture();
    $f['payload']['cells'][0]['id'] = 'core-phone';
    expect(fn () => preserveMarketingOriginal($f))->toThrow(HttpException::class)->and(History::count())->toBe(0);
    app()->instance(Sources::class, new Sources);
    expect(fn () => app(Originals::class)->authorizeSource($f['user'], $f['boundary'], $f['batch']['id'], $f['parent']['mapping_id']))->toThrow(HttpException::class);
});
it('preserves unknown JSON siblings and CRM edits across source field revisions', function () {
    $f = marketingOriginalFixture();
    $saved = preserveMarketingOriginal($f);
    DB::table('marketing_attributions')->insert(['lead_id' => $f['target']->id, 'payload' => '{"external":123456789012345678901234567890,"campaign_id":"keep-external","object":{}}', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $key = (string) Str::uuid();
    $a = app(AttributionImporter::class)->apply($f['user'], $f['boundary'], $f['batch']['id'], $saved['history_id'], ['qualification_reason' => ['request_key' => $key, 'previous_receipt_id' => null]]);
    DB::update('UPDATE marketing_attributions SET payload=payload || ?::jsonb WHERE lead_id=?', [json_encode(['qualification_reason' => 'CRM edit']), $f['target']->id]);
    $replay = app(AttributionImporter::class)->apply($f['user'], $f['boundary'], $f['batch']['id'], $saved['history_id'], ['qualification_reason' => ['request_key' => $key, 'previous_receipt_id' => null]]);
    expect($replay['fields']['qualification_reason']['state'])->toBe('preserved_target_edit');
    $f['input']['revision'] = 2;
    $f['input']['previous_receipt_id'] = $saved['receipt_id'];
    $f['input']['request_key'] = (string) Str::uuid();
    $f['payload']['cells'][0]['value'] = '"Changed source"';
    $next = preserveMarketingOriginal($f);
    $conflict = app(AttributionImporter::class)->apply($f['user'], $f['boundary'], $f['batch']['id'], $next['history_id'], ['qualification_reason' => ['request_key' => (string) Str::uuid(), 'previous_receipt_id' => $a['fields']['qualification_reason']['receipt_id']]]);
    expect($conflict['fields']['qualification_reason']['state'])->toBe('conflict');
    $raw = DB::selectOne('SELECT payload::text AS raw FROM marketing_attributions')->raw;
    expect($raw)->toContain('123456789012345678901234567890', 'keep-external', 'CRM edit', '{}');
});

it('fails closed on altered immutable evidence and module disablement without returning raw originals', function () {
    $f = marketingOriginalFixture();
    $saved = preserveMarketingOriginal($f);
    $m = Installation::manifest();
    $m['modules'] = [];
    file_put_contents(config('core.manifest'), json_encode($m));
    $this->actingAs($f['user'])->getJson('/marketing/leads/'.$f['target']->id.'/originals/'.$saved['history_id'])->assertForbidden();
    $m['modules'] = ['marketing'];
    file_put_contents(config('core.manifest'), json_encode($m));
    $row = History::find($saved['history_id']);
    $details = $row->details;
    $details['payload']['cells'][0]['value'] = '"altered-private-value"';
    DB::table('marketing_history')->where('id', $row->id)->update(['details' => json_encode($details)]);
    $response = $this->getJson('/marketing/leads/'.$f['target']->id.'/originals/'.$saved['history_id'])->assertStatus(503);
    expect($response->getContent())->not->toContain('altered-private-value', 'exact original');
});
it('rejects unchanged new original revisions without creating duplicate history', function () {
    $f = marketingOriginalFixture();
    $saved = preserveMarketingOriginal($f);
    $f['input']['revision'] = 2;
    $f['input']['previous_receipt_id'] = $saved['receipt_id'];
    $f['input']['request_key'] = (string) Str::uuid();
    expect(fn () => preserveMarketingOriginal($f))->toThrow(HttpException::class, 'Unchanged originals');
    expect(History::where('kind', 'source-lead')->count())->toBe(1);
});

it('rolls back fields and receipts changed by the final receipt callback including preserved edits', function (string $mode) {
    $f = marketingOriginalFixture();
    $f['payload']['cells'][0]['value'] = '"Original qualification"';
    $f['payload']['cells'][1]['value'] = '"yes"';
    $saved = preserveMarketingOriginal($f);
    $reasonKey = (string) Str::uuid();
    $consentKey = (string) Str::uuid();
    $first = app(AttributionImporter::class)->apply($f['user'], $f['boundary'], $f['batch']['id'], $saved['history_id'], [
        'qualification_reason' => ['request_key' => $reasonKey, 'previous_receipt_id' => null],
        'marketing_consent' => ['request_key' => $consentKey, 'previous_receipt_id' => null],
    ]);
    DB::update('UPDATE marketing_attributions SET payload=payload || ?::jsonb WHERE lead_id=?', [json_encode(['marketing_consent' => false]), $f['target']->id]);
    $f['input'] = [...$f['input'], 'revision' => 2, 'previous_receipt_id' => $saved['receipt_id'], 'request_key' => (string) Str::uuid()];
    $f['payload']['cells'][0]['value'] = '"Changed qualification"';
    $next = preserveMarketingOriginal($f);
    $fields = [
        'qualification_reason' => ['request_key' => (string) Str::uuid(), 'previous_receipt_id' => $first['fields']['qualification_reason']['receipt_id']],
        'marketing_consent' => ['request_key' => $mode === 'replayed' ? $consentKey : (string) Str::uuid(),
            'previous_receipt_id' => $mode === 'replayed' ? null : $first['fields']['marketing_consent']['receipt_id']],
    ];
    $before = [Operation::count(), History::count(), Attribution::sole()->version];
    $mutated = false;
    $dispatcher = Operation::getEventDispatcher();
    Operation::setEventDispatcher(clone $dispatcher);
    Operation::updated(function (Operation $receipt) use ($f, $mode, &$mutated): void {
        if ($receipt->state === 'verified' && $receipt->result_target_type === 'marketing.attribution'
            && Mapping::findOrFail($receipt->mapping_id)->entity_type === 'field') {
            $mutated = true;
            DB::update('UPDATE marketing_attributions SET payload=payload || ?::jsonb WHERE lead_id=?',
                [json_encode($mode === 'applied' ? ['qualification_reason' => 'Late callback change'] : ['marketing_consent' => true]), $f['target']->id]);
        }
    });
    try {
        expect(fn () => app(AttributionImporter::class)->apply($f['user'], $f['boundary'], $f['batch']['id'], $next['history_id'], $fields))
            ->toThrow(HttpException::class, 'Marketing field changed after receipt verification');
    } finally {
        Operation::setEventDispatcher($dispatcher);
    }
    expect($mutated)->toBeTrue()->and(Attribution::sole()->payload)->toBe(['marketing_consent' => false, 'qualification_reason' => 'Original qualification'])
        ->and([Operation::count(), History::count(), Attribution::sole()->version])->toBe($before);
})->with(['applied', 'skipped', 'replayed']);

it('takes the automation advisory lock before the first Lead row lock during canonical import', function () {
    $f = marketingOriginalFixture();
    $saved = preserveMarketingOriginal($f);
    $queries = [];
    $connection = DB::connection();
    $dispatcher = $connection->getEventDispatcher();
    $connection->setEventDispatcher(clone $dispatcher);
    $connection->listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'binding' => $query->bindings[0] ?? null];
    });
    try {
        $result = app(AttributionImporter::class)->apply($f['user'], $f['boundary'], $f['batch']['id'], $saved['history_id'], [
            'qualification_reason' => ['request_key' => (string) Str::uuid(), 'previous_receipt_id' => null],
        ]);
    } finally {
        $connection->setEventDispatcher($dispatcher);
    }
    $advisory = [];
    $leadRows = [];
    foreach ($queries as $index => $query) {
        if (str_contains($query['sql'], 'pg_advisory_xact_lock') && is_string($query['binding']) && str_starts_with($query['binding'], 'crm-import-automation:')) {
            $advisory[] = $index;
        }
        if (str_contains($query['sql'], 'from "crm_leads"') && str_contains($query['sql'], 'for update')) {
            $leadRows[] = $index;
        }
    }
    expect($advisory)->not->toBeEmpty()->and($leadRows)->not->toBeEmpty();
    expect(min($advisory))->toBeLessThan(min($leadRows));
    expect($result['fields']['qualification_reason']['state'])->toBe('verified')
        ->and(AutomationHold::where('source', 'lead')->where('record_id', $f['target']->id)->where('state', 'held')->exists())->toBeTrue();
});
