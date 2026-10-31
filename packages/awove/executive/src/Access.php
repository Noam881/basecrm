<?php

namespace Awove\Executive;

use Awove\Core\Modules;
use Awove\Executive\Models\Goal;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function available($user): bool
    {
        return $user && $user->active && app(Modules::class)->enabled('executive') && ($user->can('executive.view.own') || $user->can('executive.view.all'));
    }

    /** @return Builder<Goal> */
    public static function goals($user): Builder
    {
        $query = Goal::query();
        if (! self::available($user)) {
            return $query->whereRaw('1=0');
        }

        return $user->can('executive.view.all') ? $query : $query->where('owner_id', $user->id);
    }

    public static function view($user, Goal $goal): void
    {
        abort_unless(self::goals($user)->whereKey($goal->id)->exists(), 403);
    }

    public static function edit($user, Goal $goal): void
    {
        self::view($user, $goal);
        abort_if($goal->archived_at !== null, 403, __('Archived goals are read only.'));
        abort_unless($user->can('executive.edit.all') || ($goal->owner_id === $user->id && $user->can('executive.edit.own')), 403);
    }
}
