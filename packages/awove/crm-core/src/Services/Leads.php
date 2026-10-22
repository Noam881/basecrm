<?php

namespace Awove\Core\Services;

use Awove\Core\Access;
use Awove\Core\CustomFields;
use Awove\Core\Events\LeadCreated;
use Awove\Core\Events\LeadStatusChanged;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class Leads
{
    public function create($user, array $data): Lead
    {
        abort_unless($user->active && $user->can('leads.create'), 403);

        return DB::transaction(function () use ($user, $data) {
            $data['custom_fields'] = CustomFields::validate($data['custom_fields'] ?? []);
            $lead = Lead::create([...$data, 'created_by' => $user->id]);
            $this->log($lead, $user, 'lead.created', []);
            event(new LeadCreated($lead->id, $user->id));

            return $lead;
        });
    }

    public function update($user, int $id, array $data): Lead
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $lead = Lead::lockForUpdate()->findOrFail($id);
            Gate::forUser($user)->authorize('update', $lead);
            abort_unless((int) $data['version'] === $lead->version, 409, 'This lead changed. Reload before saving.');
            unset($data['version']);
            if (array_key_exists('owner_id', $data) && $data['owner_id'] != $lead->owner_id) {
                Gate::forUser($user)->authorize('leads.assign');
            }
            $before = $lead->only(['owner_id', 'status_id']);
            if (isset($data['custom_fields'])) {
                $data['custom_fields'] = CustomFields::validate($data['custom_fields']);
            }
            $lead->fill($data);
            $lead->version++;
            $lead->save();
            foreach ($before as $key => $old) {
                if ($old != $lead->$key) {
                    $this->log($lead, $user, $key === 'owner_id' ? 'lead.assigned' : 'lead.status_changed', ['from' => $old, 'to' => $lead->$key]);
                    if ($key === 'status_id') {
                        event(new LeadStatusChanged($lead->id, $old, $lead->status_id, $user->id));
                    }
                }
            }
            // An assignment never grants record access; cancel work that is no longer accessible.
            foreach ($lead->tasks()->where('status', 'open')->with('assignee', 'lead')->get() as $task) {
                if (! Access::canReceive($task)) {
                    $task->update(['status' => 'cancelled', 'version' => $task->version + 1]);
                    $this->log($lead, $user, 'task.cancelled', ['task_id' => $task->id, 'reason' => 'access changed']);
                }
            }

            return $lead;
        });
    }

    public function log(Lead $lead, $user, string $event, array $details): void
    {
        Activity::create(['lead_id' => $lead->id, 'actor_id' => $user?->id, 'event' => $event, 'details' => $details]);
    }
}
