<?php

namespace Awove\Core\Contracts;

interface CapabilityProvider
{
    /** @return list<string> Independently deployment-enabled sub-capabilities. */
    public function capabilities(): array;
}
