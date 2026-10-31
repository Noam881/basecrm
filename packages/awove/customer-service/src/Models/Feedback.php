<?php

namespace Awove\CustomerService\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $owner_id
 * @property Carbon $received_on
 * @property Carbon|null $follow_up_on
 * @property Carbon|null $followed_up_on
 * @property Carbon|null $archived_at
 */
class Feedback extends Model
{
    protected $table = 'service_feedback';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_on' => 'date:Y-m-d', 'follow_up_on' => 'date:Y-m-d', 'followed_up_on' => 'date:Y-m-d', 'action_required' => 'boolean', 'archived_at' => 'datetime'];
    }
}
