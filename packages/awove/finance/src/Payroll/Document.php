<?php

namespace Awove\Finance\Payroll;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    public $timestamps = false;

    protected $table = 'finance_payroll_documents';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payroll supporting documents is append-only.'));
        static::deleting(fn () => throw new \LogicException('Payroll supporting documents is retained.'));
    }
}
