<?php

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Awove\Core\Events\LeadCreated;
use Awove\Core\Events\SignInLinkRequested;
use Awove\Core\Imports\AutomationHold;
use Awove\Core\Imports\AutomationHolds;
use Awove\Core\Imports\Ledger;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverReminder;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Reminder;
use Awove\Core\Models\Task;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Tasks;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReminders;
use Awove\Finance\Ledger as FinanceLedger;
use Awove\Finance\LedgerCapability;
use Awove\Marketing\MarketingModule;
use Awove\Marketing\Work;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\UnlockGrant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\WorkModule;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    app(Modules::class)->register(new WorkModule);
    $this->holdManifest = tempnam(sys_get_temp_dir(), 'base-import-holds-');
    $manifest = Installation::manifest();
    $manifest['modules'] = ['marketing', 'finance', 'service-details', 'fixture-work'];
    $manifest['capabilities'] = ['sales' => true, 'finance:collections' => true, 'finance:expenses' => false, 'finance:payroll' => false];
    file_put_contents($this->holdManifest, json_encode($manifest));
    config(['core.manifest' => $this->holdManifest]);
    $this->artisan('crm:install')->assertSuccessful();
});

afterEach(function () {
    unlink($this->holdManifest);
});

function automationHoldUser(): User
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'active' => true]);
    $user->assignRole('Employee');
    $permissions = [...(new MarketingModule)->permissions(), ...(new LedgerCapability('collections'))->permissions(), ...(new WorkModule)->permissions()];
    $permissions = array_filter($permissions, fn ($permission) => ! str_ends_with($permission, '.all') && ! str_ends_with($permission, '.assign'));
    $user->givePermissionTo(['imports.manage', 'identities.classifications.view', 'identities.classifications.manage', ...$permissions]);

    return $user;
}

function automationHoldBatch(User $user): array
{
    $ledger = app(Ledger::class);
    $boundary = $ledger->boundary($user);
    $batch = $ledger->start($user, $boundary, ['batch_key' => (string) Str::uuid(), 'provider' => 'sample', 'source_account' => '001',
        'mapping_revision' => str_repeat('a', 64), 'code_revision' => str_repeat('b', 40), 'plan_revision' => str_repeat('c', 64)]);

    return [$boundary, $batch['id']];
}

function automationHoldLead(User $user): Lead
{
    $contact = Contact::create(['name' => 'Noa Cohen', 'email' => Str::uuid().'@example.test', 'created_by' => $user->id, 'search_key' => 'noacohen']);

    return app(Leads::class)->create($user, ['contact_id' => $contact->id, 'owner_id' => $user->id, 'status_id' => 'new']);
}

function automationHoldTask(User $user, Lead $lead): Task
{
    app(Tasks::class)->create($user, $lead, ['assignee_id' => $user->id, 'title' => 'Call Noa Cohen', 'due_at' => now()->subHour(), 'remind_at' => now()->subDay()]);

    return $lead->tasks()->latest('id')->firstOrFail();
}

function automationHoldContent(User $user)
{
    return app(Work::class)->save($user, 'content', ['title' => 'Yael office planning guide', 'channel' => 'instagram', 'format' => 'Article', 'planned_on' => now(Installation::settings()['timezone'])->toDateString(), 'owner_id' => $user->id, 'status' => 'ready']);
}

it('suppresses held native tasks and parent leads at enqueue while ordinary reminders remain enabled', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    $lead = automationHoldLead($user);
    $heldTask = automationHoldTask($user, $lead);
    $ordinary = automationHoldTask($user, automationHoldLead($user));
    $holds = app(AutomationHolds::class);
    $first = $holds->placeNative($user, $boundary, $batch, 'lead', $lead->id);
    expect($holds->placeNative($user, $boundary, $batch, 'lead', $lead->id))->toBe($first);
    $newTask = automationHoldTask($user, $lead);
    Queue::fake([DeliverReminder::class, DeliverWorkReminder::class]);
    $this->artisan('crm:remind')->assertSuccessful();
    Queue::assertPushed(DeliverReminder::class, 1);
    Queue::assertPushed(DeliverReminder::class, fn ($job) => $job->taskId === $ordinary->id);
    expect($heldTask->fresh()->status)->toBe('open')->and($newTask->fresh()->status)->toBe('open');
});

