<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Routine;
use Awove\Core\Models\RoutineCompletion;
use Awove\Core\Models\WorkItem;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReminders;
use Awove\Executive\Goals;
use Awove\Executive\Models\Goal;
use Awove\Executive\Models\GoalHistory;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->executiveManifest = tempnam(sys_get_temp_dir(), 'crm-executive-');
    $this->executiveData = Installation::manifest();
    $this->executiveData['modules'] = ['executive'];
    file_put_contents($this->executiveManifest, json_encode($this->executiveData));
    config(['core.manifest' => $this->executiveManifest]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    if (is_file($this->executiveManifest)) {
        unlink($this->executiveManifest);
    }
});

function executiveStaff(string $name = 'Noa Cohen', bool $all = false): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');
    $permissions = ['executive.view.own', 'executive.create', 'executive.edit.own', 'executive.archive', 'executive.export'];
    if ($all) {
        $permissions = [...$permissions, 'executive.view.all', 'executive.edit.all', 'executive.assign'];
    }
    $user->givePermissionTo($permissions);

    return $user;
}
function executiveGoalData(User $owner, array $changes = []): array
{
    return [...['name' => 'Improve project delivery', 'owner_id' => $owner->id, 'target_on' => now(Installation::settings()['timezone'])->addDays(7)->toDateString(), 'success_criterion' => 'Complete the agreed project checklist.', 'unit' => 'completed projects', 'target_value' => '100.1250', 'current_value' => '10.2500', 'status' => 'in-progress'], ...$changes];
}
function executiveTaskData(User $owner, array $changes = []): array
{
    return [...['title' => 'Review project progress', 'description' => 'Check the outstanding project actions.', 'owner_id' => $owner->id, 'priority' => 'normal', 'due_on' => now(Installation::settings()['timezone'])->toDateString(), 'status' => 'not-started'], ...$changes];
}

it('creates and updates exact manually entered goal results with their explicit common unit', function () {
    $owner = executiveStaff();
    $data = executiveGoalData($owner);
    $this->actingAs($owner)->post('/executive/goals', $data)->assertRedirect();
    $goal = Goal::sole();
    expect($goal->target_value)->toBe('100.1250')->and($goal->current_value)->toBe('10.2500')->and($goal->unit)->toBe('completed projects');
    $this->patch('/executive/goals/'.$goal->id, [...$data, 'current_value' => '20.3750', 'version' => 1])->assertRedirect();
    expect($goal->fresh()->current_value)->toBe('20.3750')->and($goal->fresh()->status)->toBe('in-progress')->and(GoalHistory::count())->toBe(2);
    $this->get('/executive/goals/'.$goal->id)->assertInertia(fn (Assert $page) => $page->component('Executive/Goal')->where('record.target_value', '100.1250')->where('record.current_value', '20.3750')->where('record.target_on', $data['target_on'])->has('history', 2));
    $this->patch('/executive/goals/'.$goal->id, [...$data, 'version' => 1])->assertConflict();
    $this->patch('/executive/goals/'.$goal->id, [...$data, 'target_value' => '1.00001', 'version' => 2])->assertSessionHasErrors('target_value');
    expect(GoalHistory::count())->toBe(2);
});

it('requires goal ownership and permission without automatically granting optional module access', function () {
    $owner = executiveStaff();
    $other = executiveStaff('Uri Erez');
    $this->actingAs($owner)->post('/executive/goals', executiveGoalData($owner))->assertRedirect();
    $goal = Goal::sole();
    $this->actingAs($other)->get('/executive/goals/'.$goal->id)->assertForbidden();
    $this->patch('/executive/goals/'.$goal->id, [...executiveGoalData($owner), 'version' => 1])->assertForbidden();
    $this->post('/executive/goals', executiveGoalData($owner))->assertForbidden();
    $administrator = User::factory()->create(['active' => true]);
    $administrator->assignRole('Administrator');
    $this->actingAs($administrator)->get('/executive')->assertForbidden();
    expect($administrator->can('executive.view.all'))->toBeFalse();
});

it('uses the shared task entity and keeps dated completion history across reopening', function () {
    $owner = executiveStaff();
    $data = executiveTaskData($owner);
    $this->actingAs($owner)->post('/executive/tasks', $data)->assertRedirect();
    $task = WorkItem::sole();
    expect($task->module)->toBe('executive')->and($task->kind)->toBe('tasks');
    $this->patch('/executive/tasks/'.$task->id, [...$data, 'status' => 'blocked', 'version' => 1])->assertRedirect();
    $completed = now(Installation::settings()['timezone'])->toDateString();
    $this->patch('/executive/tasks/'.$task->id, [...$data, 'status' => 'done', 'completed_on' => $completed, 'version' => 2])->assertRedirect();
    expect($task->fresh()->status)->toBe('done')->and($task->fresh()->toArray()['completed_on'])->toBe($completed);
    $this->patch('/executive/tasks/'.$task->id, [...$data, 'status' => 'in-progress', 'version' => 3])->assertRedirect();
    expect($task->fresh()->completed_on)->toBeNull()->and(count(app(WorkItems::class)->history($owner, $task->fresh())))->toBe(4);
});

