<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityActivity extends Model
{
    protected $table = 'crm_identity_activity';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'actor_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Identity change history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Identity change history is retained.'));
    }
}
