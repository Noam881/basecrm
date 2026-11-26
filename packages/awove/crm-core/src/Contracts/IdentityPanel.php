<?php

namespace Awove\Core\Contracts;

final readonly class IdentityPanel
{
    public function __construct(public string $id, public string $label, public string $component, public string $endpoint, public string $permission, public array $anyPermissions = []) {}
}
