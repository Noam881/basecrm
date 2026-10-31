<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;

/** One stable employee identity for HR, payroll and assets; no private employment fields. */
class EmployeeReference extends Model
{
    protected $table = 'crm_employee_references';

    protected $guarded = [];

    protected $attributes = ['active' => true, 'version' => 1];

    protected $visible = ['id', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Deactivate employee references to retain linked records.'));
    }
}
