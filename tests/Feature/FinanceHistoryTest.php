<?php

use App\Models\User;
use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Operation;
use Awove\Core\Installation;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Task;
use Awove\Core\Services\Leads;
use Awove\Finance\History\Entry;
use Awove\Finance\History\HistoryCapability;
use Awove\Finance\History\Identity;
use Awove\Finance\History\Import;
use Awove\Finance\History\Payload;
use Awove\Finance\History\Record;
use Awove\Finance\History\Records;
use Awove\Finance\Models\LedgerRecord;
use Awove\Finance\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $manifest = Installation::manifest();
    $manifest['modules'] = ['finance'];
    $manifest['capabilities']['finance:history'] = true;
    $path = tempnam(sys_get_temp_dir(), 'finance-history-');
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    config(['core.manifest' => $path]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    @unlink(config('core.manifest'));
});

function fhActor(bool $all = true): User
{
    $user = User::factory()->create(['name' => 'Shira Levi']);
    $user->assignRole('Employee');
    $user->givePermissionTo(['imports.manage', ...(new HistoryCapability)->permissions()]);
    if (! $all) {
        $user->revokePermissionTo('finance.history.view.all');
    }

    return $user;
}
function fhFixture(): array
{
    $user = fhActor();
    $ledger = app(Ledger::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'sample', 'source_account' => 'history-'.$user->id, 'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)]);

    return compact('user', 'ledger', 'boundary', 'batch');
}
function fhInput(array $extra = []): array
{
    return ['board_id' => '200', 'source_id' => '300', 'kind' => 'income', 'owner_id' => null, 'placement' => 'internal', 'associations' => [], 'request_key' => (string) Str::uuid(), 'entry_request_key' => (string) Str::uuid(), ...$extra];
}
function fhFact(array $extra = []): array
{
    return ['key' => 'total', 'label' => 'Original amount', 'value_kind' => 'money', 'availability' => 'present', 'raw_json' => '9007199254740993.12345678901234567890', 'unit' => null, 'currency' => 'ILS', ...$extra];
}
function fhPayload(array $extra = []): array
{
    return ['label' => 'Carmel studio reference', 'facts' => [fhFact()], 'body_html' => null, 'author' => ['source_id' => '77', 'name' => 'Yael Cohen'], 'source_created_at' => '2020-02-29T09:30:52.123456789+02:00', 'source_updated_at' => null, ...$extra];
}
function fhCreate(array $f, array $input = [], array $payload = []): array
{
    return app(Import::class)->create($f['user'], $f['boundary'], $f['batch']['id'], fhInput($input), fhPayload($payload));
}
function fhAppend(array $f, array $result, array $input = [], array $payload = []): array
{
    return app(Import::class)->append($f['user'], $f['boundary'], $f['batch']['id'], $result['record_receipt']['mapping_id'], ['entry_kind' => 'update', 'source_id' => '400', 'source_parent_id' => '300', 'revision' => 1, 'request_key' => (string) Str::uuid(), 'previous_receipt_id' => null, 'reply_to_mapping_id' => null, ...$input], fhPayload(['facts' => [], 'body_html' => '<p>Original discussion.</p>', ...$payload]));
}
function fhIdentity(array $f, string $type, string $source, ?User $owner = null): array
{
    $owner ??= $f['user'];
    $class = $type === 'contacts' ? Contact::class : Company::class;
    $identity = $class::create(['name' => $type === 'contacts' ? 'Noa Cohen' : 'Carmel Studio', 'email' => Str::uuid().'@example.test', 'created_by' => $owner->id, 'search_key' => 'carmel']);
    app(Leads::class)->create($owner, [$type === 'contacts' ? 'contact_id' : 'company_id' => $identity->id, 'owner_id' => $owner->id, 'status_id' => 'new']);
    $request = (string) Str::uuid();
    $receipt = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], ['board_id' => '100', 'entity_type' => 'item', 'entity_id' => $source, 'target_role' => $type === 'contacts' ? 'primary-contact' : 'primary-company', 'relation_key' => 'primary'], ['request_key' => $request, 'revision' => 1, 'source_fingerprint' => hash_hmac('sha256', $source, app('encrypter')->getKey()), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $f['ledger']->finish($f['user'], $f['boundary'], $receipt['id'], $request, ['state' => 'verified', 'target_type' => $type === 'contacts' ? 'core.contact' : 'core.company', 'target_id' => (string) $identity->id, 'result_fingerprint' => hash_hmac('sha256', json_encode($identity->getAttributes()), app('encrypter')->getKey())]);

    return ['identity' => $identity, 'link' => ['mapping_id' => $receipt['mapping_id'], 'source_board_id' => '100', 'source_item_id' => $source]];
}
function duringFhCreated(callable $hook, callable $work): mixed
{
    $original = Entry::getEventDispatcher();
    Entry::setEventDispatcher(clone $original);
    Entry::created($hook);
    try {
        return $work();
    } finally {
        Entry::setEventDispatcher($original);
    }
}

it('retains exact financial JSON and source attribution without operational finance side effects or unsafe serialization', function () {
    $f = fhFixture();
    $html = '<p>Original comment.</p><script>window.unsafe=true</script><img src=x onerror="unsafe()">';
    $result = fhCreate($f, payload: ['body_html' => $html]);
    expect($result['record_receipt']['state'])->toBe('verified')->and($result['entry_receipt']['state'])->toBe('verified');
    $entry = Entry::sole();
    expect($entry->payload['facts'][0]['raw_json'])->toBe(fhFact()['raw_json'])->and($entry->payload['body_html'])->toBe($html)->and($entry->payload['source_created_at'])->toBe(fhPayload()['source_created_at']);
    $response = $this->actingAs($f['user'])->getJson('/finance/history/'.$result['record_id'].'/entries')->assertOk();
    $response->assertJsonPath('entries.0.facts.0.display', fhFact()['raw_json'])->assertJsonPath('entries.0.facts.0.currency', 'ILS')->assertJsonPath('entries.0.text', 'Original comment.')->assertJsonPath('entries.0.author.name', 'Yael Cohen')->assertJsonPath('entries.0.ingested_by.name', 'Shira Levi');
    expect($response->getContent())->not->toContain('body_html', 'raw_json', 'window.unsafe', 'onerror', 'family_key', 'source_mapping_id');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($entry->toArray())->not->toHaveKeys(['payload', 'operation_id'])->and(LedgerRecord::count())->toBe(0)->and(Payment::count())->toBe(0)->and(Task::count())->toBe(0)->and(Activity::count())->toBe(0)->and(Lead::count())->toBe(0)->and(User::count())->toBe(1);
    $this->get('/finance/history')->assertInertia(fn (Assert $p) => $p->component('Finance/History')->has('table.page.data', 1));
    $this->get('/finance/history/'.$result['record_id'])->assertInertia(fn (Assert $p) => $p->component('Finance/HistoryRecord')->where('record.label', 'Carmel studio reference'));
    expect(fn () => $entry->update(['payload' => []]))->toThrow(LogicException::class);
    expect(fn () => Record::sole()->delete())->toThrow(LogicException::class);
});

it('replays original and cross-batch receipts and retains immutable explicit financial revisions and reply parents', function () {
    $f = fhFixture();
    $input = fhInput();
    $first = fhCreate($f, $input);
    expect(fhCreate($f, $input))->toBe($first)->and(Record::count())->toBe(1)->and(Entry::count())->toBe(1);
    $batch = Batch::findOrFail($f['batch']['id']);
    $next = $f['ledger']->start($f['user'], $f['boundary'], ['batch_key' => (string) Str::uuid(), ...$batch->only(['provider', 'source_account', 'mapping_revision', 'code_revision', 'plan_revision'])]);
    $cross = fhCreate([...$f, 'batch' => $next]);
    expect($cross['record_id'])->toBe($first['record_id'])->and($cross['entry_id'])->toBe($first['entry_id'])->and($cross['entry_receipt']['replayed_from_id'])->toBe($first['entry_receipt']['id']);
    $revision = fhAppend($f, $first, ['entry_kind' => 'facts', 'source_id' => '300', 'revision' => 2, 'previous_receipt_id' => $first['entry_receipt']['id']], ['facts' => [fhFact(['raw_json' => '1.2300'])], 'label' => 'Corrected source reference']);
    expect(Entry::find($first['entry_id'])->payload['facts'][0]['raw_json'])->toBe(fhFact()['raw_json'])->and($revision['entry_id'])->not->toBe($first['entry_id']);
    $update = fhAppend($f, $first);
    $reply = fhAppend($f, $first, ['entry_kind' => 'reply', 'source_id' => '401', 'reply_to_mapping_id' => $update['entry_receipt']['mapping_id']], ['author' => ['source_id' => null, 'name' => null], 'source_created_at' => null]);
    expect(Entry::find($reply['entry_id'])->parent_entry_id)->toBe($update['entry_id'])->and(Entry::find($reply['entry_id'])->payload['author'])->toMatchArray(['source_id' => null, 'name' => null]);
    expect(fn () => fhAppend($f, $first, ['source_parent_id' => 'other']))->toThrow(HttpException::class);
    expect(fn () => fhAppend($f, $first, ['revision' => 3, 'previous_receipt_id' => $update['entry_receipt']['id']]))->toThrow(HttpException::class);
});

it('requires Finance scope and current Core access to every associated identity without duplicating facts', function () {
    $f = fhFixture();
    $person = fhIdentity($f, 'contacts', '101');
    $company = fhIdentity($f, 'companies', '102');
    $result = fhCreate($f, ['placement' => 'linked', 'owner_id' => $f['user']->id, 'associations' => [$person['link'], $company['link']]]);
    expect(Record::count())->toBe(1)->and(Identity::count())->toBe(2)->and(Entry::count())->toBe(1);
    $this->actingAs($f['user'])->getJson('/finance/history/identities/contacts/'.$person['identity']->id)->assertOk()->assertJsonCount(1, 'records');
    $this->getJson('/finance/history/identities/companies/'.$company['identity']->id)->assertOk()->assertJsonCount(1, 'records');
    $reader = fhActor(false);
    $reader->givePermissionTo('finance.history.view.all');
    expect(app(Records::class)->query($reader)->count())->toBe(0);
    $reader->givePermissionTo('leads.view.all');
    expect(app(Records::class)->query($reader)->count())->toBe(1);
    $reader->revokePermissionTo('leads.view.all');
    $this->actingAs($reader)->get('/finance/history/'.$result['record_id'])->assertNotFound();
    $this->getJson('/finance/history/'.$result['record_id'].'/entries')->assertNotFound();
    $f['user']->revokePermissionTo('finance.history.view.all');
    $other = fhActor();
    DB::table('crm_leads')->where('company_id', $company['identity']->id)->update(['owner_id' => $other->id]);
    DB::table('crm_companies')->where('id', $company['identity']->id)->update(['created_by' => $other->id]);
    expect(app(Records::class)->query($f['user'])->count())->toBe(0);
});

it('keeps own internal and unresolved placements distinct and requires independent exact source identity mappings', function () {
    $f = fhFixture();
    $own = fhActor(false);
    $internal = fhCreate($f, ['owner_id' => $own->id]);
    fhCreate($f, ['source_id' => '301', 'placement' => 'unresolved']);
    expect(app(Records::class)->query($own)->pluck('id')->all())->toBe([$internal['record_id']]);
    $person = fhIdentity($f, 'contacts', '101');
    expect(fn () => fhCreate($f, ['source_id' => '302', 'placement' => 'linked', 'associations' => [[...$person['link'], 'source_item_id' => 'wrong']]]))->toThrow(HttpException::class);
    expect(fn () => fhCreate($f, ['source_id' => '303', 'placement' => 'internal', 'associations' => [$person['link']]]))->toThrow(HttpException::class);
    expect(fn () => fhCreate($f, ['source_id' => '304', 'placement' => 'linked']))->toThrow(HttpException::class);
    expect(Record::count())->toBe(2)->and(Entry::count())->toBe(2);
});

it('refuses changed target or source retries instead of remapping or overwriting accepted records', function () {
    $f = fhFixture();
    $first = fhCreate($f);
    $changed = fhCreate($f, payload: ['facts' => [fhFact(['raw_json' => '12'])]]);
    expect($changed['entry_receipt']['state'])->toBe('conflict')->and(Entry::count())->toBe(1);
    expect(Entry::sole()->payload['facts'][0]['raw_json'])->toBe(fhFact()['raw_json']);
    DB::table('finance_history_records')->where('id', $first['record_id'])->update(['kind' => 'bonus']);
    expect(fn () => fhCreate($f))->toThrow(HttpException::class);
});

it('rolls back new records when a claim expires during SQL and never adopts an unresolved reservation', function () {
    $f = fhFixture();
    $input = fhInput();
    $result = duringFhCreated(fn () => $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds(), fn () => fhCreate($f, $input));
    expect($result['record_id'])->toBeNull()->and($result['record_receipt']['state'])->toBe('reconciliation_required')->and(Record::count())->toBe(0)->and(Entry::count())->toBe(0)->and(Identity::count())->toBe(0);
    expect(fhCreate($f, $input)['record_id'])->toBeNull()->and(Record::count())->toBe(0);
});

it('checks fresh actor permissions activity verification capability runtime and batch state before writing', function (string $change) {
    $f = fhFixture();
    match ($change) {
        'permission' => $f['user']->revokePermissionTo('finance.history.import'),
        'core-permission' => $f['user']->revokePermissionTo('imports.manage'),
        'active' => User::whereKey($f['user']->id)->update(['active' => false]),
        'verified' => User::whereKey($f['user']->id)->update(['email_verified_at' => null]),
        'batch' => Batch::whereKey($f['batch']['id'])->update(['state' => 'paused']),
        'runtime' => $f['boundary'] = str_repeat('0', 64),
        'capability' => file_put_contents(config('core.manifest'), json_encode([...Installation::manifest(), 'capabilities' => ['finance:history' => false]])),
    };
    expect(fn () => fhCreate($f))->toThrow(HttpException::class)->and(Record::count())->toBe(0)->and(Entry::count())->toBe(0);
})->with(['permission', 'core-permission', 'active', 'verified', 'batch', 'runtime', 'capability']);

it('rechecks permissions after the SQL write and rolls back all receipt and business effects', function () {
    $f = fhFixture();
    expect(fn () => duringFhCreated(fn () => $f['user']->revokePermissionTo('finance.history.import'), fn () => fhCreate($f)))->toThrow(HttpException::class);
    expect(Record::count())->toBe(0)->and(Entry::count())->toBe(0)->and(Operation::count())->toBe(0);
});

it('bounds aggregate preview text and exposes an authorized full reader without losing retained content', function () {
    $f = fhFixture();
    $text = str_repeat('ש', 6000);
    $result = fhCreate($f, payload: ['facts' => [fhFact(['raw_json' => json_encode($text, JSON_UNESCAPED_UNICODE)]), fhFact(['key' => 'second', 'raw_json' => json_encode($text, JSON_UNESCAPED_UNICODE)])], 'body_html' => '<p>'.$text.'</p>']);
    $row = app(Records::class)->page($f['user'], $result['record_id'])['entries'][0];
    expect(strlen($row['text']) + array_sum(array_map(fn ($fact) => strlen($fact['display'] ?? ''), $row['facts'])))->toBeLessThanOrEqual(Records::PREVIEW_BYTES)->and($row['has_more_text'])->toBeTrue();
    $this->actingAs($f['user'])->getJson($row['full_text_url'])->assertOk()->assertJsonPath('entry.text', $text)->assertJsonPath('entry.facts.1.display', $text);
});

it('preserves present null missing unavailable and nested number types and rejects malformed oversized unreviewed payloads', function () {
    $facts = [fhFact(['raw_json' => 'null']), fhFact(['key' => 'missing', 'availability' => 'missing', 'raw_json' => null]), fhFact(['key' => 'unavailable', 'availability' => 'unavailable', 'raw_json' => null]), fhFact(['key' => 'nested', 'raw_json' => '{"amount":9007199254740993.1234567890}'])];
    $valid = Payload::validate('facts', fhPayload(['facts' => $facts]));
    expect(array_column($valid['facts'], 'json_type'))->toBe(['null', null, null, 'object'])->and($valid['facts'][3]['raw_json'])->toBe($facts[3]['raw_json']);
    foreach ([fhFact(['raw_json' => '{bad']), fhFact(['raw_json' => str_repeat('1', Payload::MAX_FACT_BYTES + 1)]), fhFact(['value_kind' => 'formula-code']), fhFact(['currency' => 'ils']), fhFact(['availability' => 'missing']), fhFact(['secret' => 'forbidden'])] as $fact) {
        expect(fn () => Payload::validate('facts', fhPayload(['facts' => [$fact]])))->toThrow(ValidationException::class);
    }
});

it('rolls back only the new revision after expiry and never adopts the old inflight claim on retry', function () {
    $f = fhFixture();
    $first = fhCreate($f);
    $input = ['request_key' => (string) Str::uuid()];
    $expired = duringFhCreated(fn () => $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds(), fn () => fhAppend($f, $first, $input));
    expect($expired['entry_id'])->toBeNull()->and($expired['entry_receipt']['state'])->toBe('reconciliation_required')->and(Record::count())->toBe(1)->and(Entry::count())->toBe(1);
    expect(fhAppend($f, $first, $input))->toBe($expired);
    expect(fhAppend($f, $first)['entry_receipt']['state'])->toBe('conflict')->and(Entry::count())->toBe(1);
});

it('rejects changed mapping revisions and reply reparenting while keeping all accepted source occurrences', function () {
    $f = fhFixture();
    $first = fhCreate($f);
    $one = fhAppend($f, $first);
    $two = fhAppend($f, $first, ['source_id' => '402']);
    $reply = fhAppend($f, $first, ['entry_kind' => 'reply', 'source_id' => '401', 'reply_to_mapping_id' => $one['entry_receipt']['mapping_id']]);
    expect(fn () => fhAppend($f, $first, ['entry_kind' => 'reply', 'source_id' => '401', 'revision' => 2, 'previous_receipt_id' => $reply['entry_receipt']['id'], 'reply_to_mapping_id' => $two['entry_receipt']['mapping_id']]))->toThrow(HttpException::class);
    $batch = Batch::findOrFail($f['batch']['id']);
    $next = $f['ledger']->start($f['user'], $f['boundary'], ['batch_key' => (string) Str::uuid(), ...$batch->only(['provider', 'source_account', 'mapping_revision', 'code_revision', 'plan_revision']), 'plan_revision' => str_repeat('d', 64)]);
    expect(fhCreate([...$f, 'batch' => $next])['record_receipt']['state'])->toBe('conflict')->and(Entry::count())->toBe(4);
});

it('rechecks all linked Core access after writing without suppressing integrity events', function () {
    $f = fhFixture();
    $person = fhIdentity($f, 'contacts', '101');
    $f['user']->revokePermissionTo('finance.history.view.all');
    $result = fhCreate($f, ['placement' => 'linked', 'owner_id' => $f['user']->id, 'associations' => [$person['link']]]);
    $other = fhActor();
    expect(fn () => duringFhCreated(function () use ($person, $other) {
        DB::table('crm_contacts')->where('id', $person['identity']->id)->update(['created_by' => $other->id]);
        DB::table('crm_leads')->where('contact_id', $person['identity']->id)->update(['owner_id' => $other->id]);
    }, fn () => fhAppend($f, $result)))->toThrow(RuntimeException::class);
    expect(Entry::count())->toBe(1)->and(Activity::count())->toBe(1);
});

it('rechecks linked identity access after receipt verification before committing an append', function () {
    $f = fhFixture();
    $person = fhIdentity($f, 'contacts', '101');
    $f['user']->revokePermissionTo('finance.history.view.all');
    $result = fhCreate($f, ['placement' => 'linked', 'owner_id' => $f['user']->id, 'associations' => [$person['link']]]);
    $other = fhActor();
    $before = Operation::count();
    $original = Operation::getEventDispatcher();
    Operation::setEventDispatcher(clone $original);
    Operation::updated(function (Operation $receipt) use ($person, $other): void {
        if ($receipt->state === 'verified' && $receipt->result_target_type === 'finance.history-entry') {
            DB::table('crm_contacts')->where('id', $person['identity']->id)->update(['created_by' => $other->id]);
            DB::table('crm_leads')->where('contact_id', $person['identity']->id)->update(['owner_id' => $other->id]);
        }
    });
    try {
        expect(fn () => fhAppend($f, $result))->toThrow(RuntimeException::class);
    } finally {
        Operation::setEventDispatcher($original);
    }
    expect(Entry::count())->toBe(1)->and(Operation::count())->toBe($before)->and(app(Records::class)->query($f['user'])->count())->toBe(1);
});

it('adds only three retained Finance history tables and rejects destructive rollback', function () {
    foreach (['finance_history_records', 'finance_history_identities', 'finance_history_entries'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
    $migration = require __DIR__.'/../../packages/awove/finance/database/migrations/2026_11_27_001800_create_finance_source_history.php';
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
    expect(Schema::hasColumn('crm_leads', 'finance_history'))->toBeFalse();
});
