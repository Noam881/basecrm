<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkItem extends Model
{
    protected $table = 'crm_work_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_on' => 'immutable_date:Y-m-d', 'completed_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'owner_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Archive work items to retain their history.'));
    }
}
