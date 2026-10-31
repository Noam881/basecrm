<?php

namespace Awove\Nonprofit\Projects\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property int $target_id
 * @property string $target_type
 * @property int $version
 * @property CarbonInterface|null $archived_at
 */
class ResourceLink extends Model
{
    protected $table = 'nonprofit_project_resources';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['target_id'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
