<?php

namespace Awove\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class EmploymentProfile extends Model
{
    protected $table = 'hr_employment_profiles';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['start_on' => 'immutable_date:Y-m-d', 'version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }
}
