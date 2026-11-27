<?php

namespace Awove\Core\Imports;

/** Server-registered source ownership policy. It must authorize ordinary scope before any source fetch. */
interface DocumentSourcePolicy
{
    public function authorize($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source): void;
}
