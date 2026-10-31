<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Services\WorkItems;
use Awove\Core\Tables\Column;

class Tables
{
    public function columns(string $table, $u): array
    {
        $u = $u?->fresh();
        if (! Access::available($u)) {
            return [];
        }$options = fn ($s) => array_map(fn ($key, $label) => ['value' => $key, 'label' => $label], array_keys($s), array_values($s));
        if ($table === 'nonprofit.projects') {
            $t = 'nonprofit_projects';
            $cols = [new Column('id', 'Project ID', "$t.id", 'number'), new Column('name', 'Name', "$t.name", width: 260), new Column('objective', 'Objective', "$t.objective", width: 320), new Column('owner', 'Owner', "(SELECT name FROM users WHERE id=$t.owner_id)"), new Column('starts_on', 'Start date', "$t.starts_on", 'date'), new Column('ends_on', 'End date', "$t.ends_on", 'date'), new Column('status', 'Status', "$t.status", 'select', options: $options(Records::statuses())), new Column('created_at', 'Created', "$t.created_at", 'datetime')];
            if ($u->can('nonprofit.projects.budgets.view')) {
                $cols = [...$cols, new Column('planned_budget', 'Planned budget', "(SELECT amount FROM nonprofit_project_budgets WHERE project_id=$t.id ORDER BY id DESC LIMIT 1)", 'number'), new Column('currency', 'Recorded currency', "(SELECT currency FROM nonprofit_project_budgets WHERE project_id=$t.id ORDER BY id DESC LIMIT 1)")];
            }

            return $cols;
        }
        $kind = substr($table, 10);
        if (! in_array($kind, ['project-tasks', 'project-milestones'], true)) {
            return [];
        }$type = app(WorkItems::class)->type('nonprofit', $kind);
        $t = 'crm_work_items';

        return [new Column('id', 'Work ID', "$t.id", 'number'), new Column('title', 'Title', "$t.title", width: 280), new Column('project', 'Project', "(SELECT p.name FROM nonprofit_project_work pw JOIN nonprofit_projects p ON p.id=pw.project_id WHERE pw.work_item_id=$t.id)", width: 240), new Column('description', 'Description', "$t.description", width: 300), new Column('owner', 'Owner', "(SELECT name FROM users WHERE id=$t.owner_id)"), new Column('priority', 'Priority', "$t.priority", 'select', options: $options(['low' => __('Low'), 'normal' => __('Normal'), 'high' => __('High')])), new Column('status', 'Status', "$t.status", 'select', options: $options($type->statuses)), new Column('due_on', 'Due date', "$t.due_on", 'date'), new Column('completed_on', 'Completion date', "$t.completed_on", 'date'), new Column('created_at', 'Created', "$t.created_at", 'datetime')];
    }

    public function defaults(string $table): array
    {
        return $table === 'nonprofit.projects' ? ['name', 'owner', 'starts_on', 'ends_on', 'status', 'planned_budget'] : ['title', 'project', 'owner', 'priority', 'due_on', 'status'];
    }
}
