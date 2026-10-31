<?php

namespace Awove\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $actor_id
 * @property string $event
 * @property int $version
 * @property array|null $details
 * @property Carbon $created_at
 */
class History extends Model
{
    protected $table = 'marketing_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Marketing history is immutable.'));
        static::deleting(fn () => throw new \LogicException('Marketing history is retained.'));
    }
}
