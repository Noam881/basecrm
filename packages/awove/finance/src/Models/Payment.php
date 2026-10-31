<?php

namespace Awove\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $record_id
 * @property int $actor_id
 * @property string $amount
 * @property string|null $reference
 * @property string|null $notes
 * @property string $submission_key
 * @property string $submission_fingerprint
 * @property Carbon $paid_on
 * @property Carbon $created_at
 */
class Payment extends Model
{
    protected $table = 'finance_payments';

    protected $guarded = [];

    public $timestamps = false;

    protected $hidden = ['submission_key', 'submission_fingerprint'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_on' => 'date:Y-m-d', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Payments are append-only. Refunds and corrections require an agreed business policy.'));
        static::deleting(fn () => throw new \LogicException('Payment history cannot be deleted.'));
    }
}
