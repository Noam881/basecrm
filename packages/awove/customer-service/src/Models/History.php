<?php

namespace Awove\CustomerService\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property array|null $details
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
class History extends Model
{
    protected $table = 'service_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime', 'details' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Service history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Service history is append-only.'));
    }
}
