<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array|null $details
 * @property Carbon $created_at
 */
class Activity extends Model
{
    public const LEGACY_EVENT = 'lead.legacy_history';

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

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        if ($this->event === self::LEGACY_EVENT) {
            // Original HTML and import internals are available only to the guarded history reader.
            unset($attributes['details']);
        }

        return $attributes;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Activity is append-only.'));
        static::deleting(fn () => throw new \LogicException('Activity is append-only.'));
    }
}
