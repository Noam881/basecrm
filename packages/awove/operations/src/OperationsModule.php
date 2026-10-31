<?php

namespace Awove\Operations;

use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;
use Awove\Core\Contracts\WorkContributor;
use Awove\Core\Installation;
use Awove\Core\Services\WorkItems;
use Awove\Core\Work\RoutineType;
use Awove\Core\Work\WorkType;

class OperationsModule implements DashboardContributor, Module, RecordTableContributor, ReminderContributor, WorkContributor
{
    public const REQUEST_STATUSES = ['new' => 'New', 'in-progress' => 'In progress', 'waiting' => 'Waiting', 'done' => 'Done', 'cancelled' => 'Cancelled'];

    public static function requestStatuses(): array
    {
        return self::labels(self::REQUEST_STATUSES, 'operations_request_statuses');
    }

    public static function orderStatuses(): array
    {
        return self::labels(Orders::STATUSES, 'operations_order_statuses');
    }

    private static function labels(array $defaults, string $key): array
    {
        $labels = array_replace($defaults, array_intersect_key(Installation::manifest()[$key] ?? [], $defaults));
        foreach ($labels as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw new \RuntimeException('Operations status labels must be nonempty strings up to 100 characters.');
            }
        }

        return $labels;
    }

    public function id(): string
    {
        return 'operations';
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
        return array_map(fn ($permission) => 'operations.'.$permission, ['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive', 'export']);
    }

    public function navigation(): array
    {
        return [new NavigationItem(Installation::manifest()['module_labels']['operations'] ?? 'Operations', '/operations', 'operations.view.own', ['operations.view.all'])];
    }

    public function panels(): array
    {
        return [];
    }

    public function workTypes(): array
    {
        return [new WorkType(module: 'operations', kind: 'requests', label: 'Operational requests', permissionPrefix: 'operations', statuses: self::requestStatuses(), defaultStatus: 'new', terminalStatuses: ['done', 'cancelled'], completedStatuses: ['done'], routePrefix: '/operations/requests')];
    }

    public function routineTypes(): array
    {
        return [new RoutineType(module: 'operations', kind: 'routines', label: 'Operational routines', permissionPrefix: 'operations', routePrefix: '/operations/routines')];
    }

    public function tableIds(): array
    {
        return ['operations.requests', 'operations.orders', 'operations.routines', 'operations.directory'];
    }

    public function tableColumns(string $table, $user): array
    {
        return (new Tables)->columns($user, $table);
    }

    public function tableDefaults(string $table): array
    {
        return (new Tables)->defaults($table);
    }

    public function reminderSources(): array
    {
        return [new OrderReminders];
    }

    public function widgets($user): array
    {
        if (! Access::available($user)) {
            return [];
        }
        $today = now(Installation::settings()['timezone'])->toDateString();
        $requests = app(WorkItems::class)->query($user, 'operations', 'requests')->whereNull('archived_at')->whereNotIn('status', ['done', 'cancelled']);
        $orders = Access::orders($user)->whereNull('archived_at')->whereIn('status', ['ordered', 'part-received']);
        $permissions = ['permission' => 'operations.view.own', 'anyPermissions' => ['operations.view.all']];

        return [
            [...$permissions, 'id' => 'operations.open-requests', 'label' => 'Open operational requests', 'value' => (clone $requests)->count(), 'href' => '/operations/requests'],
            [...$permissions, 'id' => 'operations.overdue-requests', 'label' => 'Overdue operational requests', 'value' => (clone $requests)->where('due_on', '<', $today)->count(), 'href' => '/operations/requests?view=overdue'],
            [...$permissions, 'id' => 'operations.outstanding-orders', 'label' => 'Outstanding supplier orders', 'value' => $orders->count(), 'href' => '/operations/orders?view=outstanding'],
        ];
    }
}
