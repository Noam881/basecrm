<?php

namespace Awove\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class EmploymentCase extends Model
{
    protected $table = 'hr_cases';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date:Y-m-d', 'final_work_on' => 'immutable_date:Y-m-d', 'completed_on' => 'immutable_date:Y-m-d', 'version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }
}
