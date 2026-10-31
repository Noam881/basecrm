<?php

namespace Awove\Operations;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Installation;
use Awove\Operations\Models\SupplierOrder;

class OrderReminders implements ReminderSource
{
    public function module(): string
    {
        return 'operations';
    }

    public function key(): string
    {
        return 'orders';
    }

    public function due(): iterable
    {
        foreach (SupplierOrder::whereNull('archived_at')->whereNotNull('owner_id')->whereIn('status', ['ordered', 'part-received'])->where('expected_on', '<=', now(Installation::settings()['timezone'])->toDateString())->cursor() as $order) {
            if ($this->current($order->id, $order->owner_id)) {
                yield ['record_id' => $order->id, 'version' => $order->version, 'user_id' => $order->owner_id];
            }
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $user = config('auth.providers.users.model')::find($recipientId);
        $order = Access::orders($user)->where('owner_id', $recipientId)->whereNull('archived_at')->whereIn('status', ['ordered', 'part-received'])->where('expected_on', '<=', now(Installation::settings()['timezone'])->toDateString())->find($recordId);

        return $order ? ['version' => $order->version, 'title' => __('Supplier delivery: :reference', ['reference' => $order->reference]), 'href' => '/operations/orders/'.$order->id] : null;
    }
}
