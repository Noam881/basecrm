<?php

namespace Awove\CustomerService;

use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Installation;

class CustomerServiceModule implements DashboardContributor, Module, RecordTableContributor, ReminderContributor
{
    public function id(): string
    {
        return 'customer-service';
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
        return ['customer-service.view.own', 'customer-service.view.all', 'customer-service.create', 'customer-service.edit.own', 'customer-service.edit.all', 'customer-service.assign', 'customer-service.export'];
    }

    public function navigation(): array
    {
        return [new NavigationItem(Installation::manifest()['module_labels']['customer-service'] ?? __('Customer service'), '/service', 'customer-service.view.own', ['customer-service.view.all'])];
    }

    public function panels(): array
    {
        return [];
    }

    public function widgets($user): array
    {
        if (! Access::available($user)) {
            return [];
        }
        $query = Access::records($user)->whereNull('archived_at')->whereNotIn('status', ['resolved', 'closed']);
        $permission = ['permission' => 'customer-service.view.own', 'anyPermissions' => ['customer-service.view.all']];

        return [
            [...$permission, 'id' => 'customer-service.open-requests', 'label' => __('Open service requests'), 'value' => (clone $query)->count(), 'href' => '/service'],
            [...$permission, 'id' => 'customer-service.overdue-actions', 'label' => __('Overdue service actions'), 'value' => (clone $query)->where('due_on', '<', now(Installation::settings()['timezone'])->toDateString())->count(), 'href' => '/service?view=overdue'],
        ];
    }

    public function tableIds(): array
    {
        return ['customer-service.requests'];
    }

    public function tableColumns(string $table, $user): array
    {
        return $table === 'customer-service.requests' ? (new ServiceTables)->columns($user) : [];
    }

    public function tableDefaults(string $table): array
    {
        return $table === 'customer-service.requests' ? (new ServiceTables)->defaults() : [];
    }

    public function reminderSources(): array
    {
        return [new ActionReminders('requests'), new ActionReminders('feedback')];
    }
}
