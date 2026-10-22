<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    protected $table = 'crm_activity';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    public $timestamps = false;

    public function actor(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Activity is append-only.'));
        static::deleting(fn () => throw new \LogicException('Activity is append-only.'));
    }
}
