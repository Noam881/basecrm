<?php

namespace Awove\Executive;

use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Contracts\WorkContributor;
use Awove\Core\Installation;
use Awove\Core\Services\WorkItems;
use Awove\Core\Work\RoutineType;
use Awove\Core\Work\WorkType;

class ExecutiveModule implements DashboardContributor, Module, RecordTableContributor, ReminderContributor, WorkContributor
{
    public function id(): string
    {
        return 'executive';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function contract(): int
    {
        return 1;
    }

    public function dependencies(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return ['executive.view.own', 'executive.view.all', 'executive.create', 'executive.edit.own', 'executive.edit.all', 'executive.assign', 'executive.archive', 'executive.export'];
    }

    public function navigation(): array
    {
        return [new NavigationItem(Installation::manifest()['module_labels']['executive'] ?? __('Executive management'), '/executive', 'executive.view.own', ['executive.view.all'])];
    }

    public function panels(): array
    {
        return [];
    }

    public function workTypes(): array
    {
        return [new WorkType(module: 'executive', kind: 'tasks', label: __('Management task'), permissionPrefix: 'executive', statuses: self::taskStatuses(), defaultStatus: 'not-started', terminalStatuses: ['done'], completedStatuses: ['done'], routePrefix: '/executive/tasks')];
    }

    public function routineTypes(): array
    {
        return [new RoutineType(module: 'executive', kind: 'routines', label: __('Management routine'), permissionPrefix: 'executive', routePrefix: '/executive/routines')];
    }

    public static function taskStatuses(): array
    {
        $defaults = ['not-started' => 'Not started', 'in-progress' => 'In progress', 'blocked' => 'Blocked', 'done' => 'Done'];

        return array_replace(array_map(fn ($label) => (string) __($label), $defaults), array_intersect_key(Installation::manifest()['executive_task_statuses'] ?? [], $defaults));
    }

    public function widgets($user): array
    {
        if (! Access::available($user)) {
            return [];
        }
        $query = app(WorkItems::class)->query($user, 'executive', 'tasks')->whereNull('archived_at')->where('status', '!=', 'done');
        $today = now(Installation::settings()['timezone'])->toDateString();
        $permission = ['permission' => 'executive.view.own', 'anyPermissions' => ['executive.view.all']];

        return [
            [...$permission, 'id' => 'executive.open-tasks', 'label' => __('Open management tasks'), 'value' => (clone $query)->count(), 'href' => '/executive?section=tasks'],
            [...$permission, 'id' => 'executive.overdue-tasks', 'label' => __('Overdue management tasks'), 'value' => (clone $query)->where('due_on', '<', $today)->count(), 'href' => '/executive?section=tasks&view=overdue'],
        ];
    }

    public function tableIds(): array
    {
        return ['executive.tasks', 'executive.goals', 'executive.routines'];
    }

    public function tableColumns(string $table, $user): array
    {
        return (new Tables)->columns($table, $user);
    }

    public function tableDefaults(string $table): array
    {
        return (new Tables)->defaults($table);
    }

    public function reminderSources(): array
    {
        return [new GoalReminders];
    }
}
