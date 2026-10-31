<?php

namespace Awove\Core\Work;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Models\Routine;
use Awove\Core\Models\WorkItem;
use Awove\Core\Services\Routines;
use Awove\Core\Services\WorkAccess;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkTypes;

class WorkReminderSource implements ReminderSource
{
    public function __construct(private string $moduleId, private string $kind, private bool $routine) {}

    public function module(): string
    {
        return $this->moduleId;
    }

    public function key(): string
    {
        return ($this->routine ? 'routine-' : 'work-').$this->kind;
    }

    public function due(): iterable
    {
        $type = $this->routine ? app(WorkTypes::class)->routine($this->moduleId, $this->kind) : app(WorkTypes::class)->work($this->moduleId, $this->kind);
        if (! $type) {
            return;
        }
        $model = $this->routine ? Routine::class : WorkItem::class;
        $date = $this->routine ? 'next_due_on' : 'due_on';
        $query = $model::where('module', $this->moduleId)->where('kind', $this->kind)->whereNull('archived_at')->whereNotNull('owner_id')->where($date, '<=', app(WorkAccess::class)->today());
        if ($type instanceof WorkType) {
            $query->whereNotIn('status', $type->terminalStatuses);
        }
        foreach ($query->cursor() as $record) {
            if ($this->current($record->id, $record->getAttribute('owner_id')) !== null) {
                yield ['record_id' => $record->id, 'version' => $record->getAttribute('version'), 'user_id' => $record->getAttribute('owner_id')];
            }
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $type = $this->routine ? app(WorkTypes::class)->routine($this->moduleId, $this->kind) : app(WorkTypes::class)->work($this->moduleId, $this->kind);
        if (! $type) {
            return null;
        }
        $user = config('auth.providers.users.model')::find($recipientId);
        $query = $this->routine ? app(Routines::class)->query($user, $this->moduleId, $this->kind, true) : app(WorkItems::class)->query($user, $this->moduleId, $this->kind, true);
        $record = $query->where('owner_id', $recipientId)->whereNull('archived_at')->find($recordId);
        if (! $record) {
            return null;
        }
        $date = $record->getAttribute($this->routine ? 'next_due_on' : 'due_on');
        if (! $date || $date->format('Y-m-d') > app(WorkAccess::class)->today() || ($type instanceof WorkType && in_array($record->getAttribute('status'), $type->terminalStatuses, true))) {
            return null;
        }

        return ['version' => $record->getAttribute('version'), 'title' => __($type->label).': '.$record->getAttribute($this->routine ? 'name' : 'title'), 'href' => $type->href($record->id)];
    }
}
