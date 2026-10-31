<?php

namespace Awove\Nonprofit\Projects\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property CarbonInterface $held_on
 * @property string $summary
 * @property string|null $decisions
 * @property int|null $follow_up_work_item_id
 * @property int $version
 */
class Meeting extends Model
{
    protected $table = 'nonprofit_project_meetings';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['held_on' => 'date:Y-m-d', 'version' => 'integer'];
    }
}
