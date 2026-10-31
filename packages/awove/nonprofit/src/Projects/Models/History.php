<?php

namespace Awove\Nonprofit\Projects\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property int $actor_id
 * @property string $event
 * @property array $changed_fields
 * @property CarbonInterface $created_at
 */
class History extends Model
{
    protected $table = 'nonprofit_project_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Project history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Project history is retained.'));
    }
}
