<?php

namespace Awove\Assets\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceCost extends Model
{
    protected $table = 'assets_maintenance_costs';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Recorded maintenance costs are immutable.'));
        static::deleting(fn () => throw new \LogicException('Recorded maintenance costs are retained.'));
    }
}