it('blocks old queued task jobs across versions and time until every import hold is explicitly released', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    [, $otherBatch] = automationHoldBatch($user);
    $lead = automationHoldLead($user);
    $task = automationHoldTask($user, $lead);
    $queued = new DeliverReminder($task->id, $task->version);
    $holds = app(AutomationHolds::class);
    $parent = $holds->placeNative($user, $boundary, $batch, 'lead', $lead->id);
    $child = $holds->placeNative($user, $boundary, $otherBatch, 'task', $task->id);
    $queued->handle();
    $task->update(['version' => 2]);
    $this->travel(10)->days();
    (new DeliverReminder($task->id, 2))->handle();
    expect(Reminder::count())->toBe(0);
    $holds->release($user, $boundary, $parent['id'], 'reviewed_activation');
    (new DeliverReminder($task->id, 2))->handle();
    expect(Reminder::count())->toBe(0);
    $released = $holds->release($user, $boundary, $child['id'], 'reviewed_activation');
    expect($holds->release($user, $boundary, $child['id'], 'reviewed_activation'))->toBe($released);
    (new DeliverReminder($task->id, 2))->handle();
    expect(Reminder::count())->toBe(1)->and($task->fresh()->status)->toBe('open')->and(AutomationHold::count())->toBe(2);
    expect(fn () => AutomationHold::findOrFail($child['id'])->delete())->toThrow(LogicException::class);
    expect(fn () => AutomationHold::findOrFail($child['id'])->update(['state' => 'held']))->toThrow(LogicException::class);
});

it('hides previously delivered held notices without deleting retained reminder history', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    $lead = automationHoldLead($user);
    $task = automationHoldTask($user, $lead);
    (new DeliverReminder($task->id, 1))->handle();
    expect(Reminder::count())->toBe(1);
    $hold = app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'task', $task->id);
    $this->actingAs($user)->get('/reminders')->assertInertia(fn (Assert $page) => $page->has('reminders.data', 0));
    expect(Reminder::count())->toBe(1);
    app(AutomationHolds::class)->release($user, $boundary, $hold['id'], 'reviewed_activation');
    $this->get('/reminders')->assertInertia(fn (Assert $page) => $page->has('reminders.data', 1));
});

it('uses the same guarded registry path for Marketing Finance and shared work reminders', function (string $kind) {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    if ($kind === 'marketing') {
        $record = automationHoldContent($user);
        $module = 'marketing';
        $source = 'content';
    } elseif ($kind === 'finance') {
        $contact = Contact::create(['name' => 'Yael Shalev', 'created_by' => $user->id, 'search_key' => 'yaelshalev']);
        $relationship = app(IdentityRelationships::class)->create($user, ['contact_id' => $contact->id, 'role' => 'customer']);
        $record = app(FinanceLedger::class)->create($user, 'collections', ['relationship_id' => $relationship->id, 'reference' => 'CH-001', 'description' => 'Agreed fictional services', 'total' => '100.00', 'due_on' => now(Installation::settings()['timezone'])->toDateString()]);
        $module = 'finance';
        $source = 'collections';
    } else {
        $record = app(WorkItems::class)->create($user, 'fixture-work', 'tasks', ['title' => 'Review the fictional plan', 'due_on' => now(Installation::settings()['timezone'])->toDateString()]);
        $module = 'fixture-work';
        $source = 'work-tasks';
    }
    $job = new DeliverWorkReminder($module, $source, $record->id, $record->version, $user->id);
    $job->handle();
    $notice = WorkReminder::sole();
    expect(app(WorkReminders::class)->preview($notice))->not->toBeNull();
    $hold = app(AutomationHolds::class)->placeReminder($user, $boundary, $batch, $module, $source, $record->id);
    expect(app(WorkReminders::class)->preview($notice))->toBeNull()->and(app(WorkReminders::class)->forUser($user))->toBe([]);
    Queue::fake([DeliverWorkReminder::class]);
    app(WorkReminders::class)->enqueueDue();
    Queue::assertNothingPushed();
    $record->update(['version' => $record->version + 1]);
    (new DeliverWorkReminder($module, $source, $record->id, $record->version, $user->id))->handle();
    expect(WorkReminder::count())->toBe(1);
    app(AutomationHolds::class)->release($user, $boundary, $hold['id'], 'reviewed_activation');
    (new DeliverWorkReminder($module, $source, $record->id, $record->version, $user->id))->handle();
    expect(WorkReminder::count())->toBe(2);
})->with(['marketing', 'finance', 'shared-work']);

