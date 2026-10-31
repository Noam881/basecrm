<?php

namespace Awove\Nonprofit\Fundraising\Models;

use Illuminate\Database\Eloquent\Model;

class Constituent extends Model
{
    protected $table = 'nonprofit_constituents';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $hidden = ['relationship_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'next_action_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Archive fundraising records to retain history.'));
    }
}
