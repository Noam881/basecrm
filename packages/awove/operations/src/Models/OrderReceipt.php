<?php

namespace Awove\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string|null $quantity
 * @property Carbon $received_on
 * @property Carbon $created_at
 */
class OrderReceipt extends Model
{
    protected $table = 'operations_order_receipts';

    protected $guarded = [];

    protected $hidden = ['submission_key', 'submission_fingerprint'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'received_on' => 'date:Y-m-d', 'created_at' => 'datetime', 'complete' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Order receipts are append-only.'));
        static::deleting(fn () => throw new \LogicException('Order receipts are append-only.'));
    }
}
