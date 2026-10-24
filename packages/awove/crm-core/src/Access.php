<?php

namespace Awove\Core;

use Awove\Core\Models\Lead;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function leads($user): Builder
    {
        $q = Lead::query();
        if (! $user->active || ! app(Modules::class)->enabled('sales')) {
            return $q->whereRaw('1=0');
        }

        return $user->can('leads.view.all') ? $q : $q->where('owner_id', $user->id)->when(! $user->can('leads.view.own'), fn ($q) => $q->whereRaw('1=0'));
    }

    public static function identities($user, string $model): Builder
    {
        $q = $model::query();
        if (! $user->active) {
            return $q->whereRaw('1=0');
        }
        if ($user->can('identities.manage')) {
            return $q;
        }

        return $q->where(fn ($q) => $q->where('created_by', $user->id)->orWhereHas('leads', fn ($q) => $q->whereIn('id', self::leads($user)->select('id'))));
    }

    public static function tasks($user): Builder
    {
        return Models\Task::whereIn('lead_id', self::leads($user)->whereNull('archived_at')->select('id'))->whereHas('assignee', fn ($q) => $q->where('active', true))->when(! $user->can('leads.view.all'), fn ($q) => $q->where('assignee_id', $user->id));
    }

    public static function canReceive($task): bool
    {
        $u = $task->assignee;

        return $u && $u->active && $task->status === 'open' && ! $task->lead->archived_at && self::leads($u)->whereKey($task->lead_id)->exists();
    }
}
