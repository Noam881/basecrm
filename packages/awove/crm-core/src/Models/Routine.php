<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Routine extends Model
{
    protected $table = 'crm_routines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['next_due_on' => 'immutable_date:Y-m-d', 'checklist' => 'array', 'archived_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'owner_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Archive routines to retain their completion history.'));
    }
}
