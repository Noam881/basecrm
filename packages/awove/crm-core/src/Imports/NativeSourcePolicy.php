<?php

namespace Awove\Core\Imports;

/** Server-owned reviewed adapter policy. No request/config registration and no built-in provider. */
interface NativeSourcePolicy
{
    /** Field names only; this policy owns ordinary source scope, role and explicit consolidation decisions. */
    public function authorize($actor, Batch $batch, array $source, string $kind, string $operation, array $fields, array $context): void;
}
