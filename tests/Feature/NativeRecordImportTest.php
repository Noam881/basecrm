<?php

use App\Models\User;
use Awove\Core\Imports\AutomationHold;
use Awove\Core\Imports\AutomationHolds;
use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Ledger;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\NativeRecords;
use Awove\Core\Imports\NativeSourcePolicy;
use Awove\Core\Imports\NativeSources;
use Awove\Core\Imports\NativeValues;
use Awove\Core\Imports\Operation;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverReminder;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Reminder;
use Awove\Core\Models\Status;
use Awove\Core\Models\Task;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Tasks;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrdinaryFixturePolicy implements NativeSourcePolicy
{
    public array $seen = [];

    public ?Closure $check = null;

    public function authorize($actor, Batch $batch, array $source, string $kind, string $operation, array $fields, array $context): void
    {
        abort_unless($batch->provider === 'sample-native' && in_array($source['board_id'], ['100', 'staff'], true)
            && match ($source['target_role']) {
                'person', 'alias-person' => $kind === 'contacts', 'company' => $kind === 'companies', 'opportunity' => $kind === 'leads', 'owner' => $kind === 'users', default => false
            }, 403);
        $this->seen[] = compact('source', 'kind', 'operation', 'fields', 'context');
        if ($this->check) {
            ($this->check)($source, $kind, $operation, $fields, $context);
        }
    }
}

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $manifest = Installation::manifest();
    $manifest['capabilities']['sales'] = true;
    $manifest['custom_fields'] = ['reviewed_code' => ['type' => 'text', 'label' => 'Reviewed code'], 'other_code' => ['type' => 'text', 'label' => 'Other code'], 'marketing_consent' => ['type' => 'boolean', 'label' => 'Reserved field']];
    $path = tempnam(sys_get_temp_dir(), 'native-import-');
    file_put_contents($path, json_encode($manifest));
    config(['core.manifest' => $path]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    @unlink(config('core.manifest'));
});

