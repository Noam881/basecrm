<?php

namespace Awove\Finance\History\Files;

use Awove\Core\Imports\Batch;
use Awove\Core\Imports\Mapping;

/** Trusted installed adapter owns exact Financial board/asset membership and source lineage. */
interface SourcePolicy
{
    public function authorize($actor, Batch $batch, Mapping $recordMapping, Mapping $entryMapping, Source $source): void;
}
