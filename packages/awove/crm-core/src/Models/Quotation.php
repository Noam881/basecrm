<?php

namespace Awove\Core\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable date casts return Carbon values when accessed as model properties.
 * The Y-m-d cast suffix controls array serialization, not the property value.
 *
 * @property CarbonImmutable|null $sent_date
 * @property CarbonImmutable|null $accepted_at
 */
class Quotation extends Model
{
    protected $table = 'sales_quotations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'sent_date' => 'immutable_date:Y-m-d', 'accepted_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $quotation) {
            if ($quotation->getOriginal('status') === 'accepted') {
                throw new \LogicException('Accepted quotations are retained unchanged. Create a new quotation for a revision.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Quotations are retained as part of the opportunity history.'));
    }
}
