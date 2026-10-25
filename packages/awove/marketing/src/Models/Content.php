<?php

namespace Awove\Marketing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $owner_id
 * @property string $title
 * @property string $status
 * @property int $version
 * @property CarbonImmutable $planned_on
 * @property CarbonImmutable|null $published_on
 * @property CarbonImmutable|null $archived_at
 */
class Content extends Model
{
    protected $table = 'marketing_content';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['planned_on' => 'immutable_date:Y-m-d', 'published_on' => 'immutable_date:Y-m-d', 'archived_at' => 'immutable_datetime'];
    }
}
