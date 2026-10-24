<?php

namespace Awove\Core\Jobs;

use Awove\Core\Models\WorkReminder;
use Awove\Core\Services\WorkReminders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverWorkReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $module, public string $source, public int $recordId, public int $version, public int $userId) {}

    public function handle(): void
    {
        $current = app(WorkReminders::class)->source($this->module, $this->source)?->current($this->recordId, $this->userId);
        if (! $current || $current['version'] !== $this->version) {
            return;
        }
        // The unique key is the final retry/concurrent-worker deduplication boundary.
        WorkReminder::firstOrCreate(['module' => $this->module, 'source' => $this->source, 'record_id' => $this->recordId, 'record_version' => $this->version, 'user_id' => $this->userId]);
    }
}
