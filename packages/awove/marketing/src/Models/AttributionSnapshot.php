<?php

namespace Awove\Marketing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property mixed $payload
 */
class AttributionSnapshot extends Model
{
    protected $table = 'marketing_attribution_snapshots';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'json', 'extracted_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Marketing history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Marketing history is retained.'));
    }
}
