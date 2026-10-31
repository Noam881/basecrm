<?php

namespace Awove\Knowledge;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Knowledge\Models\Renewal;

class DueReminders implements ReminderSource
{
    public function module(): string
    {
        return 'knowledge';
    }

    public function key(): string
    {
        return 'renewals';
    }

    private function query()
    {
        return Renewal::whereNull('archived_at')->where('status', '!=', 'renewed')->whereNotNull('owner_id')->whereNotNull('remind_on')->where('remind_on', '<=', now(Installation::settings()['timezone'])->toDateString());
    }

    public function due(): iterable
    {
        if (! app(Modules::class)->enabled('knowledge')) {
            return;
        }foreach ($this->query()->orderBy('id')->cursor() as $r) {
            yield ['record_id' => $r->id, 'version' => $r->version, 'user_id' => $r->owner_id];
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $u = config('auth.providers.users.model')::where('active', true)->find($recipientId);
        if (! Access::available($u, 'renewals')) {
            return null;
        }$r = $this->query()->whereKey($recordId)->where('owner_id', $recipientId)->first();

        return $r ? ['version' => $r->version, 'title' => __('Renewal reminder: :name', ['name' => $r->name]), 'href' => '/knowledge/renewals/'.$r->id] : null;
    }
}
