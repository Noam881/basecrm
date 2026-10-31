<?php

namespace Awove\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class Vacancy extends Model
{
    protected $table = 'hr_vacancies';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['opened_on' => 'immutable_date:Y-m-d', 'version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }
}
