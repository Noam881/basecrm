<?php

namespace Awove\Finance\History;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Initial owner/placement are immutable; financial facts live in retained entries.
 *
 * @property CarbonImmutable $created_at
 */
class Record extends Model
{
    protected $table = 'finance_history_records';

    protected $guarded = [];

    protected $hidden = ['operation_id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<Identity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(Identity::class, 'record_id');
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class, 'record_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Historical finance placement and owner are immutable.'));
        static::deleting(fn () => throw new \LogicException('Historical finance references are retained.'));
    }
}
