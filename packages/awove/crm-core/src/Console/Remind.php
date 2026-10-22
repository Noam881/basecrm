<?php

namespace Awove\Core\Console;

use Awove\Core\Jobs\DeliverReminder;
use Awove\Core\Models\Task;
use Illuminate\Console\Command;

class Remind extends Command
{
    protected $signature = 'crm:remind';

    protected $description = 'Enqueue due reminders, safely repeatable';

    public function handle(): int
    {
        Task::where('status', 'open')->where('remind_at', '<=', now())->whereNotExists(fn ($q) => $q->selectRaw('1')->from('crm_reminders')->whereColumn('task_id', 'crm_tasks.id')->whereColumn('task_version', 'crm_tasks.version'))->chunkById(200, function ($tasks) {
            foreach ($tasks as $t) {
                DeliverReminder::dispatch($t->id, $t->version);
            }
        });

        return self::SUCCESS;
    }
}
