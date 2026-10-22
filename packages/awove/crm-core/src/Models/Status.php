<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    protected $table = 'crm_statuses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public $incrementing = false;

    protected $keyType = 'string';
}
