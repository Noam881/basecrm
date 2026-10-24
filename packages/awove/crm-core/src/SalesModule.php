<?php

namespace Awove\Core;

use Awove\Core\Contracts\Module;

/** Existing core Sales domain, retained without moving historical records. */
class SalesModule implements Module
{
    public function id(): string
    {
        return 'sales';
    }

    public function version(): string
    {
        return config('core.version');
    }

    public function contract(): int
    {
        return config('core.contract');
    }

    public function dependencies(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return [];
    }

    public function navigation(): array
    {
        return [];
    }

    public function panels(): array
    {
        return [];
    }
}
