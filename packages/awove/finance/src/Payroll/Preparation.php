<?php

namespace Awove\Finance\Payroll;

use Illuminate\Database\Eloquent\Model;

class Preparation extends Model
{
    protected $table = 'finance_payroll_preparations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['input_schema' => 'array', 'inputs' => 'array', 'period_start_on' => 'immutable_date:Y-m-d', 'period_end_on' => 'immutable_date:Y-m-d', 'sent_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
