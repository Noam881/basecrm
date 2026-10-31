<?php

namespace Awove\Nonprofit\Projects\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $objective
 * @property int|null $owner_id
 * @property string $status
 * @property int $version
 * @property int $created_by
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property CarbonInterface|null $archived_at
 */
class Project extends Model
{
    protected $table = 'nonprofit_projects';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'archived_at' => 'datetime', 'version' => 'integer'];
    }
}
