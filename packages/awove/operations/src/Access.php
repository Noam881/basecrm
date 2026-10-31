<?php

namespace Awove\Operations;

use Awove\Core\Modules;
use Awove\Operations\Models\SupplierOrder;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function available($user): bool
    {
        return $user && $user->active && app(Modules::class)->enabled('operations') && ($user->can('operations.view.own') || $user->can('operations.view.all'));
    }

    /** @return Builder<SupplierOrder> */
    public static function orders($user): Builder
    {
        $query = SupplierOrder::query();
        if (! self::available($user)) {
            return $query->whereRaw('1=0');
        }

        return $user->can('operations.view.all') ? $query : $query->where('owner_id', $user->id);
    }

    public static function view($user, SupplierOrder $order): void
    {
        abort_unless(self::available($user) && self::orders($user)->whereKey($order->id)->exists(), 403);
    }

    public static function edit($user, SupplierOrder $order): void
    {
        self::view($user, $order);
        abort_if($order->archived_at !== null, 403, __('Archived orders are read only.'));
        abort_unless($user->can('operations.edit.all') || ($order->owner_id === $user->id && $user->can('operations.edit.own')), 403);
    }
}
