<?php

namespace Awove\Core\Contracts;

use Awove\Core\Tables\Column;

/** Trusted, installed module tables reuse the shared query, layout and interface facilities. */
interface RecordTableContributor
{
    /** @return list<string> Stable identifiers prefixed by the owning module ID and a dot. */
    public function tableIds(): array;

    /** @return list<Column> Use trusted SQL and return no columns when the user lacks record access. */
    public function tableColumns(string $table, $user): array;

    /** @return list<string> Shared queries remove defaults that are unavailable to the current user. */
    public function tableDefaults(string $table): array;
}
