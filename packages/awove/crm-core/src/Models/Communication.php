<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Communication extends Model
{
    protected $table = 'sales_communications';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Communication history is append-only. Record a correction as a new communication.'));
        static::deleting(fn () => throw new \LogicException('Communication history is retained with the opportunity.'));
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'staff_id');
    }
}
