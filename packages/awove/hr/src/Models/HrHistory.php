<?php

namespace Awove\Hr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string> $changed_fields
 * @property array<string,mixed> $details
 */
class HrHistory extends Model
{
    protected $table = 'hr_history';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'details' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('HR history is append-only.'));
        static::deleting(fn () => throw new \LogicException('HR history is append-only.'));
    }
}
