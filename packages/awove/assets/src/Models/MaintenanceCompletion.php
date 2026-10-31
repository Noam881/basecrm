<?php

namespace Awove\Assets\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceCompletion extends Model
{
    protected $table = 'assets_maintenance_completions';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['cost_id'];

    protected function casts(): array
    {
        return ['completed_on' => 'immutable_date:Y-m-d', 'next_due_on' => 'immutable_date:Y-m-d', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Maintenance completions are append-only.'));
        static::deleting(fn () => throw new \LogicException('Maintenance completions are append-only.'));
    }
}
