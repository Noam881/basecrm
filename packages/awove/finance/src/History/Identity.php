<?php

namespace Awove\Finance\History;

use Illuminate\Database\Eloquent\Model;

class Identity extends Model
{
    protected $table = 'finance_history_identities';

    protected $guarded = [];

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Historical finance associations are immutable.'));
        static::deleting(fn () => throw new \LogicException('Historical finance associations are retained.'));
    }
}
