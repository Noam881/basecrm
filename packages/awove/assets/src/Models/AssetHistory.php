<?php

namespace Awove\Assets\Models;

use Illuminate\Database\Eloquent\Model;

/** @property list<string> $changed_fields */
class AssetHistory extends Model
{
    protected $table = 'assets_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Asset history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Asset history is append-only.'));
    }
}
