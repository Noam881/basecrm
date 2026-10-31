<?php

namespace Awove\Executive;

use Awove\Core\Tables\Column;

class Tables
{
    public function columns(string $id, $user): array
    {
        if (! Access::available($user)) {
            return [];
        }
        $table = match ($id) {
            'executive.tasks' => 'crm_work_items', 'executive.routines' => 'crm_routines', 'executive.goals' => 'executive_goals', default => ''
        };
        if ($table === '') {
            return [];
        }
        $choices = fn ($labels) => array_map(fn ($value, $label) => compact('value', 'label'), array_keys($labels), array_values($labels));
        $columns = [new Column('id', 'Record ID', "$table.id", 'number'), new Column('owner', 'Responsible person', "(SELECT name FROM users WHERE users.id=$table.owner_id)", group: 'Ownership')];
        if ($id === 'executive.tasks') {
            $columns = [...$columns, new Column('title', 'Task', "$table.title", width: 300), new Column('description', 'Description', "$table.description", width: 300), new Column('status', 'Status', "$table.status", 'select', options: $choices(ExecutiveModule::taskStatuses())), new Column('priority', 'Priority', "$table.priority", 'select', options: $choices(['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'])), new Column('due_on', 'Due date', "$table.due_on", 'date'), new Column('completed_on', 'Completion date', "$table.completed_on", 'date')];
        } elseif ($id === 'executive.goals') {
            $columns = [...$columns, new Column('name', 'Goal', "$table.name", width: 300), new Column('status', 'Status', "$table.status", 'select', options: $choices(Goals::statuses())), new Column('target_on', 'Target date', "$table.target_on", 'date'), new Column('success_criterion', 'Success criterion', "$table.success_criterion", width: 320), new Column('target_value', 'Target result', "$table.target_value", 'number'), new Column('current_value', 'Current result', "$table.current_value", 'number'), new Column('unit', 'Unit', "$table.unit")];
        } else {
            $columns = [...$columns, new Column('name', 'Routine', "$table.name", width: 300), new Column('frequency', 'Frequency', "$table.frequency"), new Column('next_due_on', 'Next due date', "$table.next_due_on", 'date')];
        }

        return [...$columns, new Column('created_at', 'Created', "$table.created_at", 'datetime'), new Column('updated_at', 'Updated', "$table.updated_at", 'datetime'), new Column('archived_at', 'Archived', "$table.archived_at", 'datetime')];
    }

    public function defaults(string $id): array
    {
        return match ($id) {
            'executive.tasks' => ['title', 'owner', 'status', 'priority', 'due_on'], 'executive.goals' => ['name', 'owner', 'status', 'target_on', 'target_value', 'current_value', 'unit'], 'executive.routines' => ['name', 'owner', 'frequency', 'next_due_on'], default => []
        };
    }
}
