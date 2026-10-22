<?php

namespace Awove\Core;

use Awove\Core\Contracts\Module;

class Modules
{
    private array $installed = [];

    public function register(Module $module): void
    {
        if (isset($this->installed[$module->id()])) {
            throw new \RuntimeException('Duplicate module identifier.');
        } $this->installed[$module->id()] = $module;
    }

    public function installed(): array
    {
        return $this->installed;
    }

    public function enabled(string $id): bool
    {
        return isset($this->installed[$id]) && in_array($id, Installation::manifest()['modules']);
    }

    public function validate(): void
    {
        foreach (Installation::manifest()['modules'] as $id) {
            $m = $this->installed[$id] ?? throw new \RuntimeException("Required module $id is not installed.");
            if ($m->contract() !== config('core.contract')) {
                throw new \RuntimeException("Module $id has incompatible core contract.");
            }
            foreach ($m->dependencies() as $dependency) {
                if (! $this->enabled($dependency)) {
                    throw new \RuntimeException("Missing module dependency $dependency.");
                }
            }
        }
    }

    public function panels($user): array
    {
        $panels = [];
        foreach ($this->installed as $id => $m) {
            if ($this->enabled($id)) {
                foreach ($m->panels() as $p) {
                    if ($user->can($p->permission)) {
                        $panels[] = $p;
                    }
                }
            }
        }

        return $panels;
    }

    public function navigation($user): array
    {
        $items = [];
        foreach ($this->installed as $id => $m) {
            if ($this->enabled($id)) {
                foreach ($m->navigation() as $n) {
                    if ($user->can($n->permission)) {
                        $items[] = $n;
                    }
                }
            }
        }

        return $items;
    }
}
