<?php

namespace Awove\Core\Services;

use Awove\Core\Models\WorkItem;
use Awove\Core\Work\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkItems
{
    public const FIELDS = ['title', 'description', 'owner_id', 'priority', 'status', 'due_on', 'completed_on'];

    public function __construct(private WorkTypes $types, private WorkAccess $access) {}

    public function type(string $module, string $kind): WorkType
    {
        return $this->types->work($module, $kind) ?? abort(404);
    }

    /** @return Builder<WorkItem> Includes archived records for explicit archived views. */
    public function query($user, string $module, string $kind, bool $activeOnly = false): Builder
    {
        $type = $this->types->work($module, $kind);

        return $type ? $this->access->query($user, $type, WorkItem::class, $activeOnly) : WorkItem::whereRaw('1=0');
    }

    public function find($user, string $module, string $kind, int $id): WorkItem
    {
        $this->type($module, $kind);

        return $this->query($user, $module, $kind)->findOrFail($id);
    }

    public function editable($user, WorkItem $record): bool
    {
        $type = $this->types->work($record->getAttribute('module'), $record->getAttribute('kind'));

        return $type && $this->access->editable($user, $type, $record);
    }

    public function owners($user, string $module, string $kind): array
    {
        return $this->access->owners($user, $this->type($module, $kind));
    }

    public function create($user, string $module, string $kind, array $input, array $moduleFieldsChanged = []): WorkItem
    {
        return DB::transaction(function () use ($user, $module, $kind, $input, $moduleFieldsChanged) {
            $user = $this->access->fresh($user);
            $type = $this->type($module, $kind);
            $this->access->create($user, $type);
            $data = $this->data($user, $type, $input);
            $record = WorkItem::create([...$data, 'module' => $module, 'kind' => $kind, 'created_by' => $user->id, 'version' => 1]);
            $this->access->history($user, $record, 'work.created', array_keys($data), moduleFields: $moduleFieldsChanged);

            return $record;
        });
    }

    public function update($user, string $module, string $kind, int $id, array $input, array $moduleFieldsChanged = []): WorkItem
    {
        return DB::transaction(function () use ($user, $module, $kind, $id, $input, $moduleFieldsChanged) {
            $user = $this->access->fresh($user);
            $type = $this->type($module, $kind);
            $record = $this->query($user, $module, $kind)->lockForUpdate()->findOrFail($id);
            abort_unless($this->access->editable($user, $type, $record), 403);
            $this->access->version($input, $record);
            $before = ['status' => $record->getAttribute('status'), 'completed_on' => $record->getRawOriginal('completed_on')];
            $record->fill($this->data($user, $type, $input, $record));
            $fields = array_keys($record->getDirty());
            $record->version++;
            $record->save();
            $this->access->history($user, $record, 'work.updated', $fields, ['from_status' => $before['status'], 'to_status' => $record->getAttribute('status'), 'previous_completed_on' => $before['completed_on']], $moduleFieldsChanged);

            return $record;
        });
    }

    public function archive($user, string $module, string $kind, int $id, array $input): WorkItem
    {
        return DB::transaction(function () use ($user, $module, $kind, $id, $input) {
            $user = $this->access->fresh($user);
            $type = $this->type($module, $kind);
            $record = $this->query($user, $module, $kind)->lockForUpdate()->findOrFail($id);
            abort_unless($this->access->editable($user, $type, $record) && $user->can($type->permission('archive')), 403);
            $this->access->version($input, $record);
            $record->update(['archived_at' => now(), 'version' => $record->version + 1]);
            $this->access->history($user, $record, 'work.archived', ['archived_at']);

            return $record;
        });
    }

    public function history($user, WorkItem $record): array
    {
        $record = $this->find($user, $record->getAttribute('module'), $record->getAttribute('kind'), $record->id);

        return $this->access->historyFor($record);
    }

    private function data($user, WorkType $type, array $input, ?WorkItem $record = null): array
    {
        $defaults = $record?->only(self::FIELDS) ?? ['owner_id' => $user->id, 'priority' => 'normal', 'status' => $type->defaultStatus, 'description' => null, 'completed_on' => null];
        foreach (['due_on', 'completed_on'] as $field) {
            if ($record) {
                $defaults[$field] = $record->getRawOriginal($field);
            }
        }
        $values = [...$defaults, ...$input];
        foreach (['due_on', 'completed_on', 'owner_id'] as $field) {
            if (is_string($values[$field] ?? null) && trim($values[$field]) === '') {
                $values[$field] = null;
            }
        }
        $data = validator($values, [
            'title' => 'required|string|max:180', 'description' => 'nullable|string|max:20000',
            'owner_id' => 'nullable|integer|min:1', 'priority' => ['required', Rule::in(['low', 'normal', 'high'])],
            'status' => ['required', Rule::in(array_keys($type->statuses))], 'due_on' => 'required|date_format:Y-m-d', 'completed_on' => 'nullable|date_format:Y-m-d',
            'module' => 'prohibited', 'kind' => 'prohibited', 'created_by' => 'prohibited', 'archived_at' => 'prohibited',
            'version' => $record ? 'required|integer|min:1' : 'prohibited',
        ])->validate();
        unset($data['version']);
        $data['title'] = trim($data['title']);
        $data['description'] = filled($data['description'] ?? null) ? trim($data['description']) : null;
        $data['owner_id'] = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
        $this->access->owner($user, $type, $data['owner_id'], $record);
        if (in_array($data['status'], $type->completedStatuses, true)) {
            $data['completed_on'] = $data['completed_on'] ?? $this->access->today();
        } else {
            // Reopening and cancelling stop claiming a current completion; history retains the previous date.
            if (array_key_exists('completed_on', $input) && filled($input['completed_on']) && $input['completed_on'] !== ($defaults['completed_on'] ?? null)) {
                throw ValidationException::withMessages(['completed_on' => __('A completion date applies only to a completed status.')]);
            }
            $data['completed_on'] = null;
        }

        return $data;
    }
}
