<?php

namespace Awove\Nonprofit;

use Awove\Core\Contracts\CapabilityProvider;
use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Contracts\WorkContributor;
use Awove\Core\Contracts\WorkScopeContributor;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Core\Work\Definition;
use Awove\Nonprofit\Contracts\NonprofitCapability;
use Illuminate\Database\Eloquent\Builder;

class NonprofitModule implements CapabilityProvider, DashboardContributor, Module, RecordTableContributor, ReminderContributor, WorkContributor, WorkScopeContributor
{
    public function id(): string
    {
        return 'nonprofit';
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
        return ['programs', 'fundraising', 'projects'];
    }

    /** @return list<NonprofitCapability> */
    private function parts(): array
    {
        return [new Programs\Capability, new Fundraising\Capability, new Projects\Capability];
    }

    /** @return list<NonprofitCapability> */
    private function enabledParts(): array
    {
        return array_values(array_filter($this->parts(), fn ($part) => app(Modules::class)->enabled('nonprofit:'.$part->key())));
    }

    public function permissions(): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($part) => $part->permissions(), $this->parts()))));
    }

    public function navigation(): array
    {
        $permissions = [];
        foreach ($this->enabledParts() as $part) {
            foreach ($part->navigation() as $item) {
                $permissions = [...$permissions, $item->permission, ...$item->anyPermissions];
            }
        }

        return $permissions ? [new NavigationItem(Installation::manifest()['module_labels']['nonprofit'] ?? __('Nonprofit'), '/nonprofit', array_shift($permissions), array_values(array_unique($permissions)))] : [];
    }

    public function sections($user): array
    {
        $user = $user?->fresh();
        $sections = [];
        foreach ($this->enabledParts() as $part) {
            if (! $part->available($user)) {
                continue;
            }
            foreach ($part->navigation() as $item) {
                if ($user->can($item->permission) || array_filter($item->anyPermissions, fn ($p) => $user->can($p))) {
                    $sections[] = ['label' => $item->label, 'href' => $item->href];
                }
            }
        }

        return $sections;
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
                return $part->available($user) ? $part->tableColumns($table, $user) : [];
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
        return array_merge(...array_map(fn ($part) => $part->available($user) ? $part->widgets($user) : [], $this->enabledParts()));
    }

    public function reminderSources(): array
    {
        return array_merge(...array_map(fn ($part) => $part->reminderSources(), $this->enabledParts()));
    }

    public function workTypes(): array
    {
        return (new Projects\Capability)->workTypes();
    }

    public function routineTypes(): array
    {
        return [];
    }

    public function scopeWork($user, Definition $type, Builder $query, bool $activeOnly = false): void
    {
        (new Projects\Capability)->scopeWork($user, $type, $query, $activeOnly);
    }
}
