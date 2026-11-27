<?php

namespace Awove\Finance\History\Files;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Mapping;
use Awove\Core\Imports\Metadata;

/** No built-in provider, Vault role, HTTP/manifest registration or permissive fallback. */
final class Sources
{
    private array $policies = [];

    public function register(string $provider, SourcePolicy $policy): void
    {
        Metadata::token($provider, 32);
        if (isset($this->policies[$provider])) {
            throw new \LogicException('Finance file source ownership is already registered.');
        }
        $this->policies[$provider] = $policy;
    }

    public function authorize($actor, Batch $batch, Mapping $record, Mapping $entry, Source $source): void
    {
        abort_unless($source->data['role'] === 'finance-history-files' && $record->target_role === 'finance-history-record'
            && $entry->target_role === 'finance-history-entry', 403, 'Only verified Financial history can own these files.');
        $policy = $this->policies[$batch->provider] ?? null;
        abort_unless($policy instanceof SourcePolicy, 403, 'Finance file source ownership is not registered.');
        $policy->authorize($actor, $batch, $record, $entry, $source);
    }
}
