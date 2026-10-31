<?php

namespace Awove\Nonprofit\Projects\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $project_id
 * @property string $amount
 * @property string $currency
 * @property int $actor_id
 * @property CarbonInterface $created_at
 */
class Budget extends Model
{
    protected $table = 'nonprofit_project_budgets';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Budget snapshots are append-only.'));
        static::deleting(fn () => throw new \LogicException('Budget snapshots are retained.'));
    }
}
