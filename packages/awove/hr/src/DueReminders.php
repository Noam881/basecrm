<?php

namespace Awove\Hr;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Installation;
use Awove\Core\Modules;

class DueReminders implements ReminderSource
{
    public function __construct(private string $section) {}

    public function module(): string
    {
        return 'hr';
    }

    public function key(): string
    {
        return $this->section;
    }

    private function query()
    {
        $model = Access::MODELS[$this->section];
        $q = $model::whereNull('archived_at');

        return $this->section === 'cases' ? $q->whereNull('completed_on')->where('due_on', '<=', now(Installation::settings()['timezone'])->toDateString()) : $q->whereNotIn('stage', ['hired', 'rejected'])->whereNotNull('next_action_on')->where('next_action_on', '<=', now(Installation::settings()['timezone'])->toDateString());
    }

    public function due(): iterable
    {
        if (! app(Modules::class)->enabled('hr')) {
            return;
        }foreach ($this->query()->orderBy('id')->cursor() as $row) {
            yield ['record_id' => $row->id, 'version' => $row->version, 'user_id' => $row->owner_id];
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $u = config('auth.providers.users.model')::where('active', true)->find($recipientId);
        if (! Access::available($u, $this->section)) {
            return null;
        }
        $row = $this->query()->whereKey($recordId)->where('owner_id', $recipientId)->first();

        return $row ? ['version' => $row->version, 'title' => ($this->section === 'cases' ? 'HR case due: ' : 'Candidate follow-up due: ').$row->name, 'href' => '/hr/'.$this->section.'/'.$row->id] : null;
    }
}
