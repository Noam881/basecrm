<?php

namespace Awove\Executive;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Executive\Models\Goal;

class GoalReminders implements ReminderSource
{
    public function module(): string
    {
        return 'executive';
    }

    public function key(): string
    {
        return 'goals';
    }

    public function due(): iterable
    {
        if (! app(Modules::class)->enabled('executive')) {
            return;
        }
        foreach (Goal::whereNull('archived_at')->whereIn('status', ['not-started', 'in-progress'])->where('target_on', '<=', now(Installation::settings()['timezone'])->toDateString())->orderBy('id')->cursor() as $goal) {
            yield ['record_id' => $goal->id, 'version' => $goal->version, 'user_id' => $goal->owner_id];
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $user = config('auth.providers.users.model')::where('active', true)->find($recipientId);
        if (! Access::available($user)) {
            return null;
        }
        $goal = Access::goals($user)->whereKey($recordId)->where('owner_id', $recipientId)->whereNull('archived_at')->whereIn('status', ['not-started', 'in-progress'])->where('target_on', '<=', now(Installation::settings()['timezone'])->toDateString())->first();

        return $goal ? ['version' => $goal->version, 'title' => 'Goal target due: '.$goal->name, 'href' => '/executive/goals/'.$goal->id] : null;
    }
}
