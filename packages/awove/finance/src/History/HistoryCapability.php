<?php

namespace Awove\Finance\History;

use Awove\Core\Contracts\NavigationItem;
use Awove\Finance\Access as FinanceAccess;
use Awove\Finance\Contracts\FinanceCapability;

class HistoryCapability implements FinanceCapability
{
    public function key(): string
    {
        return 'history';
    }

    public function permissions(): array
    {
        return ['finance.history.view.own', 'finance.history.view.all', 'finance.history.import'];
    }

    public function navigation(): array
    {
        return [new NavigationItem(__('Financial history'), '/finance/history', 'finance.history.view.own', ['finance.history.view.all'])];
    }

    public function tableIds(): array
    {
        return ['finance.history'];
    }

    public function tableColumns(string $table, $user): array
    {
        return FinanceAccess::available($user, 'history') ? (new Tables)->columns() : [];
    }

    public function tableDefaults(string $table): array
    {
        return ['label', 'kind', 'placement', 'owner', 'created_at'];
    }

    public function widgets($user): array
    {
        return [];
    }

    public function reminderSources(): array
    {
        return [];
    }
}
