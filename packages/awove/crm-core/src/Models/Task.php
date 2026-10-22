<?php

namespace Awove\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable $remind_at
 */
class Task extends Model
{
    protected $table = 'crm_tasks';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'remind_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'assignee_id');
    }
}
