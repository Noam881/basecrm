<?php

namespace Awove\Core\Contracts;

use Awove\Core\Work\Definition;
use Illuminate\Database\Eloquent\Builder;

/** Trusted module parent scopes apply to shared reads, edits, references and reminder delivery. */
interface WorkScopeContributor
{
    /** Add constraints to the existing scoped query; activeOnly preserves closed history while suppressing edits/notices. */
    public function scopeWork($user, Definition $type, Builder $query, bool $activeOnly = false): void;
}
