<?php

namespace Awove\Knowledge\Models;

use Illuminate\Database\Eloquent\Model;

class SystemReference extends Model
{
    protected $table = 'knowledge_systems';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $hidden = ['login_url', 'password_manager_reference'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }
}
