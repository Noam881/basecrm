<?php

namespace Awove\Nonprofit\Fundraising\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptLink extends Model
{
    protected $table = 'nonprofit_pledge_receipts';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $hidden = ['finance_record_id', 'payment_id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->isDirty(['pledge_id', 'finance_record_id', 'payment_id', 'actor_id'])) {
                throw new \LogicException('Archive a receipt link and add a corrected link; retained references are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Archive fundraising records to retain history.'));
    }
}
