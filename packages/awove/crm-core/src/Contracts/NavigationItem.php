<?php

namespace Awove\Core\Contracts;

final readonly class NavigationItem
{
    public function __construct(public string $label, public string $href, public string $permission) {}
}
