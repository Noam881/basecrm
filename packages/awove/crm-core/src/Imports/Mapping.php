<?php

namespace Awove\Core\Imports;

use Illuminate\Database\Eloquent\Model;

/** A source occurrence/role may acquire one target identity; it cannot be silently remapped. */
class Mapping extends Model
{
    protected $table = 'crm_import_mappings';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $mapping): void {
            if (array_diff(array_keys($mapping->getDirty()), ['state', 'target_type', 'target_id', 'updated_at']) !== []
                || ($mapping->getRawOriginal('target_id') !== null && $mapping->isDirty(['target_type', 'target_id']))) {
                throw new \LogicException('Import source identity and accepted target are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Import mappings are retained.'));
    }
}
