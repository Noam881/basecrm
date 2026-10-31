<?php

namespace Awove\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $record_id
 * @property int $actor_id
 * @property string $event
 * @property int $version
 * @property array|null $details
 * @property Carbon $created_at
 */
class LedgerHistory extends Model
{
    protected $table = 'finance_record_history';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Finance history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Finance history cannot be deleted.'));
    }
}
