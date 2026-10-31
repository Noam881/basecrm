<?php

namespace Awove\Nonprofit\Fundraising\Models;

use Illuminate\Database\Eloquent\Model;

class History extends Model
{
    protected $table = 'nonprofit_fundraising_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Fundraising history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Fundraising history is retained.'));
    }
}
