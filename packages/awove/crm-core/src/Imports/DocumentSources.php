<?php

namespace Awove\Core\Imports;

/** No HTTP/configurable registration and no default provider: Vault sources cannot opt into ordinary storage. */
final class DocumentSources
{
    /** @var array<string, DocumentSourcePolicy> */
    private array $policies = [];

    public function register(string $provider, string $role, DocumentSourcePolicy $policy): void
    {
        $key = Metadata::token($provider, 32).':'.Metadata::token($role);
        if (isset($this->policies[$key])) {
            throw new \LogicException('Document source ownership is already registered.');
        }
        $this->policies[$key] = $policy;
    }

    public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void
    {
        $policy = $this->policies[$batch->provider.':'.$source->data['role']] ?? null;
        abort_unless($policy !== null, 403, 'This source is not authorized for ordinary document storage.');
        $policy->authorize($actor, $batch, $identity, $sourceItem, $source);
    }

    public function authorizeFields($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source, array $columns): void
    {
        $this->authorize($actor, $batch, $identity, $sourceItem, $source);
        $policy = $this->policies[$batch->provider.':'.$source->data['role']] ?? null;
        abort_unless($source->data['kind'] === 'generated-reference' && $policy instanceof ReferenceSourcePolicy, 403, 'Original column ownership is not registered.');
        $policy->authorizeFields($actor, $batch, $identity, $sourceItem, $source, $columns);
    }

    public function authorizeGeneratedHistory($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void
    {
        $this->authorize($actor, $batch, $identity, $sourceItem, $source);
        $policy = $this->policies[$batch->provider.':'.$source->data['role']] ?? null;
        abort_unless($source->data['kind'] === 'generated-history' && $policy instanceof GeneratedHistorySourcePolicy, 403, 'Generated history ownership is not registered.');
    }
}
