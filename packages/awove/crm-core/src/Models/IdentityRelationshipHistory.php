<?php

namespace Awove\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $created_at */
class IdentityRelationshipHistory extends Model
{
    protected $table = 'crm_identity_relationship_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Relationship history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Relationship history is retained.'));
    }
}