function niFixture(bool $register = true): array
{
    $user = User::factory()->create(['name' => 'Shira Levi']);
    $user->assignRole('Employee');
    $user->givePermissionTo(['imports.manage', 'leads.assign', 'leads.view.all', 'leads.edit.all', 'identities.manage']);
    $policy = new OrdinaryFixturePolicy;
    if ($register) {
        foreach (['person', 'alias-person', 'company', 'opportunity', 'owner'] as $role) {
            app(NativeSources::class)->register('sample-native', $role, $policy);
        }
    }
    $ledger = app(Ledger::class);
    $writer = app(NativeRecords::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'sample-native', 'source_account' => 'sample-'.$user->id, 'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)]);

    return compact('user', 'policy', 'ledger', 'writer', 'boundary', 'batch');
}
function niSource(string $kind = 'contacts', string $id = 'person-1'): array
{
    return ['board_id' => '100', 'entity_type' => 'item', 'entity_id' => $id, 'target_role' => match ($kind) {
        'contacts' => 'person', 'companies' => 'company', 'leads' => 'opportunity'
    }, 'relation_key' => 'primary'];
}
function niInput(array $values, array $previous = [], array $extra = []): array
{
    $fields = [];
    foreach ($values as $key => $value) {
        $fields[$key] = ['available' => true, 'value' => $value, 'request_key' => (string) Str::uuid(), 'previous_receipt_id' => $previous[$key]['receipt_id'] ?? $previous[$key]['baseline_receipt_id'] ?? null];
    }

    return ['request_key' => (string) Str::uuid(), 'fields' => $fields, 'accept_duplicate' => false, 'currency' => null, ...$extra];
}
function niCreate(array $f, string $kind = 'contacts', array $values = [], string $id = 'person-1', array $options = []): array
{
    $values = $values ?: ($kind === 'contacts' ? ['name' => 'Noa Cohen', 'email' => 'noa.cohen@example.test', 'phone' => '+972-50-555-0101'] : ['name' => 'Carmel Studio', 'registration_number' => '515000001']);

    return $f['writer']->create($f['user'], $f['boundary'], $f['batch']['id'], $kind, niSource($kind, $id), niInput($values, extra: $options));
}
function niRef(array $result, string $id = 'person-1', string $board = '100'): array
{
    return ['mapping_id' => $result['entity_receipt']['mapping_id'], 'board_id' => $board, 'entity_id' => $id];
}
function niLead(array $f, array $contact, array $extra = [], string $id = 'lead-1'): array
{
    return niCreate($f, 'leads', ['contact_id' => niRef($contact), 'owner_id' => 'actor', 'status_id' => 'new', 'source' => 'Unknown', 'priority' => 'normal', 'entered_on' => '2020-02-01', 'created_at' => '2020-02-29T09:30:52.123456789+02:00', ...$extra], $id);
}
function niApply(array $f, array $record, array $values, array $options = []): array
{
    return $f['writer']->apply($f['user'], $f['boundary'], $f['batch']['id'], $record['entity_receipt']['mapping_id'], niInput($values, $record['fields'], $options));
}
function niDuring(string $class, string $event, callable $hook, callable $work): mixed
{
    $original = $class::getEventDispatcher();
    $class::setEventDispatcher(clone $original);
    $class::$event($hook);
    try {
        return $work();
    } finally {
        $class::setEventDispatcher($original);
    }
}

it('defaults every provider role to deny before creating native rows or import metadata', function () {
    $f = niFixture(false);
    expect(fn () => niCreate($f))->toThrow(HttpException::class)->and(Contact::count())->toBe(0)->and(Mapping::count())->toBe(0);
});

it('atomically creates native identities and distinct opportunities with exact native dates holds actor and field receipts', function () {
    $f = niFixture();
    $contact = niCreate($f);
    $company = niCreate($f, 'companies', id: 'company-1');
    $lead = niLead($f, $contact, ['company_id' => niRef($company, 'company-1'), 'needs' => 'A fictional advisory meeting.', 'custom:reviewed_code' => 'ABC']);
    $second = niLead($f, $contact, id: 'lead-2');
    expect($lead['state'])->toBe('verified')->and($second['target']['id'])->not->toBe($lead['target']['id'])->and(Contact::count())->toBe(1)->and(Lead::count())->toBe(2);
    $row = Lead::findOrFail($lead['target']['id']);
    expect($row->entered_on->format('Y-m-d'))->toBe('2020-02-01')->and($row->created_at->utc()->format('Y-m-d\TH:i:sP'))->toBe('2020-02-29T07:30:52+00:00')->and($row->created_by)->toBe($f['user']->id)->and($row->owner_id)->toBe($f['user']->id)->and($row->source)->toBe('Unknown')->and($row->custom_fields)->toBe(['reviewed_code' => 'ABC']);
    expect(AutomationHold::where('source', 'lead')->where('state', 'held')->count())->toBe(2)->and(app(AutomationHolds::class)->blocked('core', 'lead', $row->id))->toBeTrue()->and(Task::count())->toBe(0)->and(User::count())->toBe(1);
    $receipt = Operation::findOrFail($lead['fields']['created_at']['receipt_id']);
    expect($receipt->result_fingerprint)->toBe(NativeValues::fieldHash('leads', 'created_at', NativeValues::read($row, 'created_at')))->and($receipt->actor_id)->toBe($f['user']->id);
    expect(Activity::where('lead_id', $row->id)->where('event', 'lead.created')->sole()->actor_id)->toBe($f['user']->id);
    expect(json_encode($f['policy']->seen, JSON_THROW_ON_ERROR))->not->toContain('Noa Cohen', 'noa.cohen', 'fictional advisory', 'ABC');
});

it('replays a verified creation by actual readback and retains accepted mappings without duplicate objects', function () {
    $f = niFixture();
    $source = niSource();
    $input = niInput(['name' => 'Noa Cohen', 'email' => 'NOA.COHEN@example.test']);
    $first = $f['writer']->create($f['user'], $f['boundary'], $f['batch']['id'], 'contacts', $source, $input);
    $again = $f['writer']->create($f['user'], $f['boundary'], $f['batch']['id'], 'contacts', $source, $input);
    expect($again['target'])->toBe($first['target'])->and($again['entity_receipt'])->toBe($first['entity_receipt'])->and(Contact::count())->toBe(1)->and(Contact::sole()->email)->toBe('noa.cohen@example.test');
    $count = Operation::count();
    app(Identities::class)->update($f['user'], 'contacts', $first['target']['id'], ['email' => 'human.edit@example.test']);
    $preserved = $f['writer']->create($f['user'], $f['boundary'], $f['batch']['id'], 'contacts', $source, $input);
    expect($preserved['fields']['email']['state'])->toBe('unchanged_source')->and(Contact::sole()->email)->toBe('human.edit@example.test')->and(Operation::count())->toBe($count);
});

it('applies changed source against an unchanged field and preserves unrelated human edits without advancing their baseline', function () {
    $f = niFixture();
    $contact = niCreate($f);
    app(Identities::class)->update($f['user'], 'contacts', $contact['target']['id'], ['phone' => '+972-54-555-0102']);
    $before = Operation::findOrFail($contact['fields']['phone']['receipt_id'])->getAttributes();
    $result = niApply($f, $contact, ['email' => 'updated.source@example.test', 'phone' => '+972-50-555-0101']);
    expect($result['state'])->toBe('verified')->and($result['fields']['phone']['state'])->toBe('unchanged_source')->and(Contact::sole()->phone)->toBe('+972-54-555-0102')->and(Contact::sole()->email)->toBe('updated.source@example.test')->and(Operation::find($contact['fields']['phone']['receipt_id'])->getAttributes())->toBe($before);
    expect(Operation::where('mapping_id', $contact['fields']['phone']['mapping_id'])->count())->toBe(1);
});

it('retains explicit conflicts for changed source and changed CRM including already-matching drift without spoofing old observations', function (bool $alreadyMatches) {
    $f = niFixture();
    $contact = niCreate($f);
    $next = 'next.source@example.test';
    app(Identities::class)->update($f['user'], 'contacts', $contact['target']['id'], ['email' => $alreadyMatches ? $next : 'human@example.test']);
    $current = NativeValues::fieldHash('contacts', 'email', NativeValues::read(Contact::sole(), 'email'));
    $result = niApply($f, $contact, ['email' => $next, 'phone' => '+972-50-555-0109']);
    expect($result['state'])->toBe('conflict')->and($result['fields']['email']['state'])->toBe('conflict')->and($result['fields']['phone']['state'])->toBe('not_applied')->and(Contact::sole()->phone)->toBe('+972-50-555-0101');
    $receipt = Operation::findOrFail($result['fields']['email']['receipt_id']);
    expect($receipt->error_code)->toBe('target_changed')->and($receipt->observed_target_fingerprint)->toBe($current)->and($receipt->result_fingerprint)->toBeNull();
})->with([true, false]);

it('rejects stale field plans and changed mapping context instead of reversing a newer source update', function () {
    $f = niFixture();
    $contact = niCreate($f);
    $updated = niApply($f, $contact, ['email' => 'new.source@example.test']);
    expect(fn () => niApply($f, $contact, ['email' => 'old.source@example.test']))->toThrow(HttpException::class);
    $old = Batch::findOrFail($f['batch']['id']);
    $next = $f['ledger']->start($f['user'], $f['boundary'], ['batch_key' => (string) Str::uuid(), ...$old->only(['provider', 'source_account', 'mapping_revision', 'code_revision', 'plan_revision']), 'plan_revision' => str_repeat('d', 64)]);
    expect(fn () => niApply([...$f, 'batch' => $next], $updated, ['email' => 'another.source@example.test']))->toThrow(HttpException::class)->and(Contact::sole()->email)->toBe('new.source@example.test');
});

it('uses only reviewed canonical mapping aliases and rejects arbitrary target IDs or policy-disallowed substitutions', function () {
    $f = niFixture();
    $one = niCreate($f);
    $two = niCreate($f, values: ['name' => 'Yael Levy', 'email' => 'yael.levy@example.test'], id: 'person-2');
    $source = [...niSource(id: 'alias-1'), 'target_role' => 'alias-person'];
    $f['policy']->check = function ($origin, $kind, $operation, $fields, $context) use ($one): void {
        if ($operation === 'consolidate') {
            abort_unless(($context['canonical']['mapping_id'] ?? null) === $one['entity_receipt']['mapping_id'], 403);
        }
    };
    $alias = $f['writer']->alias($f['user'], $f['boundary'], $f['batch']['id'], 'contacts', $source, niRef($one), (string) Str::uuid());
    expect($alias['target'])->toBe($one['target'])->and(Contact::count())->toBe(2);
    expect(fn () => $f['writer']->alias($f['user'], $f['boundary'], $f['batch']['id'], 'contacts', $source, niRef($two, 'person-2'), (string) Str::uuid()))->toThrow(HttpException::class);
    expect(fn () => niLead($f, $one, ['contact_id' => $one['target']['id']]))->toThrow(HttpException::class);
    expect(fn () => $f['writer']->alias($f['user'], $f['boundary'], $f['batch']['id'], 'leads', niSource('leads', 'lead-1'), niRef($one), (string) Str::uuid()))->toThrow(HttpException::class);
});

it('pins independent relationship source IDs and the originating lead choice in server policy', function () {
    $f = niFixture();
    $one = niCreate($f);
    $two = niCreate($f, values: ['name' => 'Yael Levy', 'email' => 'yael.levy@example.test'], id: 'person-2');
    $f['policy']->check = function ($source, $kind, $operation, $fields, $context) use ($one): void {
        if ($kind === 'leads' && isset($context['references']['contact_id'])) {
            abort_unless($context['references']['contact_id']['mapping_id'] === $one['entity_receipt']['mapping_id'], 403);
        }
    };
    expect(fn () => niLead($f, $one, ['contact_id' => niRef($two, 'person-2')]))->toThrow(HttpException::class);
    expect(fn () => niLead($f, $one, ['contact_id' => [...niRef($one), 'entity_id' => 'wrong']]))->toThrow(HttpException::class)->and(Lead::count())->toBe(0);
});

it('refuses initial field adoption onto existing differing values and allows an exact verified initial match', function () {
    $f = niFixture();
    $contact = niCreate($f, values: ['name' => 'Noa Cohen']);
    $same = niApply($f, $contact, ['email' => null]);
    expect($same['fields']['email']['state'])->toBe('verified');
    $different = niApply($f, $contact, ['phone' => '+972-50-555-0109']);
    expect($different['state'])->toBe('conflict')->and(Contact::sole()->phone)->toBeNull();
});

it('tracks registered custom fields separately so changing one cannot overwrite another human edit', function () {
    $f = niFixture();
    $contact = niCreate($f);
    $lead = niLead($f, $contact, ['custom:reviewed_code' => 'A', 'custom:other_code' => 'B']);
    $row = Lead::sole();
    app(Leads::class)->update($f['user'], $row->id, ['version' => $row->version, 'custom_fields' => ['reviewed_code' => 'A', 'other_code' => 'Human']]);
    $result = niApply($f, $lead, ['custom:reviewed_code' => 'C', 'custom:other_code' => 'B']);
    expect($result['fields']['custom:other_code']['state'])->toBe('unchanged_source')->and(Lead::sole()->custom_fields)->toBe(['reviewed_code' => 'C', 'other_code' => 'Human']);
    expect(fn () => niApply($f, $lead, ['custom:unregistered' => 'X']))->toThrow(HttpException::class);
    expect(fn () => niApply($f, $lead, ['custom:marketing_consent' => true]))->toThrow(HttpException::class);
});

it('retains native validation and rejects module payloads readonly metadata invalid dates and ambiguous currency', function () {
    $f = niFixture();
    foreach (['attribution', 'marketing_consent', 'qualification', 'password', 'created_by', 'notes', 'vault', 'finance_total'] as $key) {
        expect(fn () => niCreate($f, values: ['name' => 'Noa Cohen', $key => 'forbidden']))->toThrow(HttpException::class);
    }
    expect(fn () => niCreate($f, values: ['name' => 'Noa Cohen', 'email' => 'invalid']))->toThrow(RuntimeException::class);
    $contact = niCreate($f);
    expect(fn () => niLead($f, $contact, ['created_at' => '2020-02-29']))->toThrow(RuntimeException::class);
    expect(fn () => niLead($f, $contact, ['entered_on' => '2020-02-29T00:00:00Z']))->toThrow(RuntimeException::class);
    expect(fn () => niLead($f, $contact, ['estimated_value' => '10.00']))->toThrow(HttpException::class);
    expect(fn () => app(Leads::class)->create($f['user'], ['contact_id' => $contact['target']['id'], 'owner_id' => $f['user']->id, 'status_id' => 'new', 'created_at' => '2020-02-29T00:00:00Z']))->toThrow(ValidationException::class);
});

it('writes ordinary Core opportunity money milestones and links using native validation and exact current currency', function () {
    $f = niFixture();
    $contact = niCreate($f);
    $values = ['contact_id' => niRef($contact), 'owner_id' => 'actor', 'status_id' => 'new', 'source' => 'Unknown', 'estimated_value' => '1000.10', 'final_value' => '900', 'invoice_sent_on' => '2020-02-29', 'contract_url' => 'https://example.test/fictional-agreement', 'privacy_policy_accepted' => '1'];
    $result = niCreate($f, 'leads', $values, 'lead-1', ['currency' => Installation::settings()['currency']]);
    expect($result['state'])->toBe('verified')->and(Lead::sole()->estimated_value)->toBe('1000.10')->and(Lead::sole()->final_value)->toBe('900.00')->and(Lead::sole()->privacy_policy_accepted)->toBeTrue();
});

it('rolls back native rows and all completed receipts when a reservation expires while retaining reconciliation state', function () {
    $f = niFixture();
    $input = niInput(['name' => 'Noa Cohen']);
    $result = niDuring(Contact::class, 'created', fn () => $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds(), fn () => $f['writer']->create($f['user'], $f['boundary'], $f['batch']['id'], 'contacts', niSource(), $input));
    expect($result['state'])->toBe('reconciliation_required')->and(Contact::count())->toBe(0)->and(Operation::where('state', 'verified')->count())->toBe(0);
    $again = $f['writer']->create($f['user'], $f['boundary'], $f['batch']['id'], 'contacts', niSource(), $input);
    expect($again['state'])->toBe('reconciliation_required')->and(Contact::count())->toBe(0);
});

it('holds existing parent leads before identity writes and retains old CRM data after an expired field finish', function () {
    $f = niFixture();
    $contact = niCreate($f);
    $lead = niLead($f, $contact);
    $version = Lead::sole()->version;
    $result = niDuring(Contact::class, 'updated', function () use ($lead) {
        expect(app(AutomationHolds::class)->blocked('core', 'lead', $lead['target']['id']))->toBeTrue();
        $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds();
    }, fn () => niApply($f, $contact, ['email' => 'new.source@example.test']));
    expect($result['state'])->toBe('not_applied')->and($result['fields']['email']['state'])->toBe('reconciliation_required')->and(Contact::sole()->email)->toBe('noa.cohen@example.test')->and(Lead::sole()->version)->toBe($version);
});

it('rejects late actor Core ACL runtime batch owner and source policy revocation and rolls back the native write', function (string $change) {
    $f = niFixture();
    $contact = niCreate($f);
    $lead = niLead($f, $contact);
    $before = Operation::count();
    expect(fn () => niDuring(Contact::class, 'updated', function () use ($f, $change, $contact) {
        match ($change) {
            'actor' => User::whereKey($f['user']->id)->update(['active' => false]),
            'permission' => $f['user']->revokePermissionTo('imports.manage'),
            'acl' => $f['user']->revokePermissionTo('identities.manage'),
            'batch' => Batch::whereKey($f['batch']['id'])->update(['state' => 'paused']),
            'runtime' => config(['app.url' => 'https://changed.example.test']),
            'policy' => $f['policy']->check = fn () => abort(403),
        };
        if ($change === 'acl') {
            DB::table('crm_contacts')->where('id', $contact['target']['id'])->update(['created_by' => User::factory()->create()->id]);
        }
    }, fn () => niApply($f, $contact, ['email' => 'new.source@example.test'])))->toThrow(Exception::class);
    expect(Contact::sole()->email)->toBe('noa.cohen@example.test')->and(Operation::count())->toBe($before);
})->with(['actor', 'permission', 'acl', 'batch', 'runtime', 'policy']);

it('resolves truthful source user and account owner mappings and rechecks owner eligibility after verification', function (string $entityType) {
    $f = niFixture();
    $contact = niCreate($f);
    $owner = User::factory()->create(['name' => 'Yael Levy']);
    $owner->assignRole('Employee');
    $source = ['board_id' => 'staff', 'entity_type' => $entityType, 'entity_id' => 'source-owner-7', 'target_role' => 'owner', 'relation_key' => 'primary'];
    $key = (string) Str::uuid();
    $receipt = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], $source, ['request_key' => $key, 'revision' => 1, 'source_fingerprint' => NativeValues::digest('fixture-owner', [$source]), 'target_fingerprint' => null, 'previous_receipt_id' => null]);
    $f['ledger']->finish($f['user'], $f['boundary'], $receipt['id'], $key, ['state' => 'verified', 'target_type' => 'core.user', 'target_id' => (string) $owner->id, 'result_fingerprint' => NativeValues::contextHash('users', $owner->id)]);
    $descriptor = ['mapping_id' => $receipt['mapping_id'], 'board_id' => 'staff', 'entity_id' => 'source-owner-7'];
    $lead = niLead($f, $contact, ['owner_id' => $descriptor]);
    expect(Lead::find($lead['target']['id'])->owner_id)->toBe($owner->id)->and(User::count())->toBe(2);
    $before = Operation::count();
    expect(fn () => niDuring(Operation::class, 'updated', function (Operation $operation) use ($owner) {
        if ($operation->state === 'verified' && $operation->result_target_type === 'core.lead' && Mapping::find($operation->mapping_id)->entity_type === 'field') {
            User::whereKey($owner->id)->update(['active' => false]);
        }
    }, fn () => niApply($f, $lead, ['priority' => 'high'])))->toThrow(Exception::class);
    expect(Lead::find($lead['target']['id'])->priority)->toBe('normal')->and(Operation::count())->toBe($before);
})->with(['user', 'account']);