it('requires current parent edit permission and an exact registered source for hold placement and release', function () {
    $owner = automationHoldUser();
    $other = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($other);
    $lead = automationHoldLead($owner);
    $content = automationHoldContent($owner);
    $holds = app(AutomationHolds::class);
    expect(fn () => $holds->placeNative($other, $boundary, $batch, 'lead', $lead->id))->toThrow(AuthorizationException::class);
    $other->givePermissionTo('marketing.content.view.all');
    expect(fn () => $holds->placeReminder($other, $boundary, $batch, 'marketing', 'content', $content->id))->toThrow(HttpException::class);
    expect(fn () => $holds->placeReminder($other, $boundary, $batch, 'unknown', 'content', $content->id))->toThrow(HttpException::class);
    [, $ownerBatch] = automationHoldBatch($owner);
    $hold = $holds->placeReminder($owner, $boundary, $ownerBatch, 'marketing', 'content', $content->id);
    expect(fn () => $holds->release($other, $boundary, $hold['id'], 'reviewed_activation'))->toThrow(HttpException::class);
    $owner->fresh()->revokePermissionTo('marketing.content.edit.own');
    expect(fn () => $holds->release($owner, $boundary, $hold['id'], 'reviewed_activation'))->toThrow(HttpException::class);
    expect(AutomationHold::sole()->state)->toBe('held');
});

it('rechecks operator access for placement and release even with a stale actor instance', function (string $revocation) {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    $lead = automationHoldLead($user);
    $hold = app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'lead', $lead->id);
    $fresh = $user->fresh();
    match ($revocation) {
        'permission' => $fresh->revokePermissionTo('imports.manage'),
        'inactive' => $fresh->forceFill(['active' => false])->save(),
        'unverified' => $fresh->forceFill(['email_verified_at' => null])->save(),
    };
    expect(fn () => app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'lead', $lead->id))->toThrow(HttpException::class);
    expect(fn () => app(AutomationHolds::class)->release($user, $boundary, $hold['id'], 'reviewed_activation'))->toThrow(HttpException::class);
    expect(AutomationHold::sole()->state)->toBe('held');
})->with(['permission', 'inactive', 'unverified']);

it('requires the native task assignment permission as well as lead edit access', function () {
    $user = automationHoldUser();
    $assignee = automationHoldUser();
    $user->givePermissionTo('leads.assign');
    $assignee->givePermissionTo('leads.view.all');
    [$boundary, $batch] = automationHoldBatch($user);
    $lead = automationHoldLead($user);
    app(Tasks::class)->create($user, $lead, ['assignee_id' => $assignee->id, 'title' => 'Call Noa Cohen', 'due_at' => now()->subHour(), 'remind_at' => now()->subDay()]);
    $task = $lead->tasks()->sole();
    $user->fresh()->revokePermissionTo('leads.assign');
    expect(fn () => app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'task', $task->id))->toThrow(HttpException::class);
    expect(AutomationHold::count())->toBe(0)->and($task->fresh()->status)->toBe('open');
});

it('rolls back placement if module edit access is revoked after the initial resource authorization', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    $content = automationHoldContent($user);
    $event = 'eloquent.creating: '.AutomationHold::class;
    Event::listen($event, fn () => $user->fresh()->revokePermissionTo('marketing.content.edit.own'));
    try {
        expect(fn () => app(AutomationHolds::class)->placeReminder($user, $boundary, $batch, 'marketing', 'content', $content->id))->toThrow(HttpException::class);
    } finally {
        Event::forget($event);
    }
    expect(AutomationHold::count())->toBe(0);
});

it('retains holds across batch pause module disablement and a changed runtime boundary', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    $content = automationHoldContent($user);
    $holds = app(AutomationHolds::class);
    $hold = $holds->placeReminder($user, $boundary, $batch, 'marketing', 'content', $content->id);
    app(Ledger::class)->pause($user, $boundary, $batch);
    expect(fn () => $holds->release($user, $boundary, $hold['id'], 'reviewed_activation'))->toThrow(HttpException::class);
    app(Ledger::class)->pause($user, $boundary, $batch, false);
    $manifest = Installation::manifest();
    $manifest['modules'] = array_values(array_diff($manifest['modules'], ['marketing']));
    file_put_contents($this->holdManifest, json_encode($manifest));
    expect(fn () => $holds->release($user, $boundary, $hold['id'], 'reviewed_activation'))->toThrow(HttpException::class);
    config(['app.url' => 'https://changed.example.test']);
    expect($holds->blocked('marketing', 'content', $content->id))->toBeTrue();
    expect(fn () => $holds->release($user, $boundary, $hold['id'], 'reviewed_activation'))->toThrow(HttpException::class);
    expect(AutomationHold::sole()->state)->toBe('held');
});

