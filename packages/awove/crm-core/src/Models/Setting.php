<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $table = 'crm_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
