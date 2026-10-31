<?php

namespace Awove\Assets;

use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Installation;

class AssetsModule implements DashboardContributor, Module, RecordTableContributor, ReminderContributor
{
    public function id(): string
    {
        return 'assets';
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
        return array_map(fn ($p) => 'assets.'.$p, ['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export', 'documents.view', 'documents.manage', 'costs.view', 'costs.manage']);
    }

    public function navigation(): array
    {
        return [new NavigationItem(Records::moduleLabel(), '/assets', 'assets.view.own', ['assets.view.all'])];
    }

    public function panels(): array
    {
        return [];
    }

    public function tableIds(): array
    {
        return ['assets.register', 'assets.assignments', 'assets.maintenance'];
    }

    public function tableColumns(string $id, $u): array
    {
        return (new Tables)->columns($id, $u);
    }

    public function tableDefaults(string $id): array
    {
        return (new Tables)->defaults($id);
    }

    public function reminderSources(): array
    {
        return [new DueReminders('returns'), new DueReminders('maintenance')];
    }

    public function widgets($u): array
    {
        if (! Access::available($u)) {
            return [];
        }
        $today = now(Installation::settings()['timezone'])->toDateString();
        $q = Access::query($u)->whereNull('archived_at');
        $permission = ['permission' => 'assets.view.own', 'anyPermissions' => ['assets.view.all']];

        return [[...$permission, 'id' => 'assets.available', 'label' => __('Available assets'), 'value' => (clone $q)->where('status', 'available')->count(), 'href' => '/assets?section=register&status=available'], [...$permission, 'id' => 'assets.returns', 'label' => __('Overdue asset returns'), 'value' => Access::assignments($u)->whereNull('returned_on')->where('expected_return_on', '<', $today)->whereIn('asset_id', (clone $q)->select('id'))->count(), 'href' => '/assets?section=assignments&view=overdue']];
    }
}
