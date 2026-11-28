<?php

namespace Awove\Core\Imports;

/** Explicit source/content ownership, including an immutable shared import-review access scope. */
interface GeneratedDocumentSourcePolicy extends DocumentSourcePolicy
{
    public function accessScope(): string;

    public function authorizeDocument($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source, #[\SensitiveParameter] array $payload): void;
}
