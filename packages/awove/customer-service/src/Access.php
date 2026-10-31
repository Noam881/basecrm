<?php

namespace Awove\CustomerService;

use Awove\Core\Modules;
use Awove\CustomerService\Models\Feedback;
use Awove\CustomerService\Models\ServiceRequest;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function available($user): bool
    {
        return $user && $user->active && app(Modules::class)->enabled('customer-service') && ($user->can('customer-service.view.own') || $user->can('customer-service.view.all'));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public static function records($user, string $model = ServiceRequest::class): Builder
    {
        $query = (new $model)->newQuery();
        if (! self::available($user)) {
            return $query->whereRaw('1=0');
        }

        return $user->can('customer-service.view.all') ? $query : $query->where('owner_id', $user->id);
    }

    public static function view($user, ServiceRequest|Feedback $record): void
    {
        abort_unless(self::available($user), 403);
        abort_unless(self::records($user, $record::class)->whereKey($record->id)->exists(), 403);
    }

    public static function edit($user, ServiceRequest|Feedback $record): void
    {
        self::view($user, $record);
        abort_if($record->archived_at !== null, 403, __('Archived records are read only.'));
        abort_unless($user->can('customer-service.edit.all') || ($record->owner_id === $user->id && $user->can('customer-service.edit.own')), 403);
    }
}
