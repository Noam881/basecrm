<?php

namespace Awove\Hr;

use Awove\Core\Tables\Column;

class Tables
{
    public function columns(string $id, $u): array
    {
        $u = $u?->fresh();
        $s = substr($id, 3);
        if (! isset(Access::MODELS[$s]) || ! Access::available($u, $s)) {
            return [];
        }
        $table = (new (Access::MODELS[$s]))->getTable();
        $choices = fn ($options) => array_map(fn ($value, $label) => compact('value', 'label'), array_keys($options), array_values($options));
        $columns = [new Column('id', 'Record ID', "$table.id", 'number'), new Column('owner', 'Responsible staff', "(SELECT name FROM users WHERE users.id=$table.owner_id)")];
        if (in_array($s, ['employees', 'cases'], true) && $u->can('employees.references.view')) {
            $columns[] = new Column('employee', 'Employee', "(SELECT name FROM crm_employee_references WHERE crm_employee_references.id=$table.employee_reference_id)", width: 240);
        }
        if ($s === 'employees') {
            $columns = [...$columns, new Column('role_name', 'Role', "$table.role_name", width: 240), new Column('email', 'Email', "$table.email"), new Column('phone', 'Phone', "$table.phone"), new Column('start_on', 'Start date', "$table.start_on", 'date'), new Column('employment_status', 'Employment status', "$table.employment_status", 'select', options: $choices(Records::employmentStatuses())), new Column('responsibilities', 'Responsibilities', "$table.responsibilities", width: 300)];
        } elseif ($s === 'vacancies') {
            $columns = [...$columns, new Column('role_name', 'Vacancy', "$table.role_name", width: 260), new Column('description', 'Description', "$table.description", width: 300), new Column('opened_on', 'Opened date', "$table.opened_on", 'date'), new Column('status', 'Status', "$table.status", 'select', options: $choices(Records::vacancyStatuses()))];
        } elseif ($s === 'candidates') {
            $columns = [...$columns, new Column('name', 'Candidate', "$table.name", width: 240), new Column('email', 'Email', "$table.email"), new Column('phone', 'Phone', "$table.phone"), new Column('stage', 'Stage', "$table.stage", 'select', options: $choices(Records::stages())), new Column('next_action_on', 'Next action date', "$table.next_action_on", 'date')];
        } else {
            $columns = [...$columns, new Column('name', 'Case', "$table.name", width: 260), new Column('kind', 'Purpose', "$table.kind", 'select', options: $choices(['onboarding' => __('Onboarding'), 'offboarding' => __('Offboarding')])), new Column('due_on', 'Due date', "$table.due_on", 'date'), new Column('completed_on', 'Completion date', "$table.completed_on", 'date'), new Column('final_work_on', 'Final working date', "$table.final_work_on", 'date')];
        }

        return [...$columns, new Column('created_at', 'Created', "$table.created_at", 'datetime'), new Column('archived_at', 'Archived', "$table.archived_at", 'datetime')];
    }

    public function defaults(string $id): array
    {
        return match ($id) {
            'hr.employees' => ['employee', 'role_name', 'owner', 'employment_status', 'start_on'],'hr.vacancies' => ['role_name', 'owner', 'status', 'opened_on'],'hr.candidates' => ['name', 'owner', 'stage', 'next_action_on'],'hr.cases' => ['name', 'employee', 'owner', 'kind', 'due_on', 'completed_on'],default => []
        };
    }
}
