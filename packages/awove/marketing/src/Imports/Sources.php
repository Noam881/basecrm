<?php

namespace Awove\Marketing\Imports;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Metadata;

final class Sources
{
    private array $policies = [];

    public function register(string $provider, string $role, SourcePolicy $policy): void
    {
        $key = Metadata::token($provider, 32).':'.Metadata::token($role);
        if (isset($this->policies[$key])) {
            throw new \LogicException('Marketing source ownership already registered.');
        }
        $this->policies[$key] = $policy;
    }

    public function policy($actor, Batch $batch, Mapping $parent, string $operation): SourcePolicy
    {
        $policy = $this->policies[$batch->provider.':'.$parent->target_role] ?? null;
        abort_unless($policy instanceof SourcePolicy, 403, 'Marketing source ownership is not registered.');
        $policy->authorize($actor, $batch, $parent, $operation);

        return $policy;
    }
}
