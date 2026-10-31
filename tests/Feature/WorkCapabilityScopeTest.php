<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\WorkItem;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\Routines;
use Awove\Core\Services\WorkAccess;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReferences;
use Awove\Core\Services\WorkReminders;
use Awove\Core\Services\WorkTypes;
use Awove\Core\Work\WorkReminderSource;
use Awove\Core\Work\WorkType;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\ScopedWorkModule;
use Tests\Fixtures\WorkModule;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    app(Modules::class)->register(new ScopedWorkModule);
    $this->scopeManifest = tempnam(sys_get_temp_dir(), 'base-crm-scoped-');
    $manifest = Installation::manifest();
    $manifest['modules'][] = 'fixture-scoped';
    $manifest['capabilities']['fixture-scoped:programs'] = true;
    $manifest['capabilities']['fixture-scoped:projects'] = true;
    file_put_contents($this->scopeManifest, json_encode($manifest));
    config(['core.manifest' => $this->scopeManifest]);
    $this->artisan('crm:install')->assertSuccessful();
    Schema::create('fixture_work_parents', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('work_item_id')->nullable();
        $t->unsignedBigInteger('routine_id')->nullable();
        $t->unsignedBigInteger('viewer_id');
        $t->boolean('active')->default(true);
    });
    $this->scopeUser = User::factory()->create(['name' => 'Yael Cohen', 'active' => true]);
    $this->scopeUser->givePermissionTo((new ScopedWorkModule)->permissions());
});
afterEach(function () {
    unlink($this->scopeManifest);
});
function scopedWorkCreate($user): WorkItem
{
    $work = app(WorkItems::class)->create($user, 'fixture-scoped', 'tasks', ['title' => 'Review the fictional plan', 'due_on' => app(WorkAccess::class)->today()]);
    DB::table('fixture_work_parents')->insert(['work_item_id' => $work->id, 'viewer_id' => $user->id, 'active' => true]);

    return $work;
}
function scopedWorkCap(string $path, bool $enabled): void
{
    $m = json_decode(file_get_contents($path), true);
    $m['capabilities']['fixture-scoped:projects'] = $enabled;
    file_put_contents($path, json_encode($m));
}
function scopedWorkHttp(callable $action, int $code): void
{
    try {
        $action();
        test()->fail('Expected denial.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe($code);
    }
}
it('denies cached descriptors and stale notices when only the declaring capability is disabled', function () {
    $work = scopedWorkCreate($this->scopeUser);
    $type = app(WorkTypes::class)->work('fixture-scoped', 'tasks');
    $source = new WorkReminderSource('fixture-scoped', 'tasks', false);
    $job = new DeliverWorkReminder('fixture-scoped', 'work-tasks', $work->id, 1, $this->scopeUser->id);
    $job->handle();
    expect(app(WorkReminders::class)->forUser($this->scopeUser))->toHaveCount(1);
    scopedWorkCap($this->scopeManifest, false);
    expect(app(Modules::class)->enabled('fixture-scoped'))->toBeTrue()->and(app(Modules::class)->enabled('fixture-scoped:programs'))->toBeTrue();
    expect(app(WorkTypes::class)->work('fixture-scoped', 'tasks'))->toBeNull()->and(app(WorkTypes::class)->routine('fixture-scoped', 'routines'))->toBeNull();
    expect(app(WorkAccess::class)->available($this->scopeUser, $type))->toBeFalse()->and(app(WorkAccess::class)->query($this->scopeUser, $type, WorkItem::class)->count())->toBe(0);
    expect(app(WorkItems::class)->query($this->scopeUser, 'fixture-scoped', 'tasks')->count())->toBe(0)->and($source->current($work->id, $this->scopeUser->id))->toBeNull()->and(iterator_to_array($source->due()))->toBe([]);
    expect(app(WorkReferences::class)->for($this->scopeUser, $work))->toBe([])->and(app(WorkReminders::class)->forUser($this->scopeUser))->toBe([]);
    scopedWorkHttp(fn () => app(WorkItems::class)->create($this->scopeUser, 'fixture-scoped', 'tasks', ['title' => 'Denied']), 404);
    scopedWorkHttp(fn () => app(WorkItems::class)->update($this->scopeUser, 'fixture-scoped', 'tasks', $work->id, ['version' => 1]), 404);
    $job->handle();
    expect(WorkReminder::count())->toBe(1)->and(WorkItem::count())->toBe(1);
    scopedWorkCap($this->scopeManifest, true);
    expect(app(WorkItems::class)->query($this->scopeUser, 'fixture-scoped', 'tasks')->count())->toBe(1)->and(app(WorkReferences::class)->for($this->scopeUser, $work))->toHaveCount(1)->and(app(WorkReminders::class)->forUser($this->scopeUser))->toHaveCount(1);
});
it('applies fresh parent scopes to generic queries references and delivery even with all-work permission', function () {
    $work = scopedWorkCreate($this->scopeUser);
    expect(app(WorkItems::class)->query($this->scopeUser, 'fixture-scoped', 'tasks')->count())->toBe(1);
    DB::table('fixture_work_parents')->where('work_item_id', $work->id)->update(['viewer_id' => $this->scopeUser->id + 100000]);
    expect(app(WorkItems::class)->query($this->scopeUser, 'fixture-scoped', 'tasks')->count())->toBe(0)->and(app(WorkItems::class)->editable($this->scopeUser, $work))->toBeFalse()->and(app(WorkReferences::class)->for($this->scopeUser, $work))->toBe([]);
    (new DeliverWorkReminder('fixture-scoped', 'work-tasks', $work->id, 1, $this->scopeUser->id))->handle();
    expect(WorkReminder::count())->toBe(0);
    expect(fn () => app(WorkItems::class)->find($this->scopeUser, 'fixture-scoped', 'tasks', $work->id))->toThrow(ModelNotFoundException::class);
});
it('retains closed-parent history while suppressing shared edits and obsolete reminders', function () {
    $work = scopedWorkCreate($this->scopeUser);
    $job = new DeliverWorkReminder('fixture-scoped', 'work-tasks', $work->id, 1, $this->scopeUser->id);
    $job->handle();
    DB::table('fixture_work_parents')->where('work_item_id', $work->id)->update(['active' => false]);
    expect(app(WorkItems::class)->find($this->scopeUser, 'fixture-scoped', 'tasks', $work->id)->id)->toBe($work->id)->and(app(WorkItems::class)->history($this->scopeUser, $work))->toHaveCount(1);
    expect(app(WorkItems::class)->query($this->scopeUser, 'fixture-scoped', 'tasks', true)->count())->toBe(0)->and(app(WorkItems::class)->editable($this->scopeUser, $work))->toBeFalse()->and(app(WorkReminders::class)->forUser($this->scopeUser))->toBe([]);
    scopedWorkHttp(fn () => app(WorkItems::class)->update($this->scopeUser, 'fixture-scoped', 'tasks', $work->id, ['version' => 1, 'title' => 'Forbidden']), 403);
    $job->handle();
    expect(WorkItem::sole()->title)->toBe('Review the fictional plan')->and(WorkReminder::count())->toBe(1);
});
it('freshly checks stale actor state and grants across public read and owner boundaries', function () {
    $work = scopedWorkCreate($this->scopeUser);
    $type = app(WorkTypes::class)->work('fixture-scoped', 'tasks');
    $stale = User::findOrFail($this->scopeUser->id);
    expect($stale->can('fixture-scoped.view.all'))->toBeTrue();
    $this->scopeUser->revokePermissionTo(['fixture-scoped.view.all', 'fixture-scoped.view.own']);
    expect(app(WorkAccess::class)->available($stale, $type))->toBeFalse()->and(app(WorkItems::class)->query($stale, 'fixture-scoped', 'tasks')->count())->toBe(0)->and(app(WorkItems::class)->owners($stale, 'fixture-scoped', 'tasks'))->toBe([])->and(app(WorkItems::class)->editable($stale, $work))->toBeFalse();
    $this->scopeUser->givePermissionTo((new ScopedWorkModule)->permissions());
    $stale = User::findOrFail($this->scopeUser->id);
    $this->scopeUser->forceFill(['active' => false])->save();
    expect(app(WorkItems::class)->query($stale, 'fixture-scoped', 'tasks')->count())->toBe(0)->and(app(WorkItems::class)->owners($stale, 'fixture-scoped', 'tasks'))->toBe([])->and(app(WorkAccess::class)->available($stale, $type))->toBeFalse();
});
it('applies the same capability parent and active-parent rules to manual routines', function () {
    $r = app(Routines::class)->create($this->scopeUser, 'fixture-scoped', 'routines', ['name' => 'Fictional review', 'frequency' => 'Monthly', 'next_due_on' => app(WorkAccess::class)->today()]);
    DB::table('fixture_work_parents')->insert(['routine_id' => $r->id, 'viewer_id' => $this->scopeUser->id, 'active' => true]);
    expect(app(Routines::class)->query($this->scopeUser, 'fixture-scoped', 'routines')->count())->toBe(1);
    DB::table('fixture_work_parents')->where('routine_id', $r->id)->update(['active' => false]);
    expect(app(Routines::class)->query($this->scopeUser, 'fixture-scoped', 'routines')->count())->toBe(1)->and(app(Routines::class)->editable($this->scopeUser, $r))->toBeFalse()->and((new WorkReminderSource('fixture-scoped', 'routines', true))->current($r->id, $this->scopeUser->id))->toBeNull();
    scopedWorkHttp(fn () => app(Routines::class)->complete($this->scopeUser, 'fixture-scoped', 'routines', $r->id, ['version' => 1]), 403);
    scopedWorkCap($this->scopeManifest, false);
    expect(app(Routines::class)->query($this->scopeUser, 'fixture-scoped', 'routines')->count())->toBe(0);
});
it('requires valid declared capability keys and preserves legacy work constructors', function () {
    expect(fn () => new WorkType('fixture-scoped', 'bad', 'Bad', 'fixture-scoped', ['new' => 'New'], 'new', [], [], '/fixture/bad', capability: 'bad:key'))->toThrow(InvalidArgumentException::class);
    app(Modules::class)->register(new class extends WorkModule
    {
        public function __construct()
        {
            parent::__construct('fixture-undeclared');
        }

        public function workTypes(): array
        {
            return [new WorkType($this->id(), 'tasks', 'Work', $this->id(), ['new' => 'New'], 'new', [], [], '/fixture/bad', capability: 'missing')];
        }
    });
    $m = json_decode(file_get_contents($this->scopeManifest), true);
    $m['modules'][] = 'fixture-undeclared';
    file_put_contents($this->scopeManifest, json_encode($m));
    expect(fn () => app(WorkTypes::class)->workTypes('fixture-undeclared'))->toThrow(RuntimeException::class);
    expect((new WorkModule)->workTypes()[0]->capability)->toBeNull();
});
