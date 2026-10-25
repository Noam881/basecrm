<?php

namespace Awove\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;

class RenewalEvent extends Model
{
    protected $table = 'knowledge_renewal_events';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['previous_expiry_on' => 'immutable_date:Y-m-d', 'renewed_on' => 'immutable_date:Y-m-d', 'next_expiry_on' => 'immutable_date:Y-m-d', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Renewal events are append-only.'));
        static::deleting(fn () => throw new \LogicException('Renewal events are append-only.'));
    }
}
