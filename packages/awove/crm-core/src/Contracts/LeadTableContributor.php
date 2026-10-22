<?php

namespace Awove\Core\Contracts;

use Awove\Core\Tables\Column;

interface LeadTableContributor
{
    /** @return list<Column> Columns must use trusted SQL and enforce module permissions before contribution. */
    public function leadTableColumns($user): array;
}
