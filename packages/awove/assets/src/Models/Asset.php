<?php

namespace Awove\Assets\Models;

use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    protected $table = 'assets_register';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'purchased_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Archive assets to retain assignment and maintenance history.'));
    }
}