it('binds only accessible goals and preserves a hidden goal relationship on unrelated task edits', function () {
    $owner = executiveStaff();
    $other = executiveStaff('Tamar Ben Ami');
    $this->actingAs($other)->post('/executive/goals', executiveGoalData($other, ['name' => 'Restricted management goal']))->assertRedirect();
    $goal = Goal::sole();
    $this->actingAs($owner)->post('/executive/tasks', [...executiveTaskData($owner), 'goal_id' => $goal->id])->assertForbidden();
    expect(WorkItem::count())->toBe(0);
    $owner->givePermissionTo('executive.view.all');
    $this->post('/executive/tasks', [...executiveTaskData($owner), 'goal_id' => $goal->id])->assertRedirect();
    $task = WorkItem::sole();
    $owner->revokePermissionTo('executive.view.all');
    $this->get('/executive/tasks/'.$task->id)->assertInertia(fn (Assert $page) => $page->where('record.goal_id', null)->where('record.goal', null)->where('record.goal_restricted', true)->where('goals', []));
    $this->patch('/executive/tasks/'.$task->id, [...executiveTaskData($owner, ['title' => 'Preserve the existing goal']), 'goal_action' => 'keep', 'version' => 1])->assertRedirect();
    expect(DB::table('executive_task_goals')->where('work_item_id', $task->id)->value('goal_id'))->toBe($goal->id);
    $this->patch('/executive/tasks/'.$task->id, [...executiveTaskData($owner), 'goal_action' => 'clear', 'version' => 2])->assertRedirect();
    expect(DB::table('executive_task_goals')->count())->toBe(0)->and(DB::table('executive_task_goal_history')->count())->toBe(2);
});

it('retains routine occurrences and never infers the next occurrence from frequency', function () {
    $owner = executiveStaff();
    $today = now(Installation::settings()['timezone'])->toDateString();
    $next = now(Installation::settings()['timezone'])->addDays(7)->toDateString();
    $this->actingAs($owner)->post('/executive/routines', ['name' => 'Weekly management review', 'owner_id' => $owner->id, 'frequency' => 'Weekly', 'next_due_on' => $today])->assertRedirect();
    $routine = Routine::sole();
    $this->post('/executive/routines/'.$routine->id.'/complete', ['version' => 1, 'completed_on' => $today, 'next_due_on' => $next])->assertRedirect();
    expect($routine->fresh()->toArray()['next_due_on'])->toBe($next)->and(RoutineCompletion::count())->toBe(1);
    $this->post('/executive/routines/'.$routine->id.'/complete', ['version' => 1, 'completed_on' => $today])->assertConflict();
    $this->post('/executive/routines/'.$routine->id.'/complete', ['version' => 2, 'completed_on' => $today])->assertRedirect();
    expect($routine->fresh()->next_due_on)->toBeNull()->and(RoutineCompletion::count())->toBe(2);
    $this->post('/executive/routines/'.$routine->id.'/complete', ['version' => 3, 'completed_on' => $today])->assertUnprocessable();
    expect(Routine::count())->toBe(1)->and(WorkItem::count())->toBe(0);
    $this->get('/executive/routines/'.$routine->id)->assertInertia(fn (Assert $page) => $page->has('completions', 2)->where('record.next_due_on', null));
    expect(fn () => RoutineCompletion::first()->delete())->toThrow(LogicException::class);
});

it('reports accessible owner priority and due-period totals and reuses advanced table search', function () {
    $owner = executiveStaff();
    $other = executiveStaff('Yael Cohen');
    $this->actingAs($owner)->post('/executive/tasks', executiveTaskData($owner, ['priority' => 'high', 'status' => 'blocked']))->assertRedirect();
    $this->actingAs($other)->post('/executive/tasks', executiveTaskData($other, ['title' => 'Other staff work']))->assertRedirect();
    $today = now(Installation::settings()['timezone'])->toDateString();
    $this->actingAs($owner)->get('/executive?priority=high&from='.$today.'&to='.$today)->assertInertia(fn (Assert $page) => $page->where('metrics.open_tasks', 1)->where('metrics.blocked_tasks', 1)->where('table.page.total', 1));
    $this->get('/executive?owner_id='.$other->id)->assertInertia(fn (Assert $page) => $page->where('metrics.open_tasks', 0)->where('table.page.total', 0));
    $this->get('/executive?q=Other')->assertInertia(fn (Assert $page) => $page->where('table.page.total', 0));
    $this->get('/executive?section=goals')->assertInertia(fn (Assert $page) => $page->where('table.id', 'executive.goals'));
});

