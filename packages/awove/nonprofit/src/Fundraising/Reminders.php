<?php

namespace Awove\Nonprofit\Fundraising;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Modules;
use Awove\Nonprofit\Fundraising\Models\Constituent;

class Reminders implements ReminderSource
{
    public function module(): string
    {
        return 'nonprofit';
    }

    public function key(): string
    {
        return 'fundraising-actions';
    }

    public function due(): iterable
    {
        if (! app(Modules::class)->enabled('nonprofit:fundraising')) {
            return;
        }
        foreach (Constituent::whereNull('archived_at')->whereNotNull('owner_id')->whereNotNull('next_action')->where('next_action_on', '<=', Records::today())->cursor() as $r) {
            if ($this->current($r->id, $r->owner_id)) {
                yield ['record_id' => $r->id, 'version' => $r->version, 'user_id' => $r->owner_id];
            }
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $u = config('auth.providers.users.model')::find($recipientId);
        $r = Access::query($u)->where('owner_id', $recipientId)->whereNull('archived_at')->whereNotNull('next_action')->where('next_action_on', '<=', Records::today())->find($recordId);
        if (! $r) {
            return null;
        }
        $relationship = app(Records::class)->relationship($u, $r->getAttribute('relationship_id'));
        if (! $relationship) {
            return null;
        }

        return ['version' => $r->version, 'title' => 'Fundraising follow-up: '.$relationship['name'], 'href' => '/nonprofit/fundraising/constituents/'.$r->id];
    }
}
