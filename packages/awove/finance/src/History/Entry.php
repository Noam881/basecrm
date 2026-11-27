<?php

namespace Awove\Finance\History;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array $payload
 * @property CarbonImmutable $created_at
 */
class Entry extends Model
{
    protected $table = 'finance_history_entries';

    protected $guarded = [];

    protected $hidden = ['payload', 'family_key', 'source_mapping_id', 'operation_id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'source_revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Historical finance entries are append-only.'));
        static::deleting(fn () => throw new \LogicException('Historical finance entries are retained.'));
    }
}
