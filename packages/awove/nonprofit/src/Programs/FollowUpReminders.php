<?php

namespace Awove\Nonprofit\Programs;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Modules;
use Awove\Nonprofit\Programs\Models\Feedback;

class FollowUpReminders implements ReminderSource
{
    public function module(): string
    {
        return 'nonprofit';
    }

    public function key(): string
    {
        return 'program-follow-ups';
    }

    public function due(): iterable
    {
        if (! app(Modules::class)->enabled('nonprofit:programs')) {
            return;
        }foreach (Feedback::whereNull('archived_at')->whereNull('completed_on')->where('due_on', '<=', Records::today())->whereNotNull('follow_up_owner_id')->cursor() as $r) {
            yield ['record_id' => $r->id, 'version' => $r->version, 'user_id' => $r->getAttribute('follow_up_owner_id')];
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $u = config('auth.providers.users.model')::find($recipientId);
        if (! Access::available($u)) {
            return null;
        }$r = Access::query($u, 'feedback')->find($recordId);
        if (! $r || $r->archived_at || $r->getAttribute('completed_on') || ! $r->getAttribute('due_on') || $r->getAttribute('due_on')->format('Y-m-d') > Records::today() || $r->getAttribute('follow_up_owner_id') !== $recipientId) {
            return null;
        }$p = Access::program($u, $r);
        if ($p->archived_at) {
            return null;
        }

        return ['version' => $r->version, 'title' => __('Program follow-up: :action', ['action' => $r->getAttribute('next_action')]), 'href' => '/nonprofit/programs/feedback/'.$r->id];
    }
}
