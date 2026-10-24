<?php

namespace Awove\Core\Services;

use Awove\Core\Installation;
use Awove\Core\Models\Routine;
use Awove\Core\Models\WorkHistory;
use Awove\Core\Models\WorkItem;
use Awove\Core\Modules;
use Awove\Core\Work\Definition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class WorkAccess
{
    public function available($user, Definition $type): bool
    {
        return $user && $user->active && app(Modules::class)->enabled($type->module) && ($user->can($type->permission('view.all')) || $user->can($type->permission('view.own')));
    }

    /** @template TModel of Model
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    public function query($user, Definition $type, string $model): Builder
    {
        $query = (new $model)->newQuery()->where('module', $type->module)->where('kind', $type->kind);
        if (! $this->available($user, $type)) {
            return $query->whereRaw('1=0');
        }

        return $user->can($type->permission('view.all')) ? $query : $query->where('owner_id', $user->id);
    }

    public function editable($user, Definition $type, WorkItem|Routine $record): bool
    {
        return $this->available($user, $type) && $record->getAttribute('module') === $type->module && $record->getAttribute('kind') === $type->kind
            && ! $record->getAttribute('archived_at') && ($user->can($type->permission('view.all')) || $record->getAttribute('owner_id') === $user->id)
            && ($user->can($type->permission('edit.all')) || ($record->getAttribute('owner_id') === $user->id && $user->can($type->permission('edit.own'))));
    }

    public function fresh($user)
    {
        $current = $user?->fresh();
        abort_unless($current && $current->active, 403);

        return $current;
    }

    public function create($user, Definition $type): void
    {
        abort_unless($this->available($user, $type) && $user->can($type->permission('create')), 403);
    }

    /** Unchanged inactive/null owners remain historical references; reassignment never grants access. */
    public function owner($user, Definition $type, ?int $ownerId, WorkItem|Routine|null $record = null): void
    {
        if ($record && $record->getAttribute('owner_id') === $ownerId) {
            return;
        }
        abort_unless($ownerId === $user->id || $user->can($type->permission('assign')), 403);
        if ($ownerId !== null) {
            $owner = config('auth.providers.users.model')::find($ownerId);
            if (! $this->available($owner, $type)) {
                throw ValidationException::withMessages(['owner_id' => 'Choose an active owner who already has access to this module.']);
            }
        }
    }

    public function owners($user, Definition $type): array
    {
        if (! $this->available($user, $type)) {
            return [];
        }
        $query = config('auth.providers.users.model')::where('active', true);
        if (! $user->can($type->permission('assign'))) {
            $query->whereKey($user->id);
        }

        return $query->get()->filter(fn ($owner) => $this->available($owner, $type))->map(fn ($owner) => $owner->only(['id', 'name']))->values()->all();
    }

    public function today(): string
    {
        return now(Installation::settings()['timezone'])->toDateString();
    }

    public function version(array $input, WorkItem|Routine $record): void
    {
        $data = validator($input, ['version' => 'required|integer|min:1'])->validate();
        abort_unless((int) $data['version'] === $record->getAttribute('version'), 409, 'This record changed. Reload before saving.');
    }

    /** Related field names must be computed by trusted module code, never copied from a request. */
    public function history($user, WorkItem|Routine $record, string $event, array $fields, array $details = [], array $moduleFields = []): void
    {
        foreach ($moduleFields as $field) {
            if (! is_string($field) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field)) {
                throw new \InvalidArgumentException('Module history accepts stable field names only.');
            }
            $fields[] = 'module:'.$record->getAttribute('module').':'.$field;
        }
        WorkHistory::create([
            'work_item_id' => $record instanceof WorkItem ? $record->id : null,
            'routine_id' => $record instanceof Routine ? $record->id : null,
            'actor_id' => $user->id, 'event' => $event,
            'changed_fields' => array_values(array_unique($fields)), 'details' => $details,
        ]);
    }

    public function historyFor(WorkItem|Routine $record): array
    {
        return WorkHistory::where($record instanceof WorkItem ? 'work_item_id' : 'routine_id', $record->id)->with('actor:id,name')->orderByDesc('id')->get()->map(fn (WorkHistory $entry) => [
            'id' => $entry->id, 'event' => $entry->event, 'changed_fields' => $entry->changed_fields, 'details' => $entry->details,
            'actor' => $entry->actor?->only(['id', 'name']), 'created_at' => CarbonImmutable::parse($entry->getAttribute('created_at'))->toISOString(),
        ])->all();
    }
}