it('deduplicates owner reminders and prevents stale previews after completion rescheduling archive or disablement', function () {
    $owner = executiveStaff();
    $today = now(Installation::settings()['timezone'])->toDateString();
    $this->actingAs($owner)->post('/executive/goals', executiveGoalData($owner, ['target_on' => $today]))->assertRedirect();
    $goal = Goal::sole();
    $this->post('/executive/tasks', executiveTaskData($owner))->assertRedirect();
    $task = WorkItem::sole();
    $this->post('/executive/routines', ['name' => 'Management update', 'owner_id' => $owner->id, 'frequency' => 'Weekly', 'next_due_on' => $today])->assertRedirect();
    $routine = Routine::sole();
    $this->artisan('crm:remind')->assertSuccessful();
    $this->artisan('crm:remind')->assertSuccessful();
    expect(WorkReminder::where('module', 'executive')->count())->toBe(3)->and(app(WorkReminders::class)->forUser($owner))->toHaveCount(3);
    $this->patch('/executive/goals/'.$goal->id, [...executiveGoalData($owner), 'status' => 'achieved', 'version' => 1])->assertRedirect();
    $this->patch('/executive/tasks/'.$task->id, [...executiveTaskData($owner), 'status' => 'done', 'version' => 1])->assertRedirect();
    $this->post('/executive/routines/'.$routine->id.'/archive', ['version' => 1])->assertRedirect();
    expect(app(WorkReminders::class)->forUser($owner))->toBe([]);
    $this->executiveData['modules'] = [];
    file_put_contents($this->executiveManifest, json_encode($this->executiveData));
    $this->get('/executive')->assertNotFound();
    $this->get('/executive/export')->assertNotFound();
    $this->post('/executive/tasks', executiveTaskData($owner))->assertNotFound();
    expect(Goal::count())->toBe(1)->and(WorkItem::count())->toBe(1)->and(Routine::count())->toBe(1)->and(app(WorkReminders::class)->forUser($owner))->toBe([]);
});

it('exports only accessible filtered records with formula-safe cells and exact goal values', function () {
    $owner = executiveStaff();
    $other = executiveStaff('Uri Shalev');
    $this->actingAs($owner)->post('/executive/goals', executiveGoalData($owner, ['name' => '=HYPERLINK("https://example.test")']))->assertRedirect();
    $this->actingAs($other)->post('/executive/goals', executiveGoalData($other, ['name' => 'Restricted goal']))->assertRedirect();
    $csv = $this->actingAs($owner)->get('/executive/export?section=goals')->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK", '100.1250', '10.2500')->not->toContain('Restricted goal');
    $owner->revokePermissionTo('executive.export');
    $this->get('/executive/export?section=goals')->assertForbidden();
});

it('bounds exports after authorization and active table filters are applied', function () {
    $owner = executiveStaff();
    $other = executiveStaff('Uri Erez');
    $this->actingAs($owner)->post('/executive/goals', executiveGoalData($owner, ['name' => 'Only selected goal']))->assertRedirect();
    $row = [...executiveGoalData($other, ['name' => 'Synthetic bulk goal']), 'created_by' => $other->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
    foreach (array_chunk(array_fill(0, 10000, $row), 500) as $chunk) {
        DB::table('executive_goals')->insert($chunk);
    }
    $this->get('/executive/export?section=goals')->assertOk();
    $owner->givePermissionTo('executive.view.all');
    $this->get('/executive/export?section=goals')->assertUnprocessable();
    $filtered = $this->get('/executive/export?section=goals&q=Only%20selected')->assertOk()->streamedContent();
    expect($filtered)->toContain('Only selected goal')->not->toContain('Synthetic bulk goal');
});

it('refreshes write actors while preserving unchanged historical goal owners', function () {
    $manager = executiveStaff('Noa Erez', true);
    $owner = executiveStaff('Uri Cohen');
    $this->actingAs($manager)->post('/executive/goals', executiveGoalData($owner))->assertRedirect();
    $goal = Goal::sole();
    DB::table('users')->where('id', $owner->id)->update(['active' => false]);
    $this->patch('/executive/goals/'.$goal->id, [...executiveGoalData($owner), 'current_value' => '22.2500', 'version' => 1])->assertRedirect();
    expect($goal->fresh()->owner_id)->toBe($owner->id)->and($goal->fresh()->current_value)->toBe('22.2500');
    DB::table('users')->where('id', $manager->id)->update(['active' => false]);
    expect(fn () => app(Goals::class)->save($manager, [...executiveGoalData($owner), 'version' => 2], $goal->fresh()))->toThrow(HttpException::class);
    expect($goal->fresh()->version)->toBe(2);
});
