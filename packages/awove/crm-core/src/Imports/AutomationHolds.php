<?php

namespace Awove\Core\Imports;

use Awove\Core\Contracts\ImportHoldSource;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Task;
use Awove\Core\Services\WorkReminders;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/** Persistent eligibility holds only; domain integrity/security events and resource data stay intact. */
class AutomationHolds
{
    private const REASONS = ['reviewed_activation', 'cancelled_import', 'incorrect_scope'];

    public function placeNative($actor, string $boundary, string $batchId, string $kind, int $id): array
    {
        $this->actor($actor);
        abort_unless(in_array($kind, ['lead', 'task'], true) && $id > 0, 422, 'Invalid import hold resource.');

        return $this->mutate($actor, $boundary, $batchId, function ($user) use ($boundary, $batchId, $kind, $id): array {
            $this->authorize($user, 'core', $kind, $id);

            return $this->store($user, $boundary, $batchId, 'core', $kind, $id);
        });
    }

    public function placeReminder($actor, string $boundary, string $batchId, string $module, string $source, int $id): array
    {
        $this->actor($actor);
        Metadata::token($module, 32);
        Metadata::token($source);
        abort_unless($module !== 'core' && $id > 0, 422, 'Invalid import hold resource.');

        return $this->mutate($actor, $boundary, $batchId, function ($user) use ($boundary, $batchId, $module, $source, $id): array {
            $this->authorize($user, $module, $source, $id);

            return $this->store($user, $boundary, $batchId, $module, $source, $id);
        });
    }

    public function release($actor, string $boundary, int $holdId, string $reason): array
    {
        $this->actor($actor);
        abort_unless(in_array($reason, self::REASONS, true), 422, 'Invalid import hold release reason.');
        abort_unless(Schema::hasTable('crm_import_automation_holds'), 503, 'Import automation holds require migration.');
        $initial = AutomationHold::findOrFail($holdId);

        return $this->mutate($actor, $boundary, $initial->batch_id, function ($user) use ($boundary, $holdId, $reason): array {
            $hold = AutomationHold::findOrFail($holdId);
            abort_unless($hold->boundary === $boundary, 409, 'Import hold boundary changed.');
            $this->authorize($user, $hold->module, $hold->source, $hold->record_id);
            $hold = AutomationHold::lockForUpdate()->findOrFail($holdId);
            if ($hold->state === 'released') {
                abort_unless($hold->release_reason === $reason, 409, 'Import hold release is retained.');

                return $this->metadata($hold);
            }
            $hold->update(['state' => 'released', 'released_by' => $user->id, 'released_at' => now(), 'release_reason' => $reason]);

            return $this->metadata($hold);
        });
    }

    /** Safe before migration or on a misbound worker: do not enqueue reminder automations. */
    public function blocked(string $module, string $source, int $id): bool
    {
        return ! $this->ready() || AutomationHold::where(['module' => $module, 'source' => $source, 'record_id' => $id, 'state' => 'held'])->exists();
    }

    /** @template T of Task
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function eligibleTasks(Builder $query): Builder
    {
        if (! $this->ready()) {
            return $query->whereRaw('1=0');
        }

        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('crm_import_automation_holds')->where('module', 'core')->where('state', 'held')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('source', 'task')->whereColumn('record_id', 'crm_tasks.id'))
                ->orWhere(fn ($q) => $q->where('source', 'lead')->whereColumn('record_id', 'crm_tasks.lead_id'))));
    }

    /** Serializes placement/release with actual reminder insertion, including jobs queued before a hold. */
    public function deliverReminder(string $module, string $source, int $id, Closure $deliver): void
    {
        if (! $this->ready()) {
            return;
        }
        DB::transaction(function () use ($module, $source, $id, $deliver): void {
            $this->lock([[$module, $source, $id]]);
            if (! $this->blocked($module, $source, $id)) {
                $deliver();
            }
        });
    }

