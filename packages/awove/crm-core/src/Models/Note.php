<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    protected $table = 'crm_notes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'author_id');
    }
}
