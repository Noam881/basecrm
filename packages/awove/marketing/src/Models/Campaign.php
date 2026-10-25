<?php

namespace Awove\Marketing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $owner_id
 * @property string $name
 * @property string $status
 * @property string $currency
 * @property string $planned_budget
 * @property int $version
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $archived_at
 */
class Campaign extends Model
{
    protected $table = 'marketing_campaigns';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date:Y-m-d', 'ends_on' => 'immutable_date:Y-m-d', 'planned_budget' => 'decimal:2', 'archived_at' => 'immutable_datetime'];
    }
}
