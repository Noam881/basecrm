<?php

namespace Awove\Core\Imports;

use Illuminate\Database\Eloquent\Model;

class AutomationHold extends Model
{
    protected $table = 'crm_import_automation_holds';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['record_id' => 'integer', 'created_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $hold): void {
            if ($hold->getRawOriginal('state') !== 'held'
                || array_diff(array_keys($hold->getDirty()), ['state', 'released_by', 'released_at', 'release_reason']) !== []
                || $hold->state !== 'released') {
                throw new \LogicException('Import hold identity and release history are retained.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Import automation holds are retained.'));
    }
}
