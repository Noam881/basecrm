<?php

namespace Awove\Core\Services;

use Awove\Core\Models\Routine;
use Awove\Core\Models\RoutineCompletion;
use Awove\Core\Work\RoutineType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class Routines
{
    public const FIELDS = ['name', 'owner_id', 'frequency', 'next_due_on', 'checklist'];

    public function __construct(private WorkTypes $types, private WorkAccess $access) {}

    public function type(string $module, string $kind): RoutineType
    {
        return $this->types->routine($module, $kind) ?? abort(404);
    }

    /** @return Builder<Routine> Includes archived records for explicit archived views. */
    public function query($user, string $module, string $kind, bool $activeOnly = false): Builder
    {
        $type = $this->types->routine($module, $kind);

        return $type ? $this->access->query($user, $type, Routine::class, $activeOnly) : Routine::whereRaw('1=0');
    }

    public function find($user, string $module, string $kind, int $id): Routine
    {
        $this->type($module, $kind);

        return $this->query($user, $module, $kind)->findOrFail($id);
    }

    public function editable($user, Routine $record): bool
    {
        $type = $this->types->routine($record->getAttribute('module'), $record->getAttribute('kind'));

        return $type && $this->access->editable($user, $type, $record);
    }

    public function owners($user, string $module, string $kind): array
    {
        return $this->access->owners($user, $this->type($module, $kind));
    }

    public function create($user, string $module, string $kind, array $input, array $moduleFieldsChanged = []): Routine
    {
        return DB::transaction(function () use ($user, $module, $kind, $input, $moduleFieldsChanged) {
            $user = $this->access->fresh($user);
            $type = $this->type($module, $kind);
            $this->access->create($user, $type);
            $record = Routine::create([...$this->data($user, $type, $input), 'module' => $module, 'kind' => $kind, 'created_by' => $user->id, 'version' => 1]);
            $this->access->history($user, $record, 'routine.created', self::FIELDS, moduleFields: $moduleFieldsChanged);

            return $record;
        });
    }

    public function update($user, string $module, string $kind, int $id, array $input, array $moduleFieldsChanged = []): Routine
    {
        return DB::transaction(function () use ($user, $module, $kind, $id, $input, $moduleFieldsChanged) {
            $user = $this->access->fresh($user);
            $type = $this->type($module, $kind);
            $record = $this->query($user, $module, $kind)->lockForUpdate()->findOrFail($id);
            abort_unless($this->access->editable($user, $type, $record), 403);
            $this->access->version($input, $record);
            $record->fill($this->data($user, $type, $input, $record));
            $fields = array_keys($record->getDirty());
            $record->version++;
            $record->save();
            $this->access->history($user, $record, 'routine.updated', $fields, moduleFields: $moduleFieldsChanged);

            return $record;
        });
    }

    public function complete($user, string $module, string $kind, int $id, array $input): RoutineCompletion
    {
        return DB::transaction(function () use ($user, $module, $kind, $id, $input) {
            $user = $this->access->fresh($user);
            $type = $this->type($module, $kind);
            $record = $this->query($user, $module, $kind)->lockForUpdate()->findOrFail($id);
            abort_unless($this->access->editable($user, $type, $record), 403);
            $this->access->version($input, $record);
            abort_unless($record->getAttribute('next_due_on') !== null, 422, 'Set the next due date before completing another occurrence.');
            $values = ['completed_on' => $this->access->today(), 'next_due_on' => null, 'checked_items' => [], ...$input];
            foreach (['completed_on', 'next_due_on'] as $field) {
                if (is_string($values[$field] ?? null) && trim($values[$field]) === '') {
                    $values[$field] = $field === 'completed_on' ? $this->access->today() : null;
                }
            }
            $data = validator($values, [
                'completed_on' => 'required|date_format:Y-m-d', 'next_due_on' => 'nullable|date_format:Y-m-d',
                'checked_items' => 'array|max:20', 'checked_items.*' => ['required', 'distinct', Rule::in($record->getAttribute('checklist'))],
            ])->validate();
            $completion = RoutineCompletion::create([
                'routine_id' => $record->id, 'routine_version' => $record->version, 'created_at' => now(),
                'occurrence_due_on' => $record->getAttribute('next_due_on'), 'completed_on' => $data['completed_on'], 'completed_by' => $user->id,
                'checklist_snapshot' => array_map(fn ($label) => ['label' => $label, 'completed' => in_array($label, $data['checked_items'], true)], $record->getAttribute('checklist')),
            ]);
            // Frequency is descriptive. Only an explicit date supplied by the person schedules another occurrence.
            $record->update(['next_due_on' => $data['next_due_on'] ?? null, 'version' => $record->version + 1]);
            $this->access->history($user, $record, 'routine.completed', ['next_due_on'], ['completion_id' => $completion->id, 'completed_on' => $data['completed_on']]);

            return $completion;
        });
    }

    public function archive($user, string $module, string $kind, int $id, array $input): Routine
    {
        return DB::transaction(function () use ($user, $module, $kind, $id, $input) {
            $user = $this->access->fresh($user);
            $type = $this->type($module, $kind);
            $record = $this->query($user, $module, $kind)->lockForUpdate()->findOrFail($id);
            abort_unless($this->access->editable($user, $type, $record) && $user->can($type->permission('archive')), 403);
            $this->access->version($input, $record);
            $record->update(['archived_at' => now(), 'version' => $record->version + 1]);
            $this->access->history($user, $record, 'routine.archived', ['archived_at']);

            return $record;
        });
    }

    public function history($user, Routine $record): array
    {
        $record = $this->find($user, $record->getAttribute('module'), $record->getAttribute('kind'), $record->id);

        return $this->access->historyFor($record);
    }

    public function completions($user, Routine $record): array
    {
        $record = $this->find($user, $record->getAttribute('module'), $record->getAttribute('kind'), $record->id);

        return RoutineCompletion::where('routine_id', $record->id)->with('actor:id,name')->orderByDesc('completed_on')->orderByDesc('id')->get()->map(fn (RoutineCompletion $completion) => [
            ...$completion->only(['id', 'routine_version', 'checklist_snapshot']),
            'occurrence_due_on' => $completion->getAttribute('occurrence_due_on')?->format('Y-m-d'),
            'completed_on' => $completion->getAttribute('completed_on')->format('Y-m-d'),
            'actor' => $completion->actor?->only(['id', 'name']), 'created_at' => $completion->getAttribute('created_at')->toISOString(),
        ])->all();
    }

    private function data($user, RoutineType $type, array $input, ?Routine $record = null): array
    {
        $defaults = $record?->only(self::FIELDS) ?? ['owner_id' => $user->id, 'checklist' => []];
        if ($record) {
            $defaults['next_due_on'] = $record->getRawOriginal('next_due_on');
        }
        $values = [...$defaults, ...$input];
        foreach (['next_due_on', 'owner_id'] as $field) {
            if (is_string($values[$field] ?? null) && trim($values[$field]) === '') {
                $values[$field] = null;
            }
        }
        if (is_array($values['checklist'] ?? null)) {
            $values['checklist'] = array_map(fn ($label) => is_string($label) ? trim($label) : $label, $values['checklist']);
        }
        $data = validator($values, [
            'name' => 'required|string|max:180', 'owner_id' => 'nullable|integer|min:1', 'frequency' => 'required|string|max:120',
            'next_due_on' => ($record ? 'nullable' : 'required').'|date_format:Y-m-d', 'checklist' => 'array|max:20', 'checklist.*' => 'required|string|max:200|distinct',
            'module' => 'prohibited', 'kind' => 'prohibited', 'created_by' => 'prohibited', 'archived_at' => 'prohibited',
            'version' => $record ? 'required|integer|min:1' : 'prohibited',
        ])->validate();
        unset($data['version']);
        $data['name'] = trim($data['name']);
        $data['frequency'] = trim($data['frequency']);
        $data['owner_id'] = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
        $this->access->owner($user, $type, $data['owner_id'], $record);

        return $data;
    }
}
