<?php

namespace Awove\Core\Work;

final readonly class WorkType extends Definition
{
    public function __construct(string $module, string $kind, string $label, string $permissionPrefix, public array $statuses, public string $defaultStatus, public array $terminalStatuses, public array $completedStatuses, string $routePrefix, ?string $capability = null)
    {
        parent::__construct($module, $kind, $label, $permissionPrefix, $routePrefix, $capability);
        if ($statuses === [] || ! isset($statuses[$defaultStatus]) || array_diff($terminalStatuses, array_keys($statuses)) || array_diff($completedStatuses, $terminalStatuses)) {
            throw new \InvalidArgumentException('Work completion states must be terminal, and all states must be declared.');
        }
        foreach ($statuses as $id => $name) {
            if (! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $id) || ! is_string($name) || trim($name) === '' || mb_strlen($name) > 100) {
                throw new \InvalidArgumentException('Work statuses need stable identifiers and display labels.');
            }
        }
    }
}
