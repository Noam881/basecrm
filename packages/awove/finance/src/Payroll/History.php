<?php

namespace Awove\Finance\Payroll;

use Illuminate\Database\Eloquent\Model;

class History extends Model
{
    public $timestamps = false;

    protected $table = 'finance_payroll_history';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payroll history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Payroll history is retained.'));
    }
}
