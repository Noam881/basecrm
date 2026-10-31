<?php

namespace Awove\Hr;

use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Contracts\WorkContributor;
use Awove\Core\Installation;
use Awove\Core\Work\WorkType;

class HrModule implements DashboardContributor, Module, RecordTableContributor, ReminderContributor, WorkContributor
{
    public function id(): string
    {
        return 'hr';
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
        $permissions = ['hr.documents.view', 'hr.documents.manage', 'hr.final-pay.view', 'hr.final-pay.edit'];
        foreach (['employment', 'recruitment', 'cases'] as $kind) {
            foreach (['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export'] as $action) {
                $permissions[] = 'hr.'.$kind.'.'.$action;
            }
        }

        return $permissions;
    }

    public function navigation(): array
    {
        return [new NavigationItem(Installation::manifest()['module_labels']['hr'] ?? __('Human resources'), '/hr', 'hr.employment.view.own', ['hr.employment.view.all', 'hr.recruitment.view.own', 'hr.recruitment.view.all', 'hr.cases.view.own', 'hr.cases.view.all'])];
    }

    public function panels(): array
    {
        return [];
    }

    public function workTypes(): array
    {
        return [new WorkType(module: 'hr', kind: 'checklist', label: __('HR checklist item'), permissionPrefix: 'hr.cases', statuses: Records::checklistStatuses(), defaultStatus: 'not-started', terminalStatuses: ['done', 'skipped'], completedStatuses: ['done'], routePrefix: '/hr/checklist')];
    }

    public function routineTypes(): array
    {
        return [];
    }

    public function tableIds(): array
    {
        return ['hr.employees', 'hr.vacancies', 'hr.candidates', 'hr.cases'];
    }

    public function tableColumns(string $table, $u): array
    {
        return (new Tables)->columns($table, $u);
    }

    public function tableDefaults(string $table): array
    {
        return (new Tables)->defaults($table);
    }

    public function reminderSources(): array
    {
        return [new DueReminders('candidates'), new DueReminders('cases')];
    }

    public function widgets($u): array
    {
        $widgets = [];
        foreach (['employees' => ['Active employees', 'employment'], 'vacancies' => ['Open vacancies', 'recruitment'], 'cases' => ['Pending onboarding/offboarding', 'cases']] as $s => [$label,$p]) {
            if (! Access::available($u, $s)) {
                continue;
            }
            $q = Access::query($u, $s)->whereNull('archived_at');
            if ($s === 'employees') {
                $q->where('employment_status', 'active')->whereExists(fn ($q) => $q->selectRaw('1')->from('crm_employee_references')->whereColumn('crm_employee_references.id', 'hr_employment_profiles.employee_reference_id')->where('active', true));
            } elseif ($s === 'vacancies') {
                $q->where('status', 'open');
            } else {
                $q->whereNull('completed_on');
            }
            $widgets[] = ['id' => 'hr.'.$s, 'label' => $label, 'value' => $q->count(), 'href' => '/hr?section='.$s.'&view=open', 'permission' => 'hr.'.$p.'.view.own', 'anyPermissions' => ['hr.'.$p.'.view.all']];
        }

        return $widgets;
    }
}
