<?php

namespace Awove\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;

class Entry extends Model
{
    protected $table = 'knowledge_entries';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'revised_on' => 'immutable_date:Y-m-d', 'updated_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime'];
    }
}
