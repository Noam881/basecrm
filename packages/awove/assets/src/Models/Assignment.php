<?php

namespace Awove\Assets\Models;

use Illuminate\Database\Eloquent\Model;

class Assignment extends Model
{
    protected $table = 'assets_assignments';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $hidden = ['employee_reference_id', 'customer_relationship_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'assigned_on' => 'immutable_date:Y-m-d', 'expected_return_on' => 'immutable_date:Y-m-d', 'returned_on' => 'immutable_date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->isDirty(['asset_id', 'target_type', 'employee_reference_id', 'customer_relationship_id', 'location', 'assigned_on', 'created_by']) || ($r->getRawOriginal('returned_on') !== null && $r->isDirty(['returned_on', 'return_condition', 'returned_by', 'expected_return_on', 'notes']))) {
                throw new \LogicException('Assignments and recorded returns are retained; record a new assignment after return.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Assignment history is retained.'));
    }
}
