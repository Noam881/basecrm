<?php

namespace Awove\Marketing;

use App\Models\User;
use Awove\Core\Modules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Access
{
    public static function actor($user): User
    {
        $user = $user?->fresh();
        abort_unless($user && $user->active && app(Modules::class)->enabled('marketing'), 403);

        return $user;
    }

    public static function available($user, string $kind): bool
    {
        $u = $user?->fresh();

        return $u && $u->active && app(Modules::class)->enabled('marketing') && ($u->can('marketing.'.$kind.'.view.own') || $u->can('marketing.'.$kind.'.view.all'));
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public static function scope(Builder $query, $user, string $kind): Builder
    {
        if (! self::available($user, $kind)) {
            return $query->whereRaw('1=0');
        } $u = self::actor($user);

        return $u->can('marketing.'.$kind.'.view.all') ? $query : $query->where($query->getModel()->getTable().'.owner_id', $u->id);
    }

    public static function view($user, Model $record, string $kind): void
    {
        abort_unless(self::scope($record->newQuery(), $user, $kind)->whereKey($record->getKey())->exists(), 403);
    }

    public static function edit($user, Model $record, string $kind): void
    {
        $u = self::actor($user);
        self::view($u, $record, $kind);
        abort_if($record->getAttribute('archived_at') !== null, 403, __('Archived records are read only.'));
        abort_unless($u->can('marketing.'.$kind.'.edit.all') || ($record->getAttribute('owner_id') === $u->id && $u->can('marketing.'.$kind.'.edit.own')), 403);
    }

    public static function owners($user, string $kind): array
    {
        $u = self::actor($user);
        if (! $u->can('marketing.'.$kind.'.assign')) {
            return [['id' => $u->id, 'name' => $u->name]];
        }

        return User::where('active', true)->get(['id', 'name'])->filter(fn (User $o) => self::available($o, $kind))->map(fn (User $o) => $o->only(['id', 'name']))->values()->all();
    }

    public static function owner($user, string $kind, mixed $id, ?Model $old = null): ?int
    {
        $u = self::actor($user);
        validator(['owner_id' => $id], ['owner_id' => 'nullable|integer|min:1'])->validate();
        $id = $id === null ? null : (int) $id;
        if ($old && $id === $old->getAttribute('owner_id')) {
            return $id;
        } abort_unless($u->can('marketing.'.$kind.'.assign') || $id === $u->id, 403);
        if ($id !== null) {
            abort_unless(User::whereKey($id)->where('active', true)->exists() && self::available(User::find($id), $kind), 422, __('Choose an active owner with Marketing access.'));
        }

        return $id;
    }
}