it('retains a pre-existing field reservation as status only and never adopts it or extends its deadline', function () {
    $f = niFixture();
    $contact = niCreate($f);
    $input = niInput(['email' => 'new.source@example.test'], $contact['fields']);
    $fieldMapping = Mapping::findOrFail($contact['fields']['email']['mapping_id']);
    $baseline = Operation::findOrFail($contact['fields']['email']['receipt_id']);
    $claim = $f['ledger']->claim($f['user'], $f['boundary'], $f['batch']['id'], $fieldMapping->only(['board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key']), ['request_key' => $input['fields']['email']['request_key'], 'revision' => 2, 'source_fingerprint' => NativeValues::fieldHash('contacts', 'email', ['present' => true, 'value' => 'new.source@example.test']), 'target_fingerprint' => NativeValues::fieldHash('contacts', 'email', NativeValues::read(Contact::sole(), 'email')), 'previous_receipt_id' => $baseline->id]);
    $result = $f['writer']->apply($f['user'], $f['boundary'], $f['batch']['id'], $contact['entity_receipt']['mapping_id'], $input);
    expect($result['state'])->toBe('not_applied')->and($result['fields']['email']['state'])->toBe('claimed')->and(Operation::find($claim['id'])->reservation_expires_at->toISOString())->toBe($claim['reservation_expires_at'])->and(Contact::sole()->email)->toBe('noa.cohen@example.test');
    $this->travel(Ledger::RESERVATION_SECONDS + 1)->seconds();
    $again = $f['writer']->apply($f['user'], $f['boundary'], $f['batch']['id'], $contact['entity_receipt']['mapping_id'], $input);
    expect($again['fields']['email']['state'])->toBe('reconciliation_required')->and(Contact::sole()->email)->toBe('noa.cohen@example.test');
});

