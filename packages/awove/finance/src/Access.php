<?php

namespace Awove\Finance;

use App\Models\User;
use Awove\Core\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Access
{
    public static function available($user, string $cap): bool
    {
        $current = $user?->fresh();

        return $current && $current->active && app(Modules::class)->enabled('finance:'.$cap) && ($current->can('finance.'.$cap.'.view.own') || $current->can('finance.'.$cap.'.view.all'));
    }

    public static function actor($user, string $cap): User
    {
        $user = $user?->fresh();
        abort_unless(self::available($user, $cap), 403);

        return $user;
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public static function scope(Builder $query, $user, string $cap): Builder
    {
        if (! self::available($user, $cap)) {
            return $query->whereRaw('1=0');
        }
        $user = $user->fresh();

        return $user->can('finance.'.$cap.'.view.all') ? $query : $query->where($query->getModel()->getTable().'.owner_id', $user->id);
    }

    public static function view($user, Model $record, string $cap): void
    {
        abort_unless(self::scope($record->newQuery(), $user, $cap)->whereKey($record->getKey())->exists(), 403);
    }

    public static function edit($user, Model $record, string $cap): void
    {
        $user = self::actor($user, $cap);
        self::view($user, $record, $cap);
        abort_if($record->getAttribute('archived_at') !== null, 403, 'Archived records are read only.');
        abort_unless($user->can('finance.'.$cap.'.edit.all') || ($record->getAttribute('owner_id') === $user->id && $user->can('finance.'.$cap.'.edit.own')), 403);
    }

    public static function owners($user, string $cap): array
    {
        $user = self::actor($user, $cap);
        if (! $user->can('finance.'.$cap.'.assign')) {
            return [['id' => $user->id, 'name' => $user->name]];
        }

        return User::where('active', true)->get(['id', 'name'])->filter(fn (User $owner) => $owner->can('finance.'.$cap.'.view.own') || $owner->can('finance.'.$cap.'.view.all'))->map(fn (User $owner) => $owner->only(['id', 'name']))->values()->all();
    }
}
