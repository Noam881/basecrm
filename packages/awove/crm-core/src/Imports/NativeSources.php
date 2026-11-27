<?php

namespace Awove\Core\Imports;

final class NativeSources
{
    /** @var array<string, NativeSourcePolicy> */
    private array $policies = [];

    public function register(string $provider, string $role, NativeSourcePolicy $policy): void
    {
        $key = Metadata::token($provider, 32).':'.Metadata::token($role);
        if (isset($this->policies[$key])) {
            throw new \LogicException('Native source ownership is already registered.');
        }
        $this->policies[$key] = $policy;
    }

    public function authorize($actor, Batch $batch, array $source, string $kind, string $operation, array $fields, array $context = []): void
    {
        $source = Metadata::source($source);
        abort_unless(in_array($kind, ['contacts', 'companies', 'leads', 'users'], true) && in_array($source['entity_type'], $kind === 'users' ? ['user', 'account'] : ['item'], true), 403, 'Unsupported ordinary source.');
        $policy = $this->policies[$batch->provider.':'.$source['target_role']] ?? null;
        abort_unless($policy !== null, 403, 'This source is not authorized for ordinary native records.');
        $policy->authorize($actor, $batch, $source, $kind, $operation, $fields, $context);
    }
}