it('rechecks Core identity ACL after field receipt verification and rolls the write and receipt back together', function () {
    $f = niFixture();
    $contact = niCreate($f);
    $other = User::factory()->create();
    $before = Operation::count();
    expect(fn () => niDuring(Operation::class, 'updated', function (Operation $receipt) use ($f, $contact, $other) {
        if ($receipt->state === 'verified' && Mapping::find($receipt->mapping_id)->entity_type === 'field') {
            $f['user']->revokePermissionTo('identities.manage');
            DB::table('crm_contacts')->where('id', $contact['target']['id'])->update(['created_by' => $other->id]);
        }
    }, fn () => niApply($f, $contact, ['email' => 'new.source@example.test'])))->toThrow(Exception::class);
    expect(Contact::sole()->email)->toBe('noa.cohen@example.test')->and(Operation::count())->toBe($before);
});

it('suppresses old and later queued native reminders under imported lead holds while ordinary leads still deliver', function () {
    $f = niFixture();
    $contact = niCreate($f);
    $imported = niLead($f, $contact);
    $row = Lead::findOrFail($imported['target']['id']);
    $ordinary = app(Leads::class)->create($f['user'], ['contact_id' => $contact['target']['id'], 'owner_id' => $f['user']->id, 'status_id' => 'new']);
    foreach ([$row, $ordinary] as $lead) {
        app(Tasks::class)->create($f['user'], $lead, ['assignee_id' => $f['user']->id, 'title' => 'Call Noa Cohen', 'due_at' => now()->subHour(), 'remind_at' => now()->subDay()]);
    }
    $this->artisan('crm:remind')->assertSuccessful();
    expect(Reminder::count())->toBe(1)->and(Reminder::sole()->task->lead_id)->toBe($ordinary->id);
    $heldTask = $row->tasks()->sole();
    (new DeliverReminder($heldTask->id, $heldTask->version))->handle();
    expect(Reminder::count())->toBe(1)->and($heldTask->fresh()->status)->toBe('open');
});

