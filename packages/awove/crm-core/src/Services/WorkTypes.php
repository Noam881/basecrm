<?php

namespace Awove\Core\Services;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Contracts\WorkContributor;
use Awove\Core\Modules;
use Awove\Core\Work\RoutineType;
use Awove\Core\Work\WorkReminderSource;
use Awove\Core\Work\WorkType;

/** Installed descriptors may be reused; enablement is always evaluated at the point of use. */
class WorkTypes
{
    public function work(string $module, string $kind): ?WorkType
    {
        foreach ($this->workTypes($module) as $type) {
            if ($type->kind === $kind) {
                return $type;
            }
        }

        return null;
    }

    public function routine(string $module, string $kind): ?RoutineType
    {
        foreach ($this->routineTypes($module) as $type) {
            if ($type->kind === $kind) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<WorkType> */
    public function workTypes(string $module): array
    {
        $contributor = $this->contributor($module);
        $types = $contributor?->workTypes() ?? [];
        $this->validateTypes($module, $types);

        return $types;
    }

    /** @return list<RoutineType> */
    public function routineTypes(string $module): array
    {
        $contributor = $this->contributor($module);
        $types = $contributor?->routineTypes() ?? [];
        $this->validateTypes($module, $types);

        return $types;
    }

    private function contributor(string $module): ?WorkContributor
    {
        $registry = app(Modules::class);
        $contributor = $registry->installed()[$module] ?? null;

        return $registry->enabled($module) && $contributor instanceof WorkContributor ? $contributor : null;
    }

    private function validateTypes(string $module, array $types): void
    {
        $keys = [];
        foreach ($types as $type) {
            if ($type->module !== $module || isset($keys[$type->kind])) {
                throw new \RuntimeException('Work definitions must have unique kinds in their declaring module.');
            }
            $keys[$type->kind] = true;
            $declared = app(Modules::class)->installed()[$module]->permissions();
            if (array_diff($type->permissions(), $declared)) {
                throw new \RuntimeException('Work definitions must use explicitly declared module permissions.');
            }
        }
    }

    public function validate(): void
    {
        foreach (array_keys(app(Modules::class)->installed()) as $module) {
            $this->workTypes($module);
            $this->routineTypes($module);
        }
    }

    /** @return list<ReminderSource> */
    public function reminderSources(string $module): array
    {
        return [
            ...array_map(fn ($type) => new WorkReminderSource($module, $type->kind, false), $this->workTypes($module)),
            ...array_map(fn ($type) => new WorkReminderSource($module, $type->kind, true), $this->routineTypes($module)),
        ];
    }
}
