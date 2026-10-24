<?php

namespace Awove\Executive;

use Awove\Core\Installation;
use Awove\Executive\Models\Goal;
use Awove\Executive\Models\GoalHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Goals
{
    public const STATUSES = ['not-started' => 'Not started', 'in-progress' => 'In progress', 'achieved' => 'Achieved', 'on-hold' => 'On hold'];

    public static function statuses(): array
    {
        return array_replace(self::STATUSES, array_intersect_key(Installation::manifest()['executive_goal_statuses'] ?? [], self::STATUSES));
    }

    public function save($user, array $input, ?Goal $goal = null): Goal
    {
        $user = $this->actor($user);
        abort_unless(Access::available($user), 403);
        if ($goal) {
            Access::edit($user, $goal);
        } else {
            abort_unless($user->can('executive.create'), 403);
        }
        $data = validator($input, [
            'name' => 'required|string|max:180', 'owner_id' => 'required|integer', 'target_on' => 'required|date_format:Y-m-d',
            'success_criterion' => 'required|string|max:2000', 'unit' => 'required|string|max:80',
            'target_value' => 'required|numeric|decimal:0,4|between:-99999999999999.9999,99999999999999.9999',
            'current_value' => 'required|numeric|decimal:0,4|between:-99999999999999.9999,99999999999999.9999',
            'status' => ['required', Rule::in(array_keys(self::STATUSES))], 'version' => $goal ? 'required|integer|min:1' : 'prohibited',
        ])->validate();
        $data['owner_id'] = (int) $data['owner_id'];
        foreach (['name', 'unit', 'success_criterion'] as $field) {
            $data[$field] = trim($data[$field]);
            if ($data[$field] === '') {
                throw ValidationException::withMessages([$field => 'Enter a value.']);
            }
        }

        return DB::transaction(function () use ($user, $data, $goal) {
            if ($goal) {
                $goal = Goal::lockForUpdate()->findOrFail($goal->id);
                Access::edit($user, $goal);
                abort_unless($goal->version === (int) $data['version'], 409, 'This goal changed. Reload before saving.');
                unset($data['version']);
                $this->owner($user, $data['owner_id'], $goal);
                $goal->fill($data);
                $fields = array_keys($goal->getDirty());
                $goal->version++;
                $goal->save();
                $event = 'updated';
            } else {
                $this->owner($user, $data['owner_id']);
                $goal = Goal::create([...$data, 'created_by' => $user->id]);
                $fields = array_keys($data);
                $event = 'created';
            }
            GoalHistory::create(['goal_id' => $goal->id, 'actor_id' => $user->id, 'event' => $event, 'details' => ['fields' => $fields], 'created_at' => now()]);

            return $goal;
        });
    }

    public function archive($user, Goal $goal, int $version): void
    {
        DB::transaction(function () use ($user, $goal, $version) {
            $user = $this->actor($user);
            $goal = Goal::lockForUpdate()->findOrFail($goal->id);
            Access::edit($user, $goal);
            abort_unless($user->can('executive.archive'), 403);
            abort_unless($goal->version === $version, 409);
            $goal->update(['archived_at' => now(), 'version' => $goal->version + 1]);
            GoalHistory::create(['goal_id' => $goal->id, 'actor_id' => $user->id, 'event' => 'archived', 'details' => [], 'created_at' => now()]);
        });
    }

    private function actor($user)
    {
        $current = $user?->fresh();
        abort_unless($current && $current->active, 403);

        return $current;
    }

    private function owner($user, int $ownerId, ?Goal $goal = null): void
    {
        if ($goal && $goal->owner_id === $ownerId) {
            return;
        }
        abort_unless($ownerId === $user->id || $user->can('executive.assign'), 403);
        $owner = config('auth.providers.users.model')::where('active', true)->find($ownerId);
        if (! $owner || ! ($owner->can('executive.view.own') || $owner->can('executive.view.all'))) {
            throw ValidationException::withMessages(['owner_id' => 'Choose an active staff member with Executive access.']);
        }
    }
}
