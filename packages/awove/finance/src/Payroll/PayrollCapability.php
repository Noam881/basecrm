<?php

namespace Awove\Finance\Payroll;

use Awove\Core\Contracts\NavigationItem;
use Awove\Finance\Access;
use Awove\Finance\Contracts\FinanceCapability;

class PayrollCapability implements FinanceCapability
{
    public function key(): string
    {
        return 'payroll';
    }

    public function permissions(): array
    {
        return array_map(fn ($p) => 'finance.payroll.'.$p, ['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export']);
    }

    public function navigation(): array
    {
        return [new NavigationItem('Payroll preparation', '/finance/payroll', 'finance.payroll.view.own', ['finance.payroll.view.all'])];
    }

    public function tableIds(): array
    {
        return ['finance.payroll'];
    }

    public function tableColumns(string $table, $user): array
    {
        return Access::available($user, 'payroll') ? (new Tables)->columns($user) : [];
    }

    public function tableDefaults(string $table): array
    {
        return ['employee', 'period_start_on', 'period_end_on', 'status', 'owner', 'sent_on'];
    }

    public function widgets($user): array
    {
        if (! Access::available($user, 'payroll')) {
            return [];
        }

        return [['id' => 'finance.payroll.pending', 'label' => 'Payroll preparations pending input', 'value' => app(Preparations::class)->query($user)->where('status', 'pending-input')->count(), 'href' => '/finance/payroll?status=pending-input', 'permission' => 'finance.payroll.view.own', 'anyPermissions' => ['finance.payroll.view.all']]];
    }

    public function reminderSources(): array
    {
        return [];
    }
}
