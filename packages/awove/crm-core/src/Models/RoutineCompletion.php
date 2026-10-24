<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineCompletion extends Model
{
    protected $table = 'crm_routine_completions';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurrence_due_on' => 'immutable_date:Y-m-d', 'completed_on' => 'immutable_date:Y-m-d', 'checklist_snapshot' => 'array', 'routine_version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'completed_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Routine completions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Routine completions are retained.'));
    }
}
