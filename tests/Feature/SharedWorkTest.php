<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Routine;
use Awove\Core\Models\RoutineCompletion;
use Awove\Core\Models\WorkHistory;
use Awove\Core\Models\WorkItem;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\Routines;
use Awove\Core\Services\WorkAccess;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReminders;
use Awove\Core\Services\WorkTypes;
use Awove\Core\Work\WorkReminderSource;
use Awove\Core\Work\WorkType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\WorkModule;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    app(Modules::class)->register(new WorkModule);
    app(Modules::class)->register(new WorkModule('fixture-other'));
    $this->workManifest = tempnam(sys_get_temp_dir(), 'base-crm-work-');
    $manifest = Installation::manifest();
    $manifest['modules'] = [...$manifest['modules'], 'fixture-work', 'fixture-other'];
    file_put_contents($this->workManifest, json_encode($manifest));
    config(['core.manifest' => $this->workManifest]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    unlink($this->workManifest);
});

function sharedWorkUser(bool $manager = false): User
{
    $user = User::factory()->create(['active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo($manager ? (new WorkModule)->permissions() : ['fixture-work.view.own', 'fixture-work.create', 'fixture-work.edit.own']);

    return $user;
}
function sharedWorkData(array $extra = []): array
{
    return ['title' => 'Review the fictional plan', 'description' => 'A fictional internal action.', 'due_on' => app(WorkAccess::class)->today(), ...$extra];
}
function sharedRoutineData(array $extra = []): array
{
    return ['name' => 'Review fictional work', 'frequency' => 'Weekly', 'next_due_on' => app(WorkAccess::class)->today(), 'checklist' => ['Review progress', 'Record next step'], ...$extra];
}
function sharedWorkStatus(callable $action, int $status): void
{
    try {
        $action();
        test()->fail('Expected an HTTP authorization or concurrency error.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($status);
    }
}
function sharedWorkEnabled(string $path, bool $enabled): void
{
    $manifest = json_decode(file_get_contents($path), true);
    $manifest['modules'] = array_values(array_diff($manifest['modules'], ['fixture-work']));
    if ($enabled) {
        $manifest['modules'][] = 'fixture-work';
    }
    file_put_contents($path, json_encode($manifest));
}

it('validates typed states without treating cancellation as completion or granting module permissions', function () {
    $type = app(WorkTypes::class)->work('fixture-work', 'tasks');
    expect($type->terminalStatuses)->toBe(['done', 'cancelled'])->and($type->completedStatuses)->toBe(['done']);
    expect(fn () => new WorkType('fixture-work', 'bad', 'Bad work', 'fixture-work', ['new' => 'New'], 'new', [], ['new'], '/fixture'))->toThrow(InvalidArgumentException::class);
    $user = User::factory()->create(['active' => true]);
    $user->assignRole('Administrator');
    expect($user->can('fixture-work.create'))->toBeFalse();
    sharedWorkStatus(fn () => app(WorkItems::class)->create($user, 'fixture-work', 'tasks', sharedWorkData()), 403);
});

it('creates versioned owned work with date-only fields and non-secret append-only history', function () {
    $user = sharedWorkUser();
    $work = app(WorkItems::class)->create($user, 'fixture-work', 'tasks', sharedWorkData(), ['goal_id']);
    expect($work->version)->toBe(1)->and($work->owner_id)->toBe($user->id)->and($work->status)->toBe('new')->and($work->toArray()['due_on'])->toBe(app(WorkAccess::class)->today());
    $history = app(WorkItems::class)->history($user, $work);
    expect($history)->toHaveCount(1)->and($history[0]['changed_fields'])->toContain('module:fixture-work:goal_id')->and(json_encode($history))->not->toContain('A fictional internal action.');
    expect(fn () => WorkHistory::sole()->update(['event' => 'forged']))->toThrow(LogicException::class);
    expect(fn () => $work->delete())->toThrow(LogicException::class);
});

it('retains completion history when work is reopened and cancels without inventing a completion', function () {
    $user = sharedWorkUser();
    $service = app(WorkItems::class);
    $work = $service->create($user, 'fixture-work', 'tasks', sharedWorkData());
    $work = $service->update($user, 'fixture-work', 'tasks', $work->id, ['version' => 1, 'status' => 'done', 'completed_on' => '']);
    expect($work->completed_on->format('Y-m-d'))->toBe(app(WorkAccess::class)->today());
    $work = $service->update($user, 'fixture-work', 'tasks', $work->id, ['version' => 2, 'status' => 'in-progress']);
    expect($work->completed_on)->toBeNull();
    $history = $service->history($user, $work);
    expect($history[0]['details']['previous_completed_on'])->toBe(app(WorkAccess::class)->today());
    $work = $service->update($user, 'fixture-work', 'tasks', $work->id, ['version' => 3, 'status' => 'cancelled']);
    expect($work->completed_on)->toBeNull()->and((new WorkReminderSource('fixture-work', 'tasks', false))->current($work->id, $user->id))->toBeNull();
});

it('rejects stale versions and forged identity fields and increments versions for related-only edits', function () {
    $user = sharedWorkUser();
    $service = app(WorkItems::class);
    $work = $service->create($user, 'fixture-work', 'tasks', sharedWorkData());
    $service->update($user, 'fixture-work', 'tasks', $work->id, ['version' => 1], ['goal_id']);
    expect($work->fresh()->version)->toBe(2)->and($service->history($user, $work)[0]['changed_fields'])->toBe(['module:fixture-work:goal_id']);
    sharedWorkStatus(fn () => $service->update($user, 'fixture-work', 'tasks', $work->id, ['version' => 1, 'title' => 'Stale title']), 409);
    expect(fn () => $service->update($user, 'fixture-work', 'tasks', $work->id, ['version' => 2, 'module' => 'fixture-other']))->toThrow(ValidationException::class);
    expect($work->fresh()->title)->toBe('Review the fictional plan');
});

it('scopes all record IDs by module kind and ownership rather than creation or assignment side effects', function () {
    $user = sharedWorkUser();
    $other = sharedWorkUser();
    $work = app(WorkItems::class)->create($other, 'fixture-work', 'tasks', sharedWorkData());
    expect(app(WorkItems::class)->query($user, 'fixture-work', 'tasks')->count())->toBe(0);
    expect(fn () => app(WorkItems::class)->find($user, 'fixture-work', 'tasks', $work->id))->toThrow(ModelNotFoundException::class);
    $user->givePermissionTo((new WorkModule('fixture-other'))->permissions());
    expect(fn () => app(WorkItems::class)->update($user, 'fixture-other', 'tasks', $work->id, ['version' => 1]))->toThrow(ModelNotFoundException::class);
    sharedWorkStatus(fn () => app(WorkItems::class)->create($user, 'fixture-work', 'missing', sharedWorkData()), 404);
    expect($work->fresh()->owner_id)->toBe($other->id);
});

it('requires assign permission and a pre-authorized active owner without modifying their grants', function () {
    $user = sharedWorkUser();
    $owner = sharedWorkUser();
    $unrelated = User::factory()->create(['active' => true]);
    $unrelated->assignRole('Employee');
    $service = app(WorkItems::class);
    sharedWorkStatus(fn () => $service->create($user, 'fixture-work', 'tasks', sharedWorkData(['owner_id' => $owner->id])), 403);
    $user->givePermissionTo('fixture-work.assign');
    expect(fn () => $service->create($user, 'fixture-work', 'tasks', sharedWorkData(['owner_id' => $unrelated->id])))->toThrow(ValidationException::class);
    expect($unrelated->fresh()->can('fixture-work.view.own'))->toBeFalse();
    $work = $service->create($user, 'fixture-work', 'tasks', sharedWorkData(['owner_id' => $owner->id]));
    expect($service->query($user, 'fixture-work', 'tasks')->count())->toBe(0)->and($service->query($owner, 'fixture-work', 'tasks')->count())->toBe(1)->and($work->owner_id)->toBe($owner->id);
    $owner->forceFill(['active' => false])->save();
    expect(fn () => $service->create($user, 'fixture-work', 'tasks', sharedWorkData(['owner_id' => $owner->id])))->toThrow(ValidationException::class);
});

it('preserves unchanged null and inactive owner references for authorized ordinary edits', function () {
    $manager = sharedWorkUser(true);
    $owner = sharedWorkUser();
    $service = app(WorkItems::class);
    $work = $service->create($manager, 'fixture-work', 'tasks', sharedWorkData(['owner_id' => null]));
    $work = $service->update($manager, 'fixture-work', 'tasks', $work->id, ['version' => 1, 'description' => 'Updated description']);
    expect($work->owner_id)->toBeNull();
    $work = $service->update($manager, 'fixture-work', 'tasks', $work->id, ['version' => 2, 'owner_id' => $owner->id]);
    $owner->forceFill(['active' => false])->save();
    $work = $service->update($manager, 'fixture-work', 'tasks', $work->id, ['version' => 3, 'description' => 'Another update']);
    expect($work->owner_id)->toBe($owner->id)->and((new WorkReminderSource('fixture-work', 'tasks', false))->current($work->id, $owner->id))->toBeNull();
});

it('records one immutable routine occurrence with a checklist snapshot and no generated next occurrence', function () {
    $user = sharedWorkUser();
    $service = app(Routines::class);
    $routine = $service->create($user, 'fixture-work', 'routines', sharedRoutineData());
    $completion = $service->complete($user, 'fixture-work', 'routines', $routine->id, ['version' => 1, 'checked_items' => ['Review progress']]);
    expect($completion->routine_version)->toBe(1)->and($completion->checklist_snapshot)->toBe([['label' => 'Review progress', 'completed' => true], ['label' => 'Record next step', 'completed' => false]])->and($routine->fresh()->next_due_on)->toBeNull()->and($routine->fresh()->version)->toBe(2)->and(Routine::count())->toBe(1)->and(WorkItem::count())->toBe(0);
    sharedWorkStatus(fn () => $service->complete($user, 'fixture-work', 'routines', $routine->id, ['version' => 1]), 409);
    sharedWorkStatus(fn () => $service->complete($user, 'fixture-work', 'routines', $routine->id, ['version' => 2]), 422);
    expect(RoutineCompletion::count())->toBe(1)->and($service->completions($user, $routine)[0]['actor']['id'])->toBe($user->id);
    expect(fn () => $completion->update(['completed_on' => '2026-01-01']))->toThrow(LogicException::class);
});

it('retains routine completions after checklist edits and schedules only an explicitly entered next date', function () {
    $user = sharedWorkUser();
    $service = app(Routines::class);
    $routine = $service->create($user, 'fixture-work', 'routines', sharedRoutineData());
    $next = now()->addDays(10)->toDateString();
    $service->complete($user, 'fixture-work', 'routines', $routine->id, ['version' => 1, 'next_due_on' => $next]);
    expect($routine->fresh()->next_due_on->toDateString())->toBe($next);
    $service->update($user, 'fixture-work', 'routines', $routine->id, ['version' => 2, 'checklist' => ['A different checklist']]);
    expect($service->completions($user, $routine)[0]['checklist_snapshot'][0]['label'])->toBe('Review progress')->and(RoutineCompletion::count())->toBe(1);
    expect(fn () => $service->complete($user, 'fixture-work', 'routines', $routine->id, ['version' => 3, 'checked_items' => ['An unknown item']]))->toThrow(ValidationException::class);
    expect($routine->fresh()->version)->toBe(3);
});

it('requires archive permission and retains archived records and completion history read only', function () {
    $user = sharedWorkUser();
    $service = app(Routines::class);
    $routine = $service->create($user, 'fixture-work', 'routines', sharedRoutineData());
    $service->complete($user, 'fixture-work', 'routines', $routine->id, ['version' => 1]);
    sharedWorkStatus(fn () => $service->archive($user, 'fixture-work', 'routines', $routine->id, ['version' => 2]), 403);
    $user->givePermissionTo('fixture-work.archive');
    $service->archive($user, 'fixture-work', 'routines', $routine->id, ['version' => 2]);
    expect($service->find($user, 'fixture-work', 'routines', $routine->id)->archived_at)->not->toBeNull()->and($service->editable($user, $routine->fresh()))->toBeFalse()->and($service->completions($user, $routine))->toHaveCount(1);
    sharedWorkStatus(fn () => $service->update($user, 'fixture-work', 'routines', $routine->id, ['version' => 3, 'name' => 'Forbidden']), 403);
});

it('uses installation-local dates and deduplicates work reminders while invalidating rescheduled completed and archived work', function () {
    $manifest = json_decode(file_get_contents($this->workManifest), true);
    $manifest['timezone'] = 'Asia/Jerusalem';
    file_put_contents($this->workManifest, json_encode($manifest));
    $this->travelTo(CarbonImmutable::parse('2026-10-24T21:30:00Z'));
    $user = sharedWorkUser(true);
    $work = app(WorkItems::class)->create($user, 'fixture-work', 'tasks', sharedWorkData());
    expect($work->due_on->toDateString())->toBe('2026-10-25');
    $job = new DeliverWorkReminder('fixture-work', 'work-tasks', $work->id, 1, $user->id);
    $job->handle();
    $job->handle();
    expect(WorkReminder::count())->toBe(1)->and(app(WorkReminders::class)->forUser($user))->toHaveCount(1);
    app(WorkItems::class)->update($user, 'fixture-work', 'tasks', $work->id, ['version' => 1, 'due_on' => '2026-10-27']);
    $job->handle();
    expect(app(WorkReminders::class)->forUser($user))->toBe([])->and(WorkReminder::count())->toBe(1);
    $routine = app(Routines::class)->create($user, 'fixture-work', 'routines', sharedRoutineData());
    (new DeliverWorkReminder('fixture-work', 'routine-routines', $routine->id, 1, $user->id))->handle();
    expect(app(WorkReminders::class)->forUser($user))->toHaveCount(1);
    app(Routines::class)->complete($user, 'fixture-work', 'routines', $routine->id, ['version' => 1]);
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
});

it('denies disabled modules and stale queued previews without erasing data and rechecks account permission loss', function () {
    $user = sharedWorkUser();
    $work = app(WorkItems::class)->create($user, 'fixture-work', 'tasks', sharedWorkData());
    $job = new DeliverWorkReminder('fixture-work', 'work-tasks', $work->id, 1, $user->id);
    $job->handle();
    sharedWorkEnabled($this->workManifest, false);
    $job->handle();
    expect(app(WorkItems::class)->query($user, 'fixture-work', 'tasks')->count())->toBe(0)->and(app(WorkReminders::class)->forUser($user))->toBe([])->and(WorkItem::count())->toBe(1)->and(WorkReminder::count())->toBe(1);
    sharedWorkStatus(fn () => app(WorkItems::class)->update($user, 'fixture-work', 'tasks', $work->id, ['version' => 1]), 404);
    sharedWorkEnabled($this->workManifest, true);
    expect(app(WorkReminders::class)->forUser($user))->toHaveCount(1);
    $user->revokePermissionTo('fixture-work.view.own');
    $job->handle();
    expect(app(WorkReminders::class)->forUser($user->fresh()))->toBe([]);
    sharedWorkStatus(fn () => app(WorkItems::class)->create($user, 'fixture-work', 'tasks', sharedWorkData()), 403);
});
