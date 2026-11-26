<?php

namespace Awove\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignedContract extends Model
{
    protected $table = 'crm_signed_contracts';

    protected $guarded = [];

    protected $hidden = ['private_path', 'sha256'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['version' => 'integer', 'size_bytes' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Signed contract versions are immutable. Upload a new version.'));
        static::deleting(fn () => throw new \LogicException('Signed contract versions are retained as part of the lead history.'));
    }
}
