<?php

namespace Awove\Marketing\Imports;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Mapping;

/** Installed server code owns source scope, internal references, columns and conversion. */
interface SourcePolicy
{
    public function authorize($actor, Batch $batch, Mapping $parent, string $operation): void;

    /** Exact Marketing-owned source column IDs, including originals without a canonical equivalent. */
    public function columns(Batch $batch, Mapping $parent): array;

    /** Existing LeadFields keys only, each ['available'=>bool,'value'=>typed scalar|null]. Unknown consent is unavailable, never false. */
    public function canonical(Batch $batch, Mapping $parent, array $original): array;
}
