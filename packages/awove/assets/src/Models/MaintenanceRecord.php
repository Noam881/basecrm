<?php

namespace Awove\Assets\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceRecord extends Model
{
    protected $table = 'assets_maintenance';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $hidden = ['supplier_relationship_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'due_on' => 'immutable_date:Y-m-d', 'completed_on' => 'immutable_date:Y-m-d', 'next_due_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Archive maintenance work to retain history.'));
    }
}
