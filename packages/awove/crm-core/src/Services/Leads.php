<?php

namespace Awove\Core\Services;

use Awove\Core\Access;
use Awove\Core\CustomFields;
use Awove\Core\Events\LeadCreated;
use Awove\Core\Events\LeadStatusChanged;
use Awove\Core\LeadAttribution;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Modules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Leads
{
    public function create($user, array $data): Lead
    {
        abort_unless(app(Modules::class)->enabled('sales') && $user->active && $user->can('leads.create'), 403);

        return DB::transaction(function () use ($user, $data) {
            $data = $this->withOptionalTitle($user, $data);
            $data = [...$data, ...validator($data, Sales::opportunityRules())->validate()];
            $data = LeadAttribution::validate($data);
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
            $data = [...$data, ...validator($data, Sales::opportunityRules())->validate()];
            $data = LeadAttribution::validate($data);
            if (array_key_exists('owner_id', $data) && $data['owner_id'] != $lead->owner_id) {
                Gate::forUser($user)->authorize('leads.assign');
            }
            $before = $lead->only(['owner_id', 'status_id']);
            $attributionBefore = $lead->attribution;
            if (isset($data['custom_fields'])) {
                $data['custom_fields'] = CustomFields::validate($data['custom_fields']);
            }
            $lead->fill($this->withOptionalTitle($user, $data, $lead));
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
            if ($attributionBefore !== $lead->attribution) {
                $this->log($lead, $user, 'lead.attribution_updated', ['fields' => array_keys($data['attribution'] ?? [])]);
            }
            $salesChanges = array_intersect(array_keys($lead->getChanges()), ['needs', 'estimated_value', 'closing_date', 'final_value', 'loss_reason']);
            if ($salesChanges !== []) {
                $this->log($lead, $user, 'lead.sales_details_updated', ['fields' => array_values($salesChanges)]);
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

    private function withOptionalTitle($user, array $data, ?Lead $lead = null): array
    {
        if ($lead && ! array_key_exists('title', $data)) {
            return $data;
        }
        validator(['title' => $data['title'] ?? null], ['title' => 'nullable|string|max:160'])->validate();
        if (trim($data['title'] ?? '') !== '') {
            return $data;
        }
        $selected = [];
        foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $key => $model) {
            $id = array_key_exists($key, $data) ? $data[$key] : $lead?->getAttribute($key);
            validator([$key => $id], [$key => 'nullable|integer|min:1'])->validate();
            if ($id !== null) {
                $identity = Access::identities($user, $model)->whereKey($id)->first(['name']);
                abort_unless($identity !== null, 403);
                $selected[] = $identity->getAttribute('name');
            }
        }
        abort_unless($selected !== [], 422, 'Choose a contact or company.');
        $data['title'] = Str::substr(trim($selected[0]) ?: 'Lead', 0, 160);

        return $data;
    }

    public function log(Lead $lead, $user, string $event, array $details): void
    {
        Activity::create(['lead_id' => $lead->id, 'actor_id' => $user?->id, 'event' => $event, 'details' => $details]);
    }
}
