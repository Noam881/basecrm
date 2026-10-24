<?php

namespace Awove\Core\Services;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;

class WorkReminders
{
    public function source(string $module, string $key): ?ReminderSource
    {
        if (! app(Modules::class)->enabled($module)) {
            return null;
        }
        foreach (app(Modules::class)->reminderSources() as $source) {
            if ($source->module() === $module && $source->key() === $key) {
                return $source;
            }
        }

        return null;
    }

    public function enqueueDue(): void
    {
        foreach (app(Modules::class)->reminderSources() as $source) {
            if (! app(Modules::class)->enabled($source->module())) {
                continue;
            }
            foreach ($source->due() as $work) {
                if (! WorkReminder::where(['module' => $source->module(), 'source' => $source->key(), 'record_id' => $work['record_id'], 'record_version' => $work['version'], 'user_id' => $work['user_id']])->exists()) {
                    DeliverWorkReminder::dispatch($source->module(), $source->key(), $work['record_id'], $work['version'], $work['user_id']);
                }
            }
        }
    }

    public function forUser($user): array
    {
        if (! $user || ! $user->active) {
            return [];
        }
        $items = [];
        foreach (WorkReminder::where('user_id', $user->id)->latest('id')->cursor() as $notice) {
            $preview = $this->preview($notice);
            if ($preview !== null) {
                $items[] = ['id' => $notice->id, 'module' => $notice->module, 'title' => $preview['title'], 'href' => $preview['href'], 'read_at' => $notice->read_at?->toISOString()];
            }
        }

        return $items;
    }

    public function preview(WorkReminder $notice): ?array
    {
        $current = $this->source($notice->module, $notice->source)?->current($notice->record_id, $notice->user_id);

        return $current && $current['version'] === $notice->record_version ? $current : null;
    }

    public function markRead($user, int $id): void
    {
        abort_unless($user && $user->active, 403);
        $notice = WorkReminder::where('user_id', $user->id)->findOrFail($id);
        abort_unless($this->preview($notice) !== null, 404);
        $notice->update(['read_at' => now()]);
    }
}
