<?php

use App\Models\User;
use Awove\Core\Imports\AutomationHolds;
use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\LegacyHistory;
use Awove\Core\Imports\LegacyPayload;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Operation;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Contact;
use Awove\Core\Services\Leads;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../scripts/browser-fixtures.php';

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});

function legacyFixture(array $batchOverrides = [], array $sourceOverrides = []): array
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo('imports.manage');
    $contact = Contact::create(['name' => 'Noa Cohen', 'email' => 'noa.history@example.test', 'created_by' => $user->id, 'search_key' => 'noacohen']);
    $lead = app(Leads::class)->create($user, ['contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new']);
    $ledger = app(Ledger::class);
    $history = app(LegacyHistory::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'sample', 'source_account' => 'source-'.$user->id,
        'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64), ...$batchOverrides]);
    $key = (string) Str::uuid();
    $source = ['board_id' => '000200', 'entity_type' => 'item', 'entity_id' => '000300', 'target_role' => 'primary-lead', 'relation_key' => 'primary', ...$sourceOverrides];
    $parent = $ledger->claim($user, $boundary, $batch['id'], $source, ['request_key' => $key, 'revision' => 1,
        'source_fingerprint' => hash_hmac('sha256', 'Fictional source lead', app('encrypter')->getKey()), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $ledger->finish($user, $boundary, $parent['id'], $key, ['state' => 'verified', 'target_type' => 'core.lead', 'target_id' => (string) $lead->id,
        'result_fingerprint' => hash_hmac('sha256', json_encode($lead->getAttributes(), JSON_THROW_ON_ERROR), app('encrypter')->getKey())]);

    return compact('user', 'lead', 'ledger', 'history', 'boundary', 'batch') + ['parent' => $parent['mapping_id']];
}

function legacyInput(array $extra = []): array
{
    return [...['kind' => 'update', 'source_id' => '000400', 'source_parent_id' => '000300', 'revision' => 1, 'request_key' => (string) Str::uuid(), 'previous_receipt_id' => null, 'reply_to_mapping_id' => null], ...$extra];
}

function legacyPayload(array $extra = []): array
{
    return [...['body_html' => '<p>Reviewed the fictional customer file.</p><p>A second line remains separate.</p>',
        'author' => ['source_id' => '000077', 'name' => 'Yael Cohen'], 'source_created_at' => '2020-02-29T09:30:52.123456+02:00',
        'source_updated_at' => '2020-03-01T08:42:17.987654321Z'], ...$extra];
}

function appendLegacy(array $fixture, array $input = [], array $payload = []): array
{
    return $fixture['history']->append($fixture['user'], $fixture['boundary'], $fixture['batch']['id'], $fixture['parent'], legacyInput($input), legacyPayload($payload));
}

/** A test-only dispatcher clone leaves every native listener intact and restores it afterwards. */
function duringLegacyCreated(callable $hook, callable $work): mixed
{
    $original = Activity::getEventDispatcher();
    Activity::setEventDispatcher(clone $original);
    Activity::created(function (Activity $activity) use ($hook): void {
        if ($activity->event === LegacyHistory::EVENT) {
            $hook($activity);
        }
    });
    try {
        return $work();
    } finally {
        Activity::setEventDispatcher($original);
    }
}

it('atomically retains exact ordinary source history with a real receipt and safely projects plaintext only', function () {
    $f = legacyFixture();
    $native = Activity::sole()->getAttributes();
    $html = legacyPayload()['body_html'].'<script>window.__legacyExecuted=true</script><img src=x onerror="window.__legacyExecuted=true"><a href="javascript:alert(1)">A safe label.</a>';
    $result = appendLegacy($f, payload: ['body_html' => $html]);
    expect($result['receipt']['state'])->toBe('verified')->and($result['activity_id'])->toBeInt();
    $entry = Activity::findOrFail($result['activity_id']);
    expect($entry->actor_id)->toBeNull()->and($entry->details['payload']['body_html'])->toBe($html)
        ->and($entry->details['payload']['source_created_at'])->toBe('2020-02-29T09:30:52.123456+02:00')
        ->and($entry->details['payload']['source_updated_at'])->toBe('2020-03-01T08:42:17.987654321Z')
        ->and($entry->details['payload']['author'])->toBe(['source_id' => '000077', 'name' => 'Yael Cohen'])
        ->and(Activity::find($native['id'])->getAttributes())->toBe($native)->and(User::count())->toBe(1);
    expect($entry->toArray())->not->toHaveKey('details');
    $response = $this->actingAs($f['user'])->getJson('/leads/'.$f['lead']->id.'/legacy-history')->assertOk();
    $dto = $response->json('entries.0');
    expect($dto['actor'])->toBeNull()->and($dto['legacy']['text'])->toBe("Reviewed the fictional customer file.\n\nA second line remains separate.\n\nA safe label.")
        ->and($dto['legacy']['ingested_by'])->toBe(['id' => $f['user']->id, 'name' => 'Shira Levi'])
        ->and($dto['legacy']['source_created_at'])->toBe('2020-02-29T09:30:52.123456+02:00')
        ->and($dto['legacy']['source_media_present'])->toBeTrue()
        ->and($dto['legacy']['ingested_at'])->not->toBe($dto['legacy']['source_created_at']);
    expect($response->getContent())->not->toContain('body_html', 'onerror', 'javascript:', '__legacyExecuted', 'family_key', 'source_mapping_id');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $profile = $this->get('/leads/'.$f['lead']->id)->assertOk();
    expect($profile->inertiaProps('lead.activity'))->toHaveCount(1)
        ->and($profile->inertiaProps('lead.activity.0.event'))->toBe('lead.created');
    expect(json_encode($profile->inertiaProps('lead'), JSON_THROW_ON_ERROR))->not->toContain($html, 'legacy_history', 'source_mapping_id');
    expect(fn () => $entry->update(['details' => []]))->toThrow(LogicException::class);
    expect(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('keeps unknown original author and time unknown and links replies to a retained source update', function () {
    $f = legacyFixture();
    $update = appendLegacy($f);
    $reply = appendLegacy($f, ['kind' => 'reply', 'source_id' => '000401', 'reply_to_mapping_id' => $update['receipt']['mapping_id']],
        ['author' => ['source_id' => null, 'name' => null], 'source_created_at' => null, 'source_updated_at' => null, 'body_html' => '<p>Original author was unavailable.</p>']);
    $created = appendLegacy($f, ['kind' => 'source-created', 'source_id' => '000300'], ['body_html' => null]);
    $replyDto = $f['history']->entry($f['user'], $f['lead'], $reply['activity_id'])['entry']['legacy'];
    expect($replyDto['author'])->toBe(['source_id' => null, 'name' => null])->and($replyDto['source_created_at'])->toBeNull()
        ->and($replyDto['source_updated_at'])->toBeNull()->and($replyDto['parent_activity_id'])->toBe($update['activity_id'])
        ->and($replyDto['ingested_by']['id'])->toBe($f['user']->id)
        ->and($f['history']->entry($f['user'], $f['lead'], $created['activity_id'])['entry']['legacy']['kind'])->toBe('source-created');
    expect(fn () => appendLegacy($f, ['kind' => 'source-created', 'source_id' => 'different'], ['body_html' => null]))->toThrow(HttpException::class);
});

it('replays original and cross-batch requests and appends explicit immutable source revisions without remapping', function () {
    $f = legacyFixture();
    $input = legacyInput();
    $first = appendLegacy($f, $input);
    $before = Activity::findOrFail($first['activity_id'])->getAttributes();
    expect(appendLegacy($f, $input))->toBe($first)->and(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(1);
    $batch = Batch::findOrFail($f['batch']['id']);
    $other = $f['ledger']->start($f['user'], $f['boundary'], ['batch_key' => (string) Str::uuid(), ...$batch->only(['provider', 'source_account', 'mapping_revision', 'code_revision', 'plan_revision'])]);
    $cross = appendLegacy([...$f, 'batch' => $other], ['request_key' => (string) Str::uuid()]);
    expect($cross['activity_id'])->toBe($first['activity_id'])->and($cross['receipt']['replayed_from_id'])->toBe($first['receipt']['id']);
    $revision = appendLegacy($f, ['revision' => 2, 'previous_receipt_id' => $cross['receipt']['id']], ['body_html' => '<p>Reviewed source correction.</p>']);
    expect($revision['activity_id'])->not->toBe($first['activity_id'])
        ->and(Activity::findOrFail($first['activity_id'])->getAttributes())->toBe($before)
        ->and(Mapping::find($first['receipt']['mapping_id'])->target_id)->toBe((string) $first['activity_id'])
        ->and(Mapping::find($revision['receipt']['mapping_id'])->target_id)->toBe((string) $revision['activity_id'])
        ->and(Activity::find($revision['activity_id'])->details['previous_activity_id'])->toBe($first['activity_id']);
    expect(appendLegacy($f, $input))->toBe($first);
    expect(fn () => appendLegacy($f, ['revision' => 4, 'previous_receipt_id' => $revision['receipt']['id']]))->toThrow(HttpException::class);
});

it('never overwrites changed target history or reuses an immutable source revision for different content', function (string $change) {
    $f = legacyFixture();
    $first = appendLegacy($f);
    if ($change === 'target') {
        $entry = Activity::findOrFail($first['activity_id']);
        $details = $entry->details;
        $details['payload']['text'] = 'A retained out-of-band correction.';
        DB::table('crm_activity')->where('id', $entry->id)->update(['details' => json_encode($details, JSON_THROW_ON_ERROR)]);
    }
    $before = Activity::findOrFail($first['activity_id'])->getAttributes();
    $result = appendLegacy($f, payload: $change === 'source' ? ['body_html' => '<p>A different immutable revision body.</p>'] : []);
    expect($result['receipt']['state'])->toBe('conflict')->and($result['receipt']['error_code'])->toBe($change.'_changed')
        ->and($result['activity_id'])->toBeNull()->and(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(1)
        ->and(Activity::findOrFail($first['activity_id'])->getAttributes())->toBe($before);
    expect(fn () => appendLegacy($f, ['revision' => 2, 'previous_receipt_id' => $first['receipt']['id']]))->toThrow(HttpException::class);
})->with(['source', 'target']);

it('rolls back the Activity when a reservation expires during the atomic append and retains only reconciliation status', function () {
    $f = legacyFixture();
    $result = duringLegacyCreated(fn () => $this->travel(301)->seconds(), fn () => appendLegacy($f));
    expect($result['receipt']['state'])->toBe('reconciliation_required')->and($result['activity_id'])->toBeNull()
        ->and(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(0)
        ->and(Operation::findOrFail($result['receipt']['id'])->finish_intent_fingerprint)->toBeNull();
});

it('does not take over an existing reservation and expires it without accepting a guessed destination', function () {
    $f = legacyFixture();
    $input = legacyInput();
    $captured = null;
    expect(function () use ($f, $input, &$captured) {
        return duringLegacyCreated(function (Activity $entry) use (&$captured): void {
            $operation = Operation::findOrFail($entry->details['operation_id']);
            $mapping = Mapping::findOrFail($operation->mapping_id);
            $captured = ['source' => $mapping->only(['board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key']),
                'claim' => ['request_key' => $operation->request_key, 'revision' => $operation->revision, 'source_fingerprint' => $operation->source_fingerprint,
                    'target_fingerprint' => $operation->observed_target_fingerprint, 'previous_receipt_id' => null]];
            throw new RuntimeException('Synthetic interruption before commit.');
        }, fn () => appendLegacy($f, $input));
    })->toThrow(RuntimeException::class);
    $reserved = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], $captured['source'], $captured['claim']);
    $result = appendLegacy($f, $input);
    expect($result['receipt'])->toBe($reserved)->and($result['activity_id'])->toBeNull();
    $busy = appendLegacy($f);
    expect($busy['receipt']['state'])->toBe('busy')->and($busy['activity_id'])->toBeNull();
    $this->travel(301)->seconds();
    expect(appendLegacy($f, $input)['receipt']['state'])->toBe('reconciliation_required')
        ->and(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(0);
});

it('checks fresh actor resource and runtime authorization before committing either Activity or receipt', function (string $change) {
    $f = legacyFixture();
    $url = config('app.url');
    $before = Operation::count();
    try {
        expect(fn () => duringLegacyCreated(function () use ($f, $change): void {
            match ($change) {
                'inactive' => $f['user']->forceFill(['active' => false])->save(),
                'unverified' => $f['user']->forceFill(['email_verified_at' => null])->save(),
                'permission' => $f['user']->revokePermissionTo('imports.manage'),
                'owner' => DB::table('crm_leads')->where('id', $f['lead']->id)->update(['owner_id' => null]),
                'archive' => DB::table('crm_leads')->where('id', $f['lead']->id)->update(['archived_at' => now()]),
                'paused' => DB::table('crm_import_batches')->where('id', $f['batch']['id'])->update(['state' => 'paused']),
                'runtime' => config(['app.url' => 'https://different.example.test']),
            };
        }, fn () => appendLegacy($f)))->toThrow(in_array($change, ['owner', 'archive'], true) ? AuthorizationException::class : HttpException::class);
    } finally {
        config(['app.url' => $url]);
    }
    expect(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(0)->and(Operation::count())->toBe($before);
})->with(['inactive', 'unverified', 'permission', 'owner', 'archive', 'paused', 'runtime']);

it('rejects cross-scope parents replies readers and archived targets while allowing ordinary authorized viewers', function () {
    $f = legacyFixture();
    $other = legacyFixture();
    $foreign = appendLegacy($other);
    expect(fn () => $f['history']->append($f['user'], $f['boundary'], $f['batch']['id'], $other['parent'], legacyInput(), legacyPayload()))->toThrow(HttpException::class);
    expect(fn () => appendLegacy($f, ['kind' => 'reply', 'reply_to_mapping_id' => $foreign['receipt']['mapping_id']]))->toThrow(HttpException::class);
    $own = appendLegacy($f);
    $this->actingAs($f['user'])->getJson('/leads/'.$other['lead']->id.'/legacy-history')->assertForbidden();
    $this->getJson('/leads/'.$f['lead']->id.'/legacy-history/'.$foreign['activity_id'])->assertNotFound();
    $f['user']->revokePermissionTo('imports.manage');
    $this->getJson('/leads/'.$f['lead']->id.'/legacy-history/'.$own['activity_id'])->assertOk();
    expect(fn () => appendLegacy($f))->toThrow(HttpException::class);
    $f['user']->givePermissionTo('imports.manage');
    $f['lead']->update(['archived_at' => now()]);
    expect(fn () => appendLegacy($f))->toThrow(AuthorizationException::class);
});

it('bounds list previews without losing accessible full text and paginates every retained entry', function () {
    $f = legacyFixture();
    $text = str_repeat('שלום ', 2200).'Final fictional history line.';
    $long = appendLegacy($f, payload: ['body_html' => '<p>'.$text.'</p>']);
    for ($i = 1; $i <= LegacyHistory::PAGE_SIZE + 1; $i++) {
        appendLegacy($f, ['source_id' => 'source-'.$i]);
    }
    $ids = [];
    $cursor = null;
    do {
        $page = $f['history']->page($f['user'], $f['lead'], $cursor);
        expect($page['entries'])->toHaveCount($cursor === null ? LegacyHistory::PAGE_SIZE : 2);
        foreach ($page['entries'] as $entry) {
            $ids[] = $entry['id'];
            expect(strlen($entry['legacy']['text']))->toBeLessThanOrEqual(LegacyHistory::PREVIEW_BYTES);
            if ($entry['id'] === $long['activity_id']) {
                expect($entry['legacy']['has_more_text'])->toBeTrue()->and($entry['legacy']['text'])->not->toContain('Final fictional history line.')
                    ->and(mb_check_encoding($entry['legacy']['text'], 'UTF-8'))->toBeTrue();
            }
        }
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);
    expect(array_unique($ids))->toHaveCount(LegacyHistory::PAGE_SIZE + 2);
    $full = $this->actingAs($f['user'])->getJson('/leads/'.$f['lead']->id.'/legacy-history/'.$long['activity_id'])->assertOk();
    expect($full->json('entry.legacy.text'))->toBe($text)->and($full->json('entry.legacy.has_more_text'))->toBeFalse();
    $this->getJson('/leads/'.$f['lead']->id.'/legacy-history?before=-1')->assertUnprocessable();
    $this->postJson('/leads/'.$f['lead']->id.'/legacy-history', [])->assertMethodNotAllowed();
});

it('rejects an attested alias update mapping instead of silently changing a reply source association', function () {
    $f = legacyFixture();
    $update = appendLegacy($f);
    $request = (string) Str::uuid();
    $alias = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'],
        ['board_id' => '000200', 'entity_type' => 'update', 'entity_id' => 'another-update', 'target_role' => 'legacy-history', 'relation_key' => 'another-occurrence'],
        ['request_key' => $request, 'revision' => 1, 'source_fingerprint' => hash_hmac('sha256', 'Alias metadata is not source proof', app('encrypter')->getKey()), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $f['ledger']->finish($f['user'], $f['boundary'], $alias['id'], $request,
        ['state' => 'verified', 'target_type' => 'core.activity', 'target_id' => (string) $update['activity_id'], 'result_fingerprint' => Operation::findOrFail($update['receipt']['id'])->result_fingerprint]);
    expect(fn () => appendLegacy($f, ['kind' => 'reply', 'source_id' => 'reply0001', 'reply_to_mapping_id' => $alias['mapping_id']]))->toThrow(HttpException::class);
    expect(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(1);
});

it('checks the independently supplied source parent and refuses to reparent a reply while appending its edits', function () {
    $f = legacyFixture();
    expect(fn () => appendLegacy($f, ['source_parent_id' => 'another-source-item']))->toThrow(HttpException::class);
    expect(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(0)->and(Operation::count())->toBe(1);
    $first = appendLegacy($f);
    $other = appendLegacy($f, ['source_id' => '000405']);
    $reply = appendLegacy($f, ['kind' => 'reply', 'source_id' => '000406', 'reply_to_mapping_id' => $first['receipt']['mapping_id']]);
    expect(fn () => appendLegacy($f, ['kind' => 'reply', 'source_id' => '000406', 'revision' => 2,
        'previous_receipt_id' => $reply['receipt']['id'], 'reply_to_mapping_id' => $other['receipt']['mapping_id']],
        ['body_html' => '<p>An edit cannot move this reply.</p>']))->toThrow(HttpException::class);
    expect(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(3);
});

it('limits browser history metadata plans to exact fixture accounts and refuses protected targets and references', function (string $foreign) {
    $run = 'desktop-1792693755194';
    $f = legacyFixture(['provider' => 'browser-fixture', 'source_account' => 'legacy-history-'.$run], ['board_id' => 'board-'.$run]);
    $entry = appendLegacy($f);
    $activities = Activity::where('lead_id', $f['lead']->id)->pluck('id')->all();
    $method = new ReflectionMethod(BrowserFixtures::class, 'legacyHistoryPlan');
    $plan = $method->invoke(null, [$f['user']->id], [$f['lead']->id], $activities, 'desktop');
    expect($plan['crm_import_batches'])->toBe([$f['batch']['id']])->and($plan['crm_import_operations'])->toHaveCount(2);
    if ($foreign === 'hold') {
        app(AutomationHolds::class)->placeNative($f['user'], $f['boundary'], $f['batch']['id'], 'lead', $f['lead']->id);
    } else {
        $other = legacyFixture();
        if ($foreign === 'mapping-target') {
            DB::table('crm_import_mappings')->where('id', $f['parent'])->update(['target_id' => (string) $other['lead']->id]);
        } elseif ($foreign === 'receipt-target') {
            DB::table('crm_import_operations')->where('id', $entry['receipt']['id'])->update(['result_target_type' => 'core.lead', 'result_target_id' => (string) $other['lead']->id]);
        } else {
            $row = Operation::findOrFail($entry['receipt']['id'])->getAttributes();
            unset($row['id']);
            DB::table('crm_import_operations')->insert([...$row, 'batch_id' => $other['batch']['id'], 'request_key' => (string) Str::uuid()]);
        }
    }
    expect(fn () => $method->invoke(null, [$f['user']->id], [$f['lead']->id], $activities, 'desktop'))->toThrow(RuntimeException::class);
    expect(Activity::find($entry['activity_id']))->not->toBeNull();
})->with(['mapping-target', 'receipt-target', 'external-operation', 'hold']);

it('preserves separated table cells and rejects parser depth recovery instead of silently merging or omitting source text', function () {
    $payload = legacyPayload(['body_html' => '<table><tr><th>First</th><th>Second</th><th></th></tr><tr><td>Noa</td><td>Cohen</td><td></td></tr></table><hr><p>After the table.</p>']);
    $text = LegacyPayload::validate('update', $payload)['text'];
    expect($text)->toBe("First\tSecond\t\n\nNoa\tCohen\t\n\nAfter the table.");
    $deep = str_repeat('<div>', 300).'This text must not be silently omitted.'.str_repeat('</div>', 300);
    expect(fn () => LegacyPayload::validate('update', legacyPayload(['body_html' => $deep])))->toThrow(ValidationException::class);
    $limit = str_repeat('a', LegacyPayload::MAX_BODY_BYTES);
    $valid = LegacyPayload::validate('update', legacyPayload(['body_html' => $limit]));
    expect($valid['body_html'])->toBe($limit)->and($valid['text'])->toBe($limit);
});

it('retains eligible hyperlink destinations as noninteractive plaintext and marks embedded media without exposing source URLs', function () {
    $html = '<p><a href="https://ordinary.example.test/quote?a=1&amp;b=2">Proposal</a> '
        .'<a href="http://ordinary.example.test/info">Information</a> '
        .'<a href="mailto:noa@example.test">Email</a> '
        .'<a href="tel:+972-50-123-4567">Phone</a> '
        .'<a href="javascript:alert(1)">Unsafe script</a> '
        .'<a href="data:text/html,unsafe">Unsafe data</a> '
        .'<a href="https://synthetic:invalid@ordinary.example.test/">Unsafe userinfo</a></p>'
        .'<img src="https://source.example.test/private-image?temporary=synthetic" alt="Source image description">';
    $payload = LegacyPayload::validate('update', legacyPayload(['body_html' => $html]));
    expect($payload['body_html'])->toBe($html)->and($payload['source_media_present'])->toBeTrue()
        ->and($payload['text'])->toContain('Proposal (https://ordinary.example.test/quote?a=1&b=2)', 'Information (http://ordinary.example.test/info)',
            'Email (mailto:noa@example.test)', 'Phone (tel:+972-50-123-4567)', 'Source image description')
        ->and($payload['text'])->not->toContain('javascript:', 'data:text', 'synthetic:invalid', 'private-image', 'temporary=');
    $imageOnly = LegacyPayload::validate('update', legacyPayload(['body_html' => '<img src="https://source.example.test/private">']));
    expect($imageOnly['text'])->toBe('')->and($imageOnly['source_media_present'])->toBeTrue();
});

it('rejects unavailable oversized malformed or undeclared source payloads without truncation or writes', function (array $payload) {
    $f = legacyFixture();
    $before = Operation::count();
    expect(fn () => appendLegacy($f, payload: $payload))->toThrow(ValidationException::class);
    expect(Operation::count())->toBe($before)->and(Activity::where('event', LegacyHistory::EVENT)->count())->toBe(0);
})->with([
    'missing body' => [['body_html' => null]],
    'oversize' => [['body_html' => str_repeat('x', LegacyPayload::MAX_BODY_BYTES + 1)]],
    'bad utf8' => [['body_html' => "invalid \xFF"]],
    'unsafe declaration' => [['body_html' => '<!DOCTYPE doc SYSTEM "https://invalid.example.test/"><p>Text</p>']],
    'date rollover' => [['source_created_at' => '2020-02-30T12:00:00Z']],
    'date only' => [['source_created_at' => '2020-02-29']],
    'private extra field' => [['password' => 'Never accepted as ordinary history']],
]);