it('rejects applied or preserved values changed by a late receipt listener instead of attesting stale readback', function (bool $preserved) {
    $f = niFixture();
    $contact = niCreate($f);
    app(Identities::class)->update($f['user'], 'contacts', $contact['target']['id'], ['phone' => '+972-54-555-0199']);
    $before = Operation::count();
    expect(fn () => niDuring(Operation::class, 'updated', function (Operation $receipt) use ($contact, $preserved) {
        if ($receipt->state === 'verified' && Mapping::find($receipt->mapping_id)->entity_type === 'field') {
            DB::table('crm_contacts')->where('id', $contact['target']['id'])->update($preserved ? ['phone' => '+972-55-555-0111'] : ['email' => 'late.change@example.test']);
        }
    }, fn () => niApply($f, $contact, ['email' => 'new.source@example.test', 'phone' => '+972-50-555-0101'])))->toThrow(HttpException::class);
    expect(Contact::sole()->email)->toBe('noa.cohen@example.test')->and(Contact::sole()->phone)->toBe('+972-54-555-0199')->and(Operation::count())->toBe($before);
})->with([true, false]);

it('refuses a released import hold before committing a new historical opportunity', function () {
    $f = niFixture();
    $contact = niCreate($f);
    expect(fn () => niDuring(Operation::class, 'updated', function (Operation $receipt) {
        if ($receipt->state === 'verified' && $receipt->result_target_type === 'core.lead' && Mapping::find($receipt->mapping_id)->entity_type === 'field') {
            DB::table('crm_import_automation_holds')->where('source', 'lead')->where('record_id', (int) $receipt->result_target_id)->update(['state' => 'released', 'release_reason' => 'reviewed_activation', 'released_at' => now(), 'released_by' => $receipt->actor_id]);
        }
    }, fn () => niLead($f, $contact)))->toThrow(HttpException::class);
    expect(Lead::count())->toBe(0)->and(AutomationHold::count())->toBe(0);
});

