<?php

namespace Awove\Core;

use Awove\Core\Contracts\CapabilityProvider;
use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\ReminderContributor;

class Modules
{
    private array $installed = [];

    public function register(Module $module): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $module->id())) {
            throw new \RuntimeException('Invalid stable module identifier.');
        }
        if (isset($this->installed[$module->id()])) {
            throw new \RuntimeException('Duplicate module identifier.');
        }
        $this->installed[$module->id()] = $module;
    }

    public function installed(): array
    {
        return $this->installed;
    }

    /** A module and its required capabilities must all be available. */
    public function enabled(string $id): bool
    {
        return $this->available($id, []);
    }

    private function available(string $key, array $seen): bool
    {
        [$id, $capability] = array_pad(explode(':', $key, 2), 2, null);
        $module = $this->installed[$id] ?? null;
        if (! $module || in_array($id, $seen, true)) {
            return false;
        }
        $manifest = Installation::manifest();
        $selected = $id === 'sales' ? ($manifest['capabilities']['sales'] ?? true) : in_array($id, $manifest['modules'], true);
        if (! $selected) {
            return false;
        }
        if ($capability !== null && (! $module instanceof CapabilityProvider || ! in_array($capability, $module->capabilities(), true) || ! ($manifest['capabilities'][$key] ?? false))) {
            return false;
        }
        foreach ($module->dependencies() as $dependency) {
            if (! $this->available($dependency, [...$seen, $id])) {
                return false;
            }
        }

        return true;
    }

    public function validate(): void
    {
        $manifest = Installation::manifest();
        foreach ($manifest['modules'] as $id) {
            $module = $this->installed[$id] ?? throw new \RuntimeException("Required module $id is not installed.");
            if ($module->contract() !== config('core.contract')) {
                throw new \RuntimeException("Module $id has incompatible core contract.");
            }
            if (! preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/', $module->version())) {
                throw new \RuntimeException("Module $id must declare a semantic version.");
            }
            foreach ($module->dependencies() as $dependency) {
                if (! $this->enabled($dependency)) {
                    throw new \RuntimeException("Missing module dependency $dependency or circular module dependency for $id.");
                }
            }
        }
        foreach ($manifest['capabilities'] as $key => $enabled) {
            if ($key === 'sales') {
                continue;
            }
            [$id, $capability] = array_pad(explode(':', $key, 2), 2, null);
            $module = $this->installed[$id] ?? null;
            if (! $module instanceof CapabilityProvider || $capability === null || ! in_array($capability, $module->capabilities(), true)) {
                throw new \RuntimeException("Unknown module capability $key.");
            }
            if ($enabled && ! in_array($id, $manifest['modules'], true)) {
                throw new \RuntimeException("Capability $key requires enabled module $id.");
            }
        }
    }

    public function panels($user): array
    {
        $panels = [];
        foreach ($this->installed as $id => $module) {
            if ($this->enabled($id)) {
                foreach ($module->panels() as $panel) {
                    if ($user->active && $user->can($panel->permission)) {
                        $panels[] = $panel;
                    }
                }
            }
        }

        return $panels;
    }

    public function navigation($user): array
    {
        $items = [];
        foreach ($this->installed as $id => $module) {
            if ($this->enabled($id)) {
                foreach ($module->navigation() as $item) {
                    if ($user->active && ($user->can($item->permission) || array_filter($item->anyPermissions, fn ($permission) => $user->can($permission)))) {
                        $items[] = $item;
                    }
                }
            }
        }

        return $items;
    }

    /** Enabled, authorized contributions only; disabled modules do not calculate or expose counters. */
    public function dashboardWidgets($user): array
    {
        if (! $user || ! $user->active) {
            return [];
        }
        $widgets = [];
        foreach ($this->installed as $id => $module) {
            if (! $this->enabled($id) || ! $module instanceof DashboardContributor) {
                continue;
            }
            foreach ($module->widgets($user) as $widget) {
                if (! preg_match('/^'.preg_quote($id, '/').'\.[a-z][a-z0-9.-]*$/', $widget['id'])
                    || array_key_exists($widget['id'], $widgets)
                    || trim($widget['label']) === '' || mb_strlen($widget['label']) > 100
                    || ! (is_int($widget['value']) && $widget['value'] >= 0 || is_string($widget['value']) && mb_strlen($widget['value']) <= 100)
                    || ! str_starts_with($widget['href'], '/') || str_starts_with($widget['href'], '//')
                    || strlen($widget['href']) > 2048 || preg_match('/[\x00-\x20\\\\]/', $widget['href'])
                    || ! str_starts_with($widget['permission'], $id.'.')) {
                    throw new \RuntimeException('Invalid or duplicate dashboard contribution for module '.$id.'.');
                }
                foreach ($widget['anyPermissions'] ?? [] as $permission) {
                    if (! str_starts_with($permission, $id.'.')) {
                        throw new \RuntimeException('Dashboard permissions must belong to their declaring module.');
                    }
                }
                // Retain IDs before filtering so duplicate declarations cannot depend on a user's permissions.
                $widgets[$widget['id']] = null;
                if ($user->can($widget['permission']) || array_filter($widget['anyPermissions'] ?? [], fn ($permission) => $user->can($permission))) {
                    $widgets[$widget['id']] = [
                        'id' => $widget['id'],
                        'module' => $id,
                        'label' => $widget['label'],
                        'value' => $widget['value'],
                        'href' => $widget['href'],
                    ];
                }
            }
        }

        return array_values(array_filter($widgets));
    }

    public function reminderSources(): array
    {
        $sources = [];
        foreach ($this->installed as $id => $module) {
            if ($this->enabled($id) && $module instanceof ReminderContributor) {
                foreach ($module->reminderSources() as $source) {
                    if ($source->module() !== $id) {
                        throw new \RuntimeException('Reminder source must belong to its declaring module.');
                    }
                    $key = $id.':'.$source->key();
                    if (isset($sources[$key])) {
                        throw new \RuntimeException('Duplicate reminder source.');
                    }
                    $sources[$key] = $source;
                }
            }
        }

        return array_values($sources);
    }
}
