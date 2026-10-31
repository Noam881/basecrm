<?php

namespace Awove\Core\Services;

use Awove\Core\Access;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class Tasks
{
    public function create($actor, Lead $lead, array $data): void
    {
        DB::transaction(function () use ($actor, $lead, $data) {
            $service = app(Leads::class);
            $lead = Lead::lockForUpdate()->findOrFail($lead->id);
            Gate::forUser($actor)->authorize('update', $lead);
            $assignee = config('auth.providers.users.model')::where('active', true)->findOrFail($data['assignee_id']);
            abort_unless($assignee->getKey() === $actor->id || $actor->can('leads.assign'), 403);
            abort_unless(Access::leads($assignee)->whereKey($lead->id)->exists(), 422, __('Assignee cannot access this lead.'));
            DB::transaction(function () use ($actor, $lead, $data, $service) {
                $task = $lead->tasks()->create($data);
                $service->log($lead, $actor, 'task.created', ['task_id' => $task->id]);
            });

        });
    }

    public function update($actor, Task $task, array $data): void
    {
        $service = app(Leads::class);

        DB::transaction(function () use ($task, $data, $actor, $service) {
            Lead::lockForUpdate()->findOrFail($task->lead_id);
            $t = Task::with('lead')->lockForUpdate()->findOrFail($task->id);
            Gate::forUser($actor)->authorize('update', $t->lead);
            abort_unless($actor->id === $t->assignee_id || $actor->can('leads.assign'), 403);
            abort_unless($t->version === (int) $data['version'], 409);
            unset($data['version']);
            foreach (['due_at', 'remind_at'] as $k) {
                if (isset($data[$k])) {
                    $data[$k] = CarbonImmutable::parse($data[$k])->utc();
                }
            }abort_if(($data['remind_at'] ?? $t->remind_at) > ($data['due_at'] ?? $t->due_at), 422, __('Reminder must be before the deadline.'));
            $t->update([...$data, 'version' => $t->version + 1]);
            $service->log($t->lead()->firstOrFail(), $actor, 'task.'.$t->status, ['task_id' => $t->id]);
        });

    }
}
