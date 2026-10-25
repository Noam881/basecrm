<?php

namespace Awove\Marketing;

use App\Models\User;
use Awove\Core\Currency;
use Awove\Core\Money;
use Awove\Core\Services\DocumentLinks;
use Awove\Marketing\Models\Campaign;
use Awove\Marketing\Models\Content;
use Awove\Marketing\Models\History;
use Awove\Marketing\Models\PerformanceReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class Work
{
    /** @return Builder<Campaign> */
    public function campaigns($u): Builder
    {
        return Access::scope(Campaign::query(), $u, 'campaigns');
    }

    /** @return Builder<Content> */
    public function content($u): Builder
    {
        return Access::scope(Content::query(), $u, 'content');
    }

    /** @return Builder<PerformanceReport> */
    public function reports($u): Builder
    {
        return PerformanceReport::whereIn('campaign_id', $this->campaigns($u)->select('id'));
    }

    public function find($u, string $kind, int $id): Campaign|Content
    {
        $r = ($kind === 'campaigns' ? Campaign::query() : Content::query())->findOrFail($id);
        Access::view($u, $r, $kind);

        return $r;
    }

    public function save($u, string $kind, array $input, ?int $id = null): Campaign|Content
    {
        return DB::transaction(function () use ($u, $kind, $input, $id) {
            $u = Access::actor($u);
            $r = $id ? ($kind === 'campaigns' ? Campaign::query() : Content::query())->lockForUpdate()->findOrFail($id) : null;
            if ($r) {
                Access::edit($u, $r, $kind);
                $this->version($r, $input);
            } else {
                abort_unless(Access::available($u, $kind) && $u->can('marketing.'.$kind.'.create'), 403);
            }
            $data = $this->validate($u, $kind, $input, $r);
            if ($kind === 'campaigns') {
                app(Currency::class)->assertConsistent();
                $data['currency'] = $r?->getAttribute('currency') ?? Currency::current();
            }
            $before = $r?->getAttributes() ?? [];
            if ($r) {
                $r->fill($data);
                $r->version++;
                $r->save();
            } else {
                $class = $kind === 'campaigns' ? Campaign::class : Content::class;
                $r = $class::create([...$data, 'created_by' => $u->id, 'version' => 1]);
            }
            $this->log($r, $kind, $u, $id ? 'updated' : 'created', ['changed_fields' => array_values(array_diff(array_keys($data), array_keys(array_filter($data, fn ($v, $k) => array_key_exists($k, $before) && $before[$k] === $v, ARRAY_FILTER_USE_BOTH))))]);

            return $r;
        });
    }

    private function validate($u, string $kind, array $input, ?Model $old): array
    {
        $rules = $kind === 'campaigns' ? ['name' => 'required|string|max:160', 'objective' => 'required|string|max:10000', 'channel' => 'required|string|max:80', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'planned_budget' => 'required', 'materials_url' => 'nullable|string|max:2048', 'landing_url' => 'nullable|string|max:2048'] : ['title' => 'required|string|max:160', 'channel' => 'required|string|max:80', 'format' => 'required|string|max:80', 'planned_on' => 'required|date_format:Y-m-d', 'draft' => 'nullable|string|max:30000', 'asset_url' => 'nullable|string|max:2048', 'publication_url' => 'nullable|string|max:2048', 'published_on' => 'nullable|date_format:Y-m-d'];
        $data = validator($input, [...$rules, 'owner_id' => 'nullable|integer|min:1', 'status' => ['required', Rule::in(array_keys(Labels::statuses($kind)))]])->validate();
        $data['owner_id'] = Access::owner($u, $kind, array_key_exists('owner_id', $input) ? $input['owner_id'] : ($old ? $old->getAttribute('owner_id') : $u->id), $old);
        foreach ($kind === 'campaigns' ? ['materials_url', 'landing_url'] : ['asset_url', 'publication_url'] as $key) {
            $data[$key] = app(DocumentLinks::class)->validate($data[$key] ?? null, $key);
        }
        if ($kind === 'campaigns') {
            $data['planned_budget'] = Money::amount($data['planned_budget'], 'planned_budget');
        }
        if ($kind === 'content') {
            validator($data, ['published_on' => [$data['status'] === 'published' ? 'required' : 'nullable', 'date_format:Y-m-d'], 'publication_url' => $data['status'] === 'published' ? 'required' : 'nullable'])->validate();
        }

        return $data;
    }

    public function report($u, int $campaignId, array $input, ?int $id = null): PerformanceReport
    {
        return DB::transaction(function () use ($u, $campaignId, $input, $id) {
            $u = Access::actor($u);
            $campaign = Campaign::lockForUpdate()->findOrFail($campaignId);
            Access::edit($u, $campaign, 'campaigns');
            app(Currency::class)->assertConsistent();
            $r = $id ? PerformanceReport::where('campaign_id', $campaignId)->lockForUpdate()->findOrFail($id) : null;
            if ($r) {
                $this->version($r, $input);
            }
            $data = validator($input, ['starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'actual_spend' => 'required', 'reported_leads' => 'required|integer|min:0|max:2147483647', 'notes' => 'nullable|string|max:10000'])->validate();
            $data['actual_spend'] = Money::amount($data['actual_spend'], 'actual_spend');
            $overlap = PerformanceReport::where('campaign_id', $campaignId)->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on']);
            if ($r) {
                $overlap->whereKeyNot($r->id);
            }validator(['starts_on' => $data['starts_on']], ['starts_on' => [function ($a, $v, $fail) use ($overlap) {
                if ($overlap->exists()) {
                    $fail('This reporting period overlaps an existing period, including its boundary dates.');
                }
            }]])->validate();
            if ($r) {
                $r->fill($data);
                $r->version++;
                $r->save();
            } else {
                $r = PerformanceReport::create([...$data, 'campaign_id' => $campaign->id, 'currency' => $campaign->currency, 'created_by' => $u->id, 'version' => 1]);
            }
            $this->log($r, 'reports', $u, $id ? 'updated' : 'created', $data);

            return $r;
        });
    }

    public function archive($u, string $kind, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $kind, $id, $input) {
            $r = $this->find($u, $kind, $id);
            $r = $r->newQuery()->lockForUpdate()->findOrFail($id);
            $u = Access::actor($u);
            Access::edit($u, $r, $kind);
            abort_unless($u->can('marketing.'.$kind.'.archive'), 403);
            $this->version($r, $input);
            $r->archived_at = now();
            $r->version++;
            $r->save();
            $this->log($r, $kind, $u, 'archived', []);
        });
    }

    public function version(Model $r, array $input): void
    {
        validator($input, ['version' => 'required|integer|min:0'])->validate();
        abort_unless((int) $input['version'] === $r->getAttribute('version'), 409, 'This record changed. Reload before saving.');
    }

    public function log(Model $r, string $kind, $u, string $event, array $details): void
    {
        History::create(['kind' => $kind, 'record_id' => $r->getKey(), 'actor_id' => $u?->id, 'event' => $event, 'version' => $r->getAttribute('version'), 'details' => $details]);
    }

    public function history($u, string $kind, Model $r): array
    {
        if ($kind === 'reports') {
            $c = Campaign::findOrFail($r->getAttribute('campaign_id'));
            Access::view($u, $c, 'campaigns');
        } else {
            Access::view($u, $r, $kind);
        }

        return History::where('kind', $kind)->where('record_id', $r->getKey())->orderByDesc('id')->get()->map(fn (History $h) => ['id' => $h->id, 'event' => $h->event, 'version' => $h->version, 'changed_fields' => $h->details['changed_fields'] ?? array_keys($h->details ?? []), 'actor' => ($a = User::find($h->actor_id)) ? ['id' => $a->id, 'name' => $a->name] : null, 'created_at' => $h->created_at->toIso8601String()])->all();
    }
}
