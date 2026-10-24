<?php

namespace Awove\Executive\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array{fields?:list<string>} $details */
class GoalHistory extends Model
{
    protected $table = 'executive_goal_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Goal history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Goal history is retained.'));
    }
}
