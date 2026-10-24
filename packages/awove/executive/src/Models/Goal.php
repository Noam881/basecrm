<?php

namespace Awove\Executive\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $target_on */
class Goal extends Model
{
    protected $table = 'executive_goals';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_on' => 'immutable_date:Y-m-d', 'target_value' => 'decimal:4', 'current_value' => 'decimal:4', 'version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }
}
