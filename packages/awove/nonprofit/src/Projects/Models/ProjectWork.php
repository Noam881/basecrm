<?php

namespace Awove\Nonprofit\Projects\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property int $work_item_id
 * @property string $kind
 */
class ProjectWork extends Model
{
    protected $table = 'nonprofit_project_work';

    protected $guarded = [];

    public $timestamps = false;
}
