<?php

namespace Awove\Core\Jobs;

use Awove\Core\Access;
use Awove\Core\Models\Reminder;
use Awove\Core\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $taskId, public int $version) {}

    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function handle(): void
    {
        DB::transaction(function () {
            $t = Task::with('assignee', 'lead')->lockForUpdate()->find($this->taskId);
            if (! $t || $t->version !== $this->version || $t->remind_at->isFuture() || ! Access::canReceive($t)) {
                return;
            }Reminder::firstOrCreate(['task_id' => $t->id, 'task_version' => $t->version], ['user_id' => $t->assignee_id]);
        });
    }
}
