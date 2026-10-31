<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeReferenceHistory extends Model
{
    protected $table = 'crm_employee_reference_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['version' => 'integer', 'changed_fields' => 'array', 'links' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Employee reference history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Employee reference history is retained.'));
    }
}
