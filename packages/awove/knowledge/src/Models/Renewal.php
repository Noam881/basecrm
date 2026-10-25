<?php

namespace Awove\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;

class Renewal extends Model
{
    protected $table = 'knowledge_renewals';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'expires_on' => 'immutable_date:Y-m-d', 'remind_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime'];
    }
}
