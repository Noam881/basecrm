<?php

namespace Awove\Marketing;

use App\Models\User;
use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Marketing\Models\Content;

class ContentReminders implements ReminderSource
{
    public function module(): string
    {
        return 'marketing';
    }

    public function key(): string
    {
        return 'content';
    }

    public function due(): iterable
    {
        if (! app(Modules::class)->enabled('marketing')) {
            return;
        }foreach (Content::whereNull('archived_at')->where('status', '<>', 'published')->whereNotNull('owner_id')->where('planned_on', '<=', now(Installation::settings()['timezone'])->toDateString())->cursor() as $r) {
            if ($this->current($r->id, $r->owner_id)) {
                yield ['record_id' => $r->id, 'version' => $r->version, 'user_id' => $r->owner_id];
            }
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $u = User::find($recipientId);
        if (! Access::available($u, 'content')) {
            return null;
        }$r = app(Work::class)->content($u)->where('owner_id', $recipientId)->whereNull('archived_at')->where('status', '<>', 'published')->where('planned_on', '<=', now(Installation::settings()['timezone'])->toDateString())->find($recordId);

        return $r ? ['version' => $r->version, 'title' => __('Content due: :title', ['title' => $r->title]), 'href' => '/marketing/content/'.$r->id] : null;
    }
}