it('preserves string stage keys and normalizes a native numeric-key input without collapsing named stages', function () {
    $f = niFixture();
    $contact = niCreate($f);
    Status::create(['id' => '3', 'name' => 'Reviewed stage', 'kind' => 'active', 'position' => 99]);
    $result = niLead($f, $contact, ['status_id' => 3]);
    expect($result['state'])->toBe('verified')->and(Lead::sole()->status_id)->toBe('3');
    $updated = niApply($f, $result, ['status_id' => 'new']);
    expect($updated['state'])->toBe('verified')->and(Lead::sole()->status_id)->toBe('new')
        ->and(Operation::find($updated['fields']['status_id']['receipt_id'])->result_fingerprint)->not->toBe(Operation::find($result['fields']['status_id']['receipt_id'])->result_fingerprint);
});

it('does not invent a native object or field baseline when required source content is unavailable', function () {
    $f = niFixture();
    $input = niInput(['name' => null]);
    $input['fields']['name']['available'] = false;
    $result = $f['writer']->create($f['user'], $f['boundary'], $f['batch']['id'], 'contacts', niSource(), $input);
    expect($result['state'])->toBe('unresolved')->and($result['target'])->toBeNull()->and(Contact::count())->toBe(0)->and(Mapping::count())->toBe(0);
});

it('checks mapped company changes and every actual dependent lead before changing a shared contact association', function () {
    $f = niFixture();
    $one = niCreate($f, 'companies', id: 'company-1');
    $two = niCreate($f, 'companies', values: ['name' => 'Galilee Studio'], id: 'company-2');
    $contact = niCreate($f, values: ['name' => 'Noa Cohen', 'email' => 'noa.cohen@example.test', 'company_id' => niRef($one, 'company-1')]);
    $lead = niLead($f, $contact);
    $result = niApply($f, $contact, ['company_id' => niRef($two, 'company-2')]);
    expect($result['state'])->toBe('verified')->and(Contact::sole()->company_id)->toBe($two['target']['id'])->and(app(AutomationHolds::class)->blocked('core', 'lead', $lead['target']['id']))->toBeTrue();
    Lead::whereKey($lead['target']['id'])->update(['archived_at' => now()]);
    expect(fn () => niApply($f, $result, ['company_id' => niRef($one, 'company-1')]))->toThrow(Exception::class)->and(Contact::sole()->company_id)->toBe($two['target']['id']);
});
