<?php

namespace Awove\Finance;

use Awove\Core\Contracts\CapabilityProvider;
use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Installation;
use Awove\Core\Modules;

class FinanceModule implements CapabilityProvider, DashboardContributor, Module, RecordTableContributor, ReminderContributor
{
    public function id(): string
    {
        return 'finance';
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

    public function capabilities(): array
    {
        return ['collections', 'expenses', 'payroll'];
    }

    private function parts(): array
    {
        return [new LedgerCapability('collections'), new LedgerCapability('expenses'), new Payroll\PayrollCapability];
    }

    private function enabledParts(): array
    {
        return array_filter($this->parts(), fn ($part) => app(Modules::class)->enabled('finance:'.$part->key()));
    }

    public function permissions(): array
    {
        return array_merge(...array_map(fn ($part) => $part->permissions(), $this->parts()));
    }

    public function navigation(): array
    {
        $permissions = [];
        foreach ($this->enabledParts() as $part) {
            foreach ($part->navigation() as $item) {
                $permissions = [...$permissions, $item->permission, ...$item->anyPermissions];
            }
        }

        return $permissions ? [new NavigationItem(Installation::manifest()['module_labels']['finance'] ?? __('Finance'), '/finance', array_shift($permissions), $permissions)] : [];
    }

    public function sections($user): array
    {
        $items = [];
        foreach ($this->enabledParts() as $part) {
            if (! Access::available($user, $part->key())) {
                continue;
            }
            foreach ($part->navigation() as $item) {
                $items[] = ['label' => $item->label, 'href' => $item->href];
            }
        }

        return $items;
    }

    public function panels(): array
    {
        return [];
    }

    public function tableIds(): array
    {
        return array_merge(...array_map(fn ($part) => $part->tableIds(), $this->enabledParts()));
    }

    public function tableColumns(string $table, $user): array
    {
        foreach ($this->enabledParts() as $part) {
            if (in_array($table, $part->tableIds(), true)) {
                return $part->tableColumns($table, $user);
            }
        }

        return [];
    }

    public function tableDefaults(string $table): array
    {
        foreach ($this->enabledParts() as $part) {
            if (in_array($table, $part->tableIds(), true)) {
                return $part->tableDefaults($table);
            }
        }

        return [];
    }

    public function widgets($user): array
    {
        return array_merge(...array_map(fn ($part) => $part->widgets($user), $this->enabledParts()));
    }

    public function reminderSources(): array
    {
        return array_merge(...array_map(fn ($part) => $part->reminderSources(), $this->enabledParts()));
    }
}
