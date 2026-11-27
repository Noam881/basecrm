<?php

namespace Awove\Core\Services;

use Awove\Core\Access;
use Awove\Core\CustomFields;
use Awove\Core\Events\LeadCreated;
use Awove\Core\Events\LeadStatusChanged;
use Awove\Core\Imports\LegacyPayload;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Modules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class Leads
{
    public function create($user, array $data): Lead
    {
        return $this->createNative($user, $data, []);
    }

    /** Internal historical path. No HTTP route; ordinary create/update continue rejecting these fields. */
    public function createHistorical($user, array $data, array $dates): Lead
    {
        $user = $this->historicalActor($user);

        return $this->createNative($user, $data, self::historicalDates($dates));
    }

    private function createNative($user, array $data, array $dates): Lead
    {
        abort_unless(app(Modules::class)->enabled('sales') && $user->active && $user->can('leads.create'), 403);

        return DB::transaction(function () use ($user, $data, $dates) {
            $data = $this->titleData($user, $data);
            $data = [...$data, ...Sales::opportunityData($data)];
            validator($data, ['attribution' => 'missing', 'entered_on' => 'missing', 'created_at' => 'missing', 'updated_at' => 'missing'])->validate();
            $data['custom_fields'] = CustomFields::validate($data['custom_fields'] ?? []);
            $lead = Lead::create([...$data, ...$dates, 'created_by' => $user->id]);
            $this->log($lead, $user, 'lead.created', []);
            event(new LeadCreated($lead->id, $user->id));

            return $lead;
        });
    }

    public function update($user, int $id, array $data): Lead
    {
        return $this->updateNative($user, $id, $data, []);
    }

    public function updateHistorical($user, int $id, array $data, array $dates): Lead
    {
        $user = $this->historicalActor($user);

        return $this->updateNative($user, $id, $data, self::historicalDates($dates));
    }

    private function updateNative($user, int $id, array $data, array $dates): Lead
    {
        return DB::transaction(function () use ($user, $id, $data, $dates) {
            $lead = Lead::lockForUpdate()->findOrFail($id);
            Gate::forUser($user)->authorize('update', $lead);
            abort_unless((int) $data['version'] === $lead->version, 409, __('This lead changed. Reload before saving.'));
            unset($data['version']);
            $data = [...$data, ...Sales::opportunityData($data)];
            validator($data, ['attribution' => 'missing', 'entered_on' => 'missing', 'created_at' => 'missing', 'updated_at' => 'missing'])->validate();
            if (array_key_exists('owner_id', $data) && $data['owner_id'] != $lead->owner_id) {
                Gate::forUser($user)->authorize('leads.assign');
            }
            $before = $lead->only(['owner_id', 'status_id']);
            if (isset($data['custom_fields'])) {
                $data['custom_fields'] = CustomFields::validate($data['custom_fields']);
            }
            $lead->fill([...$this->titleData($user, $data, $lead), ...$dates]);
            $lead->version++;
            $lead->save();
            $historicalChanges = array_intersect(array_keys($lead->getChanges()), array_keys($dates));
            if ($historicalChanges !== []) {
                $this->log($lead, $user, 'lead.historical_dates_updated', ['fields' => array_values($historicalChanges)]);
            }
            foreach ($before as $key => $old) {
                if ($old != $lead->$key) {
                    $this->log($lead, $user, $key === 'owner_id' ? 'lead.assigned' : 'lead.status_changed', ['from' => $old, 'to' => $lead->$key]);
                    if ($key === 'status_id') {
                        event(new LeadStatusChanged($lead->id, $old, $lead->status_id, $user->id));
                    }
                }
            }
            $salesChanges = array_intersect(array_keys($lead->getChanges()), array_keys(Sales::opportunityRules()));
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

    /** Same ordinary request validation used by HTTP and schema-native import preparation. */
    public function input($user, array $input, bool $update = false): array
    {
        $data = validator($input, ['title' => 'sometimes|nullable|string|max:160', 'contact_id' => 'nullable|integer', 'company_id' => 'nullable|integer', 'owner_id' => 'nullable|integer', 'status_id' => ($update ? 'sometimes' : 'required').'|exists:crm_statuses,id', 'source' => 'sometimes|required|string|max:100', 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])], 'custom_fields' => 'sometimes|array', 'version' => $update ? 'required|integer' : 'prohibited', 'attribution' => 'missing', 'entered_on' => 'missing', ...Sales::opportunityRules()])->validate();
        foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $key => $model) {
            if (! empty($data[$key])) {
                abort_unless(Access::identities($user, $model)->whereKey($data[$key])->exists(), 403);
            }
        }
        if (! $update && ! $user->can('leads.assign')) {
            $data['owner_id'] = $user->id;
        }
        if (isset($data['owner_id'])) {
            abort_unless($data['owner_id'] == $user->id || $user->can('leads.assign'), 403);
            abort_unless(config('auth.providers.users.model')::where('active', true)->whereKey($data['owner_id'])->exists(), 422);
        }
        if (! $update) {
            abort_unless(! empty($data['contact_id']) || ! empty($data['company_id']), 422, __('Choose a contact or company.'));
        }

        return $data;
    }

    /** Match the native schema's whole-second timestamp precision; exact source text lives in provenance. */
    public static function historicalDates(array $dates): array
    {
        abort_unless(array_diff(array_keys($dates), ['entered_on', 'created_at']) === [], 422, 'Unsupported historical lead date.');
        $result = validator($dates, ['entered_on' => 'sometimes|nullable|string|date_format:Y-m-d'])->validate();
        if (array_key_exists('created_at', $dates)) {
            abort_unless(is_string($dates['created_at']), 422, 'An exact source creation timestamp is required.');
            LegacyPayload::validate('source-created', ['body_html' => null, 'author' => ['source_id' => null, 'name' => null], 'source_created_at' => $dates['created_at'], 'source_updated_at' => null]);
            $seconds = preg_replace('/\.\d{1,9}(?=Z|[+-]\d{2}:\d{2}$)/D', '', $dates['created_at']);
            $result['created_at'] = CarbonImmutable::parse($seconds)->utc()->format('Y-m-d\TH:i:sP');
        }

        return $result;
    }

    private function historicalActor($actor)
    {
        $user = $actor?->fresh();
        abort_unless($user && $user->active && $user->email_verified_at !== null && $user->can('imports.manage'), 403);

        return $user;
    }

    /** Pure native optional-title normalization, shared by historical preparation. */
    public function titleData($user, array $data, ?Lead $lead = null): array
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
        abort_unless($selected !== [], 422, __('Choose a contact or company.'));
        $data['title'] = Str::substr(trim($selected[0]) ?: 'Lead', 0, 160);

        return $data;
    }

    public function log(Lead $lead, $user, string $event, array $details): void
    {
        Activity::create(['lead_id' => $lead->id, 'actor_id' => $user?->id, 'event' => $event, 'details' => $details]);
    }
}
