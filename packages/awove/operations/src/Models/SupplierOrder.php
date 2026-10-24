<?php

namespace Awove\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $owner_id
 * @property string|null $quantity
 * @property string $agreed_total
 * @property Carbon $ordered_on
 * @property Carbon|null $expected_on
 * @property Carbon|null $received_on
 * @property Carbon|null $archived_at
 */
class SupplierOrder extends Model
{
    protected $table = 'operations_orders';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'agreed_total' => 'decimal:2', 'ordered_on' => 'date:Y-m-d', 'expected_on' => 'date:Y-m-d', 'received_on' => 'date:Y-m-d', 'archived_at' => 'datetime'];
    }
}
