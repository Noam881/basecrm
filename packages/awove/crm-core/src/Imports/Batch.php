<?php

namespace Awove\Core\Imports;

use Illuminate\Database\Eloquent\Model;

/** Import metadata only. Context and original operator are retained, never rewritten. */
class Batch extends Model
{
    protected $table = 'crm_import_batches';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::updating(function (self $batch): void {
            if (array_diff(array_keys($batch->getDirty()), ['state', 'updated_at']) !== []) {
                throw new \LogicException('Import batch context is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Import batches are retained.'));
    }
}
