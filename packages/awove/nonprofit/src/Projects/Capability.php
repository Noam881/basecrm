<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Core\Work\Definition;
use Awove\Core\Work\WorkType;
use Awove\Nonprofit\Contracts\NonprofitCapability;
use Illuminate\Database\Eloquent\Builder;

class Capability implements NonprofitCapability
{
    public function key(): string
    {
        return 'projects';
    }

    public function permissions(): array
    {
        return array_map(fn ($action) => 'nonprofit.projects.'.$action, ['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export', 'budgets.view', 'budgets.manage', 'documents.view', 'documents.manage']);
    }

    public function available($user): bool
    {
        return Access::available($user);
    }

    public function navigation(): array
    {
        return [new NavigationItem('Projects', '/nonprofit/projects', 'nonprofit.projects.view.own', ['nonprofit.projects.view.all'])];
    }

    public function tableIds(): array
    {
        return ['nonprofit.projects', 'nonprofit.project-tasks', 'nonprofit.project-milestones'];
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
        return [];
    }

    public function widgets($user): array
    {
        if (! Access::available($user)) {
            return [];
        }
        $q = Access::query($user)->whereNull('archived_at')->whereNotIn('status', ['completed', 'cancelled']);

        return [['id' => 'nonprofit.projects-open', 'label' => 'Open nonprofit projects', 'value' => $q->count(), 'href' => '/nonprofit/projects', 'permission' => 'nonprofit.projects.view.own', 'anyPermissions' => ['nonprofit.projects.view.all']]];
    }

    public function workTypes(): array
    {
        if (! app(Modules::class)->enabled('nonprofit:projects')) {
            return [];
        }
        $statuses = array_replace(['not-started' => 'Not started', 'in-progress' => 'In progress', 'blocked' => 'Blocked', 'done' => 'Completed', 'cancelled' => 'Cancelled'], array_intersect_key(Installation::manifest()['nonprofit_project_work_statuses'] ?? [], array_flip(['not-started', 'in-progress', 'blocked', 'done', 'cancelled'])));

        return array_map(fn ($kind, $label) => new WorkType(module: 'nonprofit', kind: $kind, label: $label, permissionPrefix: 'nonprofit.projects', statuses: $statuses, defaultStatus: 'not-started', terminalStatuses: ['done', 'cancelled'], completedStatuses: ['done'], routePrefix: '/nonprofit/'.$kind, capability: 'projects'), ['project-tasks', 'project-milestones'], ['Project task', 'Project milestone']);
    }

    public function scopeWork($user, Definition $type, Builder $query, bool $activeOnly = false): void
    {
        if ($type->module !== 'nonprofit' || ! in_array($type->kind, ['project-tasks', 'project-milestones'], true)) {
            return;
        }
        $parents = Access::query($user);
        if ($activeOnly) {
            $parents->whereNull('archived_at')->whereNotIn('status', ['completed', 'cancelled']);
        }
        $query->whereIn('crm_work_items.id', Models\ProjectWork::query()->select('work_item_id')->where('kind', $type->kind)->whereIn('project_id', $parents->select('nonprofit_projects.id')));
    }
}
