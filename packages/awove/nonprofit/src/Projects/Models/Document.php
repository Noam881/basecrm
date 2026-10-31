<?php

namespace Awove\Nonprofit\Projects\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $url
 * @property int $version
 * @property CarbonInterface|null $archived_at
 */
class Document extends Model
{
    protected $table = 'nonprofit_project_documents';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['url'];

    protected $attributes = ['version' => 1];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
