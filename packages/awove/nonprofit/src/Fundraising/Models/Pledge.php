<?php

namespace Awove\Nonprofit\Fundraising\Models;

use Illuminate\Database\Eloquent\Model;

class Pledge extends Model
{
    protected $table = 'nonprofit_pledges';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $hidden = ['amount', 'currency'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'amount' => 'decimal:2', 'expected_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Archive fundraising records to retain history.'));
    }
}
