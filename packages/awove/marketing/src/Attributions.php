<?php

namespace Awove\Marketing;

use App\Models\User;
use Awove\Core\Models\Lead;
use Awove\Core\Modules;
use Awove\Marketing\Models\Attribution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class Attributions
{
    public function lead($u, int $id, bool $edit = false): Lead
    {
        $u = Access::actor($u);
        abort_unless(app(Modules::class)->enabled('sales') && ($u->can('marketing.attribution.view') || $u->can('marketing.attribution.edit')), 403);
        $lead = Lead::findOrFail($id);
        Gate::forUser($u)->authorize($edit ? 'update' : 'view', $lead);
        if ($edit) {
            abort_unless($u->can('marketing.attribution.edit'), 403);
        }

        return $lead;
    }

    public function describe($u, int $id): array
    {
        $this->lead($u, $id);
        $r = Attribution::where('lead_id', $id)->first();
        $campaign = $r?->linked_campaign_id ? app(Work::class)->campaigns($u)->find($r->linked_campaign_id) : null;
        $raw = $r?->payload;
        $fields = [];
        foreach (Labels::ATTRIBUTION as $key => $label) {
            if (is_array($raw) && array_key_exists($key, $raw) && (is_string($raw[$key]) || $raw[$key] === null)) {
                $fields[$key] = $raw[$key];
            }
        }

        return ['version' => $r->version ?? 0, 'history' => $r ? Models\History::where('kind', 'attribution')->where('record_id', $r->id)->orderByDesc('id')->get()->map(fn (Models\History $h) => ['id' => $h->id, 'event' => $h->event, 'version' => $h->version, 'changed_fields' => array_values(array_intersect($h->details['changed_fields'] ?? [], [...array_keys(Labels::ATTRIBUTION), 'linked_campaign_id'])), 'actor' => ($actor = User::find($h->actor_id)) ? ['id' => $actor->id, 'name' => $actor->name] : null, 'created_at' => $h->created_at->toIso8601String()])->all() : [], 'fields' => $fields, 'linked_campaign_id' => $campaign?->id, 'linked_campaign' => $campaign?->name, 'has_hidden_campaign' => $r?->linked_campaign_id !== null && $campaign === null, 'has_legacy_snapshot' => Models\AttributionSnapshot::where('lead_id', $id)->exists(), 'campaigns' => app(Work::class)->campaigns($u)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])->toArray(), 'canEdit' => $u->fresh()->can('marketing.attribution.edit') && Gate::forUser($u->fresh())->allows('update', Lead::findOrFail($id))];
    }

    public function update($u, int $id, array $input): Attribution
    {
        return DB::transaction(function () use ($u, $id, $input) {
            $u = Access::actor($u);
            $lead = Lead::lockForUpdate()->findOrFail($id);
            $this->lead($u, $id, true);
            $r = Attribution::where('lead_id', $id)->lockForUpdate()->first();
            validator($input, ['version' => 'required|integer|min:0', 'fields' => 'sometimes|array:'.implode(',', array_keys(Labels::ATTRIBUTION)), 'linked_campaign_id' => 'sometimes|nullable|integer|min:1'])->validate();
            abort_unless((int) $input['version'] === ($r->version ?? 0), 409, 'Attribution changed. Reload before saving.');
            $rules = [];
            foreach (Labels::ATTRIBUTION as $key => $label) {
                $rules['fields.'.$key] = $key === 'landing_page_url' ? ['nullable', 'url:http,https', 'max:2048'] : ($key === 'platform' ? ['nullable', Rule::in(Labels::CHANNELS)] : ['nullable', 'string', 'max:200']);
            }$validated = validator($input, $rules)->validate();
            $patch = [];
            foreach ($validated['fields'] ?? [] as $key => $value) {
                $patch[$key] = is_string($value) ? (trim($value) ?: null) : $value;
            }
            $link = $r?->linked_campaign_id;
            if (array_key_exists('linked_campaign_id', $input)) {
                $requested = $input['linked_campaign_id'] === null ? null : (int) $input['linked_campaign_id'];
                $oldVisible = $link === null || app(Work::class)->campaigns($u)->whereKey($link)->exists();
                if (! $oldVisible) {
                    // A redacted DTO must never erase or replace the retained relationship.
                    abort_unless($requested === null, 403);
                } elseif ($requested !== $link) {
                    if ($requested !== null) {
                        abort_unless(app(Work::class)->campaigns($u)->whereNull('archived_at')->whereKey($requested)->exists(), 403);
                    }
                    $link = $requested;
                }
            }
            if ($r) {
                $r->linked_campaign_id = $link;
                $r->version++;
                $r->updated_by = $u->id;
                $r->save();
            } else {
                $r = Attribution::create(['lead_id' => $lead->id, 'payload' => null, 'linked_campaign_id' => $link, 'version' => 1, 'updated_by' => $u->id]);
            }
            if ($patch !== []) {
                // Merge only validated string/null fields in PostgreSQL: decoding/re-encoding the
                // existing JSON would round large legacy numeric IDs and change empty objects.
                DB::update("UPDATE marketing_attributions SET payload = (CASE WHEN jsonb_typeof(payload) = 'object' THEN payload ELSE '{}'::jsonb END) || ?::jsonb WHERE id = ?", [json_encode($patch, JSON_THROW_ON_ERROR), $r->id]);
                $r->refresh();
            }
            app(Work::class)->log($r, 'attribution', $u, 'updated', ['changed_fields' => [...array_keys($validated['fields'] ?? []), ...(array_key_exists('linked_campaign_id', $input) ? ['linked_campaign_id'] : [])]]);

            return $r;
        });
    }
}
