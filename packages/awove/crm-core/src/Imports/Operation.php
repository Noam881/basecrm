<?php

namespace Awove\Core\Imports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Reservations are metadata only, never authorization or evidence that a business writer ran.
 *
 * @property CarbonImmutable $reservation_expires_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 */
class Operation extends Model
{
    protected $table = 'crm_import_operations';

    protected $guarded = [];

    protected $hidden = ['finish_intent_fingerprint'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['revision' => 'integer', 'reservation_expires_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $operation): void {
            if ($operation->getRawOriginal('state') !== 'claimed'
                || array_diff(array_keys($operation->getDirty()), ['state', 'finished_by', 'finished_at', 'result_target_type', 'result_target_id', 'result_fingerprint', 'finish_intent_fingerprint', 'error_code']) !== []) {
                throw new \LogicException('Import operation context and terminal receipts are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Import operation receipts are retained.'));
    }
}
