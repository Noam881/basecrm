<?php

namespace Awove\CustomerService\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $owner_id
 * @property Carbon $received_on
 * @property Carbon|null $due_on
 * @property Carbon|null $resolved_on
 * @property Carbon|null $archived_at
 */
class ServiceRequest extends Model
{
    protected $table = 'service_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d', 'resolved_on' => 'date:Y-m-d', 'archived_at' => 'datetime'];
    }
}
