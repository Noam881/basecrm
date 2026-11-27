<?php

namespace Awove\Core\Imports;

/** Server-registered ordinary-column ownership and exact lineage policy; no default implementation. */
interface ReferenceSourcePolicy extends DocumentSourcePolicy
{
    public function authorizeFields($actor, Batch $batch, Mapping $identity, Mapping $sourceItem, DocumentSource $source, array $columnIds): void;
}
