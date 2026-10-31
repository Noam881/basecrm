<?php

namespace Awove\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $kind
 * @property int $relationship_id
 * @property int|null $owner_id
 * @property string $reference
 * @property string $description
 * @property string|null $category
 * @property string $total
 * @property string $currency
 * @property int $version
 * @property string|null $document_url
 * @property string|null $next_action
 * @property int $created_by
 * @property string|null $cancellation_reason
 * @property Carbon $due_on
 * @property Carbon|null $next_action_on
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 */
class LedgerRecord extends Model
{
    protected $table = 'finance_records';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'due_on' => 'date:Y-m-d', 'next_action_on' => 'date:Y-m-d', 'cancelled_at' => 'datetime', 'version' => 'integer'];
    }
}
