<?php

namespace Awove\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property array|null $details
 * @property Carbon $created_at
 */
class OrderHistory extends Model
{
    protected $table = 'operations_order_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Order history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Order history is append-only.'));
    }
}
