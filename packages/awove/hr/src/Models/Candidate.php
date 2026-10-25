<?php

namespace Awove\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class Candidate extends Model
{
    protected $table = 'hr_candidates';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['next_action_on' => 'immutable_date:Y-m-d', 'version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }
}
