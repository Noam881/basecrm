<?php

namespace Awove\Marketing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campaign_id
 * @property string $actual_spend
 * @property int $reported_leads
 * @property int $version
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 */
class PerformanceReport extends Model
{
    protected $table = 'marketing_performance_reports';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date:Y-m-d', 'ends_on' => 'immutable_date:Y-m-d', 'actual_spend' => 'decimal:2'];
    }
}
