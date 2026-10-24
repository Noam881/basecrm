<?php

namespace Awove\Core\Work;

abstract readonly class Definition
{
    public function __construct(public string $module, public string $kind, public string $label, public string $permissionPrefix, public string $routePrefix)
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $module) || ! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $kind)
            || ($permissionPrefix !== $module && ! str_starts_with($permissionPrefix, $module.'.'))
            || ! preg_match('/^[a-z][a-z0-9.-]{0,119}$/D', $permissionPrefix)
            || trim($label) === '' || mb_strlen($label) > 120
            || ! preg_match('~^/(?!/)[a-zA-Z0-9/_-]+$~D', $routePrefix)) {
            throw new \InvalidArgumentException('Invalid namespaced work definition.');
        }
    }

    public function permission(string $action): string
    {
        return $this->permissionPrefix.'.'.$action;
    }

    public function permissions(): array
    {
        return array_map(fn ($action) => $this->permission($action), ['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'archive']);
    }

    public function href(int $id): string
    {
        return rtrim($this->routePrefix, '/').'/'.$id;
    }
}
