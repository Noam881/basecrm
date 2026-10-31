<?php

namespace Awove\Executive;

use Awove\Core\Installation;
use Awove\Core\Models\WorkItem;
use Awove\Core\Services\Csv;
use Awove\Core\Services\Routines;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReferences;
use Awove\Core\Tables\Query;
use Awove\Executive\Models\Goal;
use Awove\Executive\Models\GoalHistory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ExecutiveController
{
    private function filters(Request $request): string
    {
        abort_unless(Access::available($request->user()), 403);
        $request->validate(['section' => ['nullable', Rule::in(['tasks', 'goals', 'routines'])], 'owner_id' => 'nullable|integer', 'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])], 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in(['open', 'overdue', 'blocked', 'archived'])]]);

        return $request->input('section', 'tasks');
    }

    private function query(Request $request, string $section)
    {
        $query = match ($section) {
            'goals' => Access::goals($request->user()),
            'routines' => app(Routines::class)->query($request->user(), 'executive', 'routines'),
            default => app(WorkItems::class)->query($request->user(), 'executive', 'tasks'),
        };
        $request->input('view') === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if ($request->filled('owner_id')) {
            $query->where('owner_id', $request->integer('owner_id'));
        }
        if ($section === 'tasks' && $request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        $date = match ($section) {
            'goals' => 'target_on', 'routines' => 'next_due_on', default => 'due_on'
        };
        if ($request->filled('from')) {
            $query->where($date, '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where($date, '<=', $request->input('to'));
        }
        if ($request->input('view') === 'overdue') {
            $query->where($date, '<', $this->today($request->user()));
        }
        if ($section === 'tasks') {
            if (in_array($request->input('view'), ['open', 'overdue'], true)) {
                $query->where('status', '!=', 'done');
            }
            if ($request->input('view') === 'blocked') {
                $query->where('status', 'blocked');
            }
        } elseif ($section === 'goals' && in_array($request->input('view'), ['open', 'overdue'], true)) {
            $query->whereIn('status', ['not-started', 'in-progress']);
        }

        return $query;
    }

    private function today($user): string
    {
        return now(Installation::settings()['timezone'])->toDateString();
    }

    private function options($user, ?int $currentOwner = null): array
    {
        $owners = app(WorkItems::class)->owners($user, 'executive', 'tasks');
        if ($currentOwner !== null && ! in_array($currentOwner, array_column($owners, 'id'), true)) {
            $owner = $this->person($currentOwner);
            if ($owner) {
                $owners[] = $owner;
            }
        }
        $ownerIds = array_unique([...app(WorkItems::class)->query($user, 'executive', 'tasks')->whereNotNull('owner_id')->distinct()->pluck('owner_id')->all(), ...Access::goals($user)->distinct()->pluck('owner_id')->all(), ...app(Routines::class)->query($user, 'executive', 'routines')->whereNotNull('owner_id')->distinct()->pluck('owner_id')->all()]);
        $filterOwners = config('auth.providers.users.model')::whereIn('id', $ownerIds)->orderBy('name')->get(['id', 'name']);

        return ['taskStatuses' => ExecutiveModule::taskStatuses(), 'goalStatuses' => Goals::statuses(), 'owners' => $owners, 'filterOwners' => $filterOwners, 'goals' => Access::goals($user)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']), 'canCreate' => $user->can('executive.create'), 'canAssign' => $user->can('executive.assign'), 'canArchive' => $user->can('executive.archive'), 'canExport' => $user->can('executive.export'), 'today' => $this->today($user)];
    }

    public function index(Request $request)
    {
        $section = $this->filters($request);
        $tasks = $this->query($request, 'tasks');
        $goals = $this->query($request, 'goals');
        $routines = $this->query($request, 'routines');
        $today = $this->today($request->user());
        $metrics = ['open_tasks' => (clone $tasks)->where('status', '!=', 'done')->count(), 'overdue_tasks' => (clone $tasks)->where('status', '!=', 'done')->where('due_on', '<', $today)->count(), 'blocked_tasks' => (clone $tasks)->where('status', 'blocked')->count(), 'active_goals' => (clone $goals)->whereIn('status', ['not-started', 'in-progress'])->count(), 'upcoming_routines' => (clone $routines)->whereBetween('next_due_on', [$today, CarbonImmutable::parse($today)->addDays(7)->toDateString()])->count()];
        $table = app(Query::class)->run($this->query($request, $section), 'executive.'.$section, $request);
        $table['page']->through(fn ($record) => [...$record->toArray(), 'record_url' => '/executive/'.$section.'/'.$record->id, 'record_link_column' => $section === 'tasks' ? 'title' : 'name']);

        return Inertia::render('Executive/Index', [...$this->options($request->user()), 'table' => $table, 'section' => $section, 'metrics' => $metrics, 'filters' => $request->only(['owner_id', 'priority', 'from', 'to', 'view'])]);
    }

    public function storeGoal(Request $request, Goals $goals)
    {
        $goal = $goals->save($request->user(), $request->all());

        return redirect('/executive/goals/'.$goal->id);
    }

    public function goal(Request $request, int $id)
    {
        $goal = Goal::findOrFail($id);
        Access::view($request->user(), $goal);
        $history = GoalHistory::where('goal_id', $goal->id)->orderByDesc('id')->get()->map(fn ($event) => ['id' => $event->id, 'event' => $event->event, 'changed_fields' => $event->details['fields'] ?? [], 'created_at' => CarbonImmutable::parse($event->created_at)->toISOString(), 'actor' => $this->person($event->actor_id)]);
        $editable = $goal->archived_at === null && ($request->user()->can('executive.edit.all') || ($goal->owner_id === $request->user()->id && $request->user()->can('executive.edit.own')));

        return Inertia::render('Executive/Goal', [...$this->options($request->user(), $goal->owner_id), 'record' => $goal, 'editable' => $editable, 'history' => $history]);
    }

    public function updateGoal(Request $request, int $id, Goals $goals)
    {
        $goals->save($request->user(), $request->all(), Goal::findOrFail($id));

        return back();
    }

    public function archiveGoal(Request $request, int $id, Goals $goals)
    {
        $data = $request->validate(['version' => 'required|integer']);
        $goals->archive($request->user(), Goal::findOrFail($id), $data['version']);

        return redirect('/executive?section=goals');
    }

    private function person(?int $id): ?array
    {
        return $id ? config('auth.providers.users.model')::find($id, ['id', 'name'])?->only(['id', 'name']) : null;
    }

    private function saveTask(Request $request, ?int $id = null): WorkItem
    {
        return DB::transaction(function () use ($request, $id) {
            $user = $request->user();
            $service = app(WorkItems::class);
            $existing = $id ? $service->find($user, 'executive', 'tasks', $id) : null;
            $previousGoal = $existing ? DB::table('executive_task_goals')->where('work_item_id', $existing->id)->value('goal_id') : null;
            $validated = $request->validate(['goal_id' => 'nullable|integer|min:1', 'owner_id' => 'sometimes|nullable|integer|min:1', 'goal_action' => ['nullable', Rule::in(['keep', 'set', 'clear'])]]);
            $goalId = $request->input('goal_action') === 'keep' ? $previousGoal : ($request->input('goal_action') === 'clear' ? null : (array_key_exists('goal_id', $validated) ? $validated['goal_id'] : $previousGoal));
            $oldOwnerId = $existing ? $existing->owner_id : null;
            $ownerId = array_key_exists('owner_id', $validated) ? $validated['owner_id'] : ($existing ? $existing->owner_id : $user->id);
            $ownerId = $ownerId !== null ? (int) $ownerId : null;
            if ($goalId && ($goalId != $previousGoal || $ownerId !== $oldOwnerId)) {
                $goal = Goal::lockForUpdate()->findOrFail($goalId);
                Access::view($user, $goal);
                abort_if($goal->archived_at !== null, 422, __('Choose an active goal.'));
                if ($ownerId === null) {
                    throw ValidationException::withMessages(['owner_id' => __('Assign a responsible person before linking a goal.')]);
                }
                $owner = config('auth.providers.users.model')::findOrFail($ownerId);
                abort_unless(Access::goals($owner)->whereKey($goalId)->exists(), 422, __('Task owner must be able to view the linked goal.'));
            }
            $data = $request->only(['title', 'description', 'owner_id', 'priority', 'due_on', 'status', 'completed_on', 'version']);
            $record = $existing ? $service->update($user, 'executive', 'tasks', $existing->id, $data, $goalId != $previousGoal ? ['goal_id'] : []) : $service->create($user, 'executive', 'tasks', $data, $goalId ? ['goal_id'] : []);
            if ($goalId != $previousGoal) {
                if ($goalId) {
                    DB::table('executive_task_goals')->updateOrInsert(['work_item_id' => $record->id], ['goal_id' => $goalId, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('executive_task_goals')->where('work_item_id', $record->id)->delete();
                }
                DB::table('executive_task_goal_history')->insert(['work_item_id' => $record->id, 'from_goal_id' => $previousGoal, 'to_goal_id' => $goalId, 'actor_id' => $user->id, 'created_at' => now()]);
            }

            return $record;
        });
    }

    public function storeTask(Request $request)
    {
        $record = $this->saveTask($request);

        return redirect('/executive/tasks/'.$record->id);
    }

    public function updateTask(Request $request, int $id)
    {
        $this->saveTask($request, $id);

        return back();
    }

    public function task(Request $request, int $id, WorkItems $service)
    {
        $record = $service->find($request->user(), 'executive', 'tasks', $id);
        $goalId = DB::table('executive_task_goals')->where('work_item_id', $id)->value('goal_id');
        $goal = $goalId ? Access::goals($request->user())->find($goalId, ['id', 'name']) : null;
        $links = DB::table('executive_task_goal_history')->where('work_item_id', $id)->orderByDesc('id')->get()->map(fn ($entry) => ['id' => $entry->id, 'event' => 'Goal link updated', 'changed_fields' => ['goal_id'], 'actor' => $this->person($entry->actor_id), 'created_at' => $entry->created_at]);

        return Inertia::render('Executive/Task', [...$this->options($request->user(), $record->owner_id), 'record' => [...$record->toArray(), 'goal_id' => $goal?->id, 'goal' => $goal, 'goal_restricted' => $goalId !== null && $goal === null], 'editable' => $service->editable($request->user(), $record), 'history' => $service->history($request->user(), $record), 'goalHistory' => $links, 'instructionLinks' => app(WorkReferences::class)->for($request->user(), $record)]);
    }

    public function archiveTask(Request $request, int $id, WorkItems $service)
    {
        $service->archive($request->user(), 'executive', 'tasks', $id, $request->all());

        return redirect('/executive?section=tasks');
    }

    public function storeRoutine(Request $request, Routines $service)
    {
        $record = $service->create($request->user(), 'executive', 'routines', $request->all());

        return redirect('/executive/routines/'.$record->id);
    }

    public function updateRoutine(Request $request, int $id, Routines $service)
    {
        $service->update($request->user(), 'executive', 'routines', $id, $request->all());

        return back();
    }

    public function routine(Request $request, int $id, Routines $service)
    {
        $record = $service->find($request->user(), 'executive', 'routines', $id);

        return Inertia::render('Executive/Routine', [...$this->options($request->user(), $record->owner_id), 'record' => $record, 'editable' => $service->editable($request->user(), $record), 'history' => $service->history($request->user(), $record), 'completions' => $service->completions($request->user(), $record), 'instructionLinks' => app(WorkReferences::class)->for($request->user(), $record)]);
    }

    public function completeRoutine(Request $request, int $id, Routines $service)
    {
        $service->complete($request->user(), 'executive', 'routines', $id, $request->all());

        return back();
    }

    public function archiveRoutine(Request $request, int $id, Routines $service)
    {
        $service->archive($request->user(), 'executive', 'routines', $id, $request->all());

        return redirect('/executive?section=routines');
    }

    public function export(Request $request, Csv $csv)
    {
        $section = $this->filters($request);
        abort_unless($request->user()->can('executive.export'), 403);
        $query = app(Query::class)->filtered($this->query($request, $section), 'executive.'.$section, $request);
        abort_if((clone $query)->count() > 10000, 422, __('Narrow the filters to export at most 10,000 records.'));
        $headers = match ($section) {
            'goals' => ['id', 'name', 'owner_id', 'target_on', 'status', 'success_criterion', 'target_value', 'current_value', 'unit'], 'routines' => ['id', 'name', 'owner_id', 'frequency', 'next_due_on'], default => ['id', 'title', 'description', 'owner_id', 'status', 'priority', 'due_on', 'completed_on']
        };
        $rows = (function () use ($query, $headers) {
            foreach ($query->orderBy('id')->cursor() as $record) {
                $data = $record->toArray();
                yield array_map(fn ($field) => $data[$field] ?? '', $headers);
            }
        })();

        return $csv->download('executive-'.$section.'.csv', $headers, $rows);
    }
}
