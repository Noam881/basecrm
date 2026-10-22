<?php

namespace Awove\Core\Contracts;

interface Module
{
    public function id(): string;

    public function version(): string;

    public function contract(): int;

    /** @return list<string> */
    public function dependencies(): array;

    /** @return list<string> */
    public function permissions(): array;

    /** @return list<NavigationItem> */
    public function navigation(): array;

    /** @return list<LeadPanel> */
    public function panels(): array;
}