it('rolls back a hold with its failed resource transaction and safely suppresses lookups before migration', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    expect(fn () => DB::transaction(function () use ($user, $boundary, $batch) {
        $lead = automationHoldLead($user);
        app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'lead', $lead->id);
        throw new RuntimeException('Synthetic writer rollback.');
    }))->toThrow(RuntimeException::class, 'Synthetic writer rollback.');
    expect(AutomationHold::count())->toBe(0)->and(Lead::count())->toBe(0);
    $lead = automationHoldLead($user);
    $task = automationHoldTask($user, $lead);
    Schema::rename('crm_import_automation_holds', 'crm_import_holds_unavailable');
    try {
        expect(app(AutomationHolds::class)->blocked('core', 'lead', $lead->id))->toBeTrue();
        (new DeliverReminder($task->id, 1))->handle();
        expect(Reminder::count())->toBe(0);
        expect(fn () => app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'lead', $lead->id))->toThrow(HttpException::class, 'require migration');
    } finally {
        Schema::rename('crm_import_holds_unavailable', 'crm_import_automation_holds');
    }
    (new DeliverReminder($task->id, 1))->handle();
    expect(Reminder::count())->toBe(1);
});

it('preserves extension integrity events Vault revocation and security sign-in delivery while a lead is held', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    $lead = automationHoldLead($user);
    app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'lead', $lead->id);
    DB::table('service_details_leads')->where('lead_id', $lead->id)->delete();
    event(new LeadCreated($lead->id, $user->id));
    expect(DB::table('service_details_leads')->where('lead_id', $lead->id)->value('created_from_event'))->toBeTrue();
    $member = Member::create(['installation_id' => Installation::manifest()['id'], 'user_id' => $user->id, 'approved_by' => $user->id, 'approved_at' => now(), 'confirmed_at' => now(), 'factor_generation' => 1])->refresh();
    $issued = now()->toImmutable();
    UnlockGrant::create(['id' => (string) Str::uuid(), 'installation_id' => $member->installation_id, 'user_id' => $user->id, 'session_hash' => str_repeat('a', 64), 'factor_generation' => 1, 'credential_fingerprint' => str_repeat('b', 64), 'issued_at' => $issued, 'expires_at' => $issued->addSeconds(300)]);
    $user->revokePermissionTo('marketing.content.edit.own');
    expect($member->fresh()->factor_generation)->toBe(2)->and(UnlockGrant::count())->toBe(0);
    Notification::fake([MagicLoginLink::class]);
    event(new SignInLinkRequested($user->id));
    Notification::assertSentTo($user, MagicLoginLink::class);
    expect(AutomationHold::sole()->state)->toBe('held');
});

it('commits a new lead hold before ordinary after-commit listeners observe the imported lead', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    $observed = [];
    Event::listen(LeadCreated::class, function (LeadCreated $event) use (&$observed): void {
        $observed[] = app(AutomationHolds::class)->blocked('core', 'lead', $event->leadId);
    });
    $lead = DB::transaction(function () use ($user, $boundary, $batch) {
        $lead = automationHoldLead($user);
        app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'lead', $lead->id);

        return $lead;
    });
    expect($observed)->toBe([true])
        ->and(DB::table('service_details_leads')->where('lead_id', $lead->id)->value('created_from_event'))->toBeTrue();
});

it('places an existing task hold before the owning update and retains it through the committed version change', function () {
    $user = automationHoldUser();
    [$boundary, $batch] = automationHoldBatch($user);
    $lead = automationHoldLead($user);
    $task = automationHoldTask($user, $lead);
    DB::transaction(function () use ($user, $boundary, $batch, $task) {
        app(AutomationHolds::class)->placeNative($user, $boundary, $batch, 'task', $task->id);
        app(Tasks::class)->update($user, $task, ['version' => $task->version, 'title' => 'Confirm the next step with Noa']);
    });
    (new DeliverReminder($task->id, $task->fresh()->version))->handle();
    expect($task->fresh()->version)->toBe(2)->and($task->fresh()->status)->toBe('open')
        ->and(AutomationHold::sole()->state)->toBe('held')->and(Reminder::count())->toBe(0);
});