    public function deliverTask(int $id, Closure $deliver): void
    {
        if (! $this->ready()) {
            return;
        }
        DB::transaction(function () use ($id, $deliver): void {
            $initial = Task::find($id);
            if (! $initial) {
                return;
            }
            $this->lock([['core', 'lead', $initial->lead_id], ['core', 'task', $id]]);
            $task = Task::with('assignee', 'lead')->lockForUpdate()->find($id);
            if ($task && $task->lead_id === $initial->lead_id && ! $this->blocked('core', 'lead', $task->lead_id) && ! $this->blocked('core', 'task', $id)) {
                $deliver($task);
            }
        });
    }

    private function authorize($user, string $module, string $source, int $id): void
    {
        if ($module === 'core') {
            abort_unless(in_array($source, ['lead', 'task'], true), 403);
            $initial = $source === 'task' ? Task::findOrFail($id) : null;
            $leadId = $initial ? $initial->lead_id : $id;
            $subjects = [['core', 'lead', $leadId]];
            if ($initial) {
                $subjects[] = ['core', 'task', $id];
            }
            $this->lock($subjects);
            $lead = Lead::lockForUpdate()->findOrFail($leadId);
            Gate::forUser($user)->authorize('update', $lead);
            if ($initial) {
                $task = Task::lockForUpdate()->findOrFail($id);
                abort_unless($task->lead_id === $lead->id, 409, 'Import hold parent changed.');
                abort_unless($task->assignee_id === $user->id || $user->can('leads.assign'), 403);
            }

            return;
        }
        $provider = app(WorkReminders::class)->source($module, $source);
        abort_unless($provider instanceof ImportHoldSource, 403, 'Import hold source is unavailable.');
        $this->lock([[$module, $source, $id]]);
        $provider->authorizeImportHold($user, $id);
    }

    private function mutate($actor, string $boundary, string $batchId, Closure $write): array
    {
        $this->actor($actor);
        Metadata::digest($boundary);
        $batchId = Metadata::uuid($batchId);
        abort_unless(Schema::hasTable('crm_import_automation_holds'), 503, 'Import automation holds require migration.');

        return DB::transaction(function () use ($actor, $boundary, $batchId, $write): array {
            $user = $this->actor($actor);
            abort_unless(hash_equals(Runtime::current()['boundary'], $boundary), 409, 'Import runtime boundary changed.');
            $batch = Batch::lockForUpdate()->findOrFail($batchId);
            abort_unless($batch->boundary === $boundary && $batch->state === 'open', 409, 'Import hold batch is unavailable.');
            $result = $write($user);
            $user = $this->actor($user);
            $this->authorize($user, $result['module'], $result['source'], $result['record_id']);
            abort_unless(hash_equals(Runtime::current()['boundary'], $boundary), 409, 'Import runtime boundary changed.');

            return $result;
        });
    }

    private function actor($actor)
    {
        $user = $actor?->fresh();
        abort_unless($user && $user->active && $user->email_verified_at !== null && $user->can('imports.manage'), 403);

        return $user;
    }

    private function ready(): bool
    {
        try {
            if (! Schema::hasTable('crm_import_automation_holds')) {
                return false;
            }
            Runtime::current();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function lock(array $subjects): void
    {
        $keys = array_values(array_unique(array_map(fn ($subject) => json_encode($subject, JSON_THROW_ON_ERROR), $subjects)));
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['crm-import-automation:'.$key]);
        }
    }

    private function store($user, string $boundary, string $batchId, string $module, string $source, int $id): array
    {
        $hold = AutomationHold::firstOrCreate(['batch_id' => $batchId, 'module' => $module, 'source' => $source, 'record_id' => $id], ['boundary' => $boundary, 'created_by' => $user->id]);
        $hold = AutomationHold::lockForUpdate()->findOrFail($hold->id);
        abort_unless($hold->state === 'held', 409, 'Released import holds are retained. Start a reviewed new batch.');

        return $this->metadata($hold);
    }

    private function metadata(AutomationHold $hold): array
    {
        return $hold->only(['id', 'batch_id', 'module', 'source', 'record_id', 'state', 'created_by', 'released_by', 'release_reason']);
    }
}
