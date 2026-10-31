<?php

namespace Awove\Marketing;

use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Tables\Catalog;
use Awove\Core\Tables\Query;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class Controller
{
    private function kind(Request $r): string
    {
        return $r->route('kind');
    }

    private function options($u, string $kind): array
    {
        $u = Access::actor($u);
        $k = $kind === 'reports' ? 'campaigns' : $kind;

        return ['kind' => $kind, 'owners' => Access::owners($u, $k), 'statuses' => Labels::statuses($k), 'canCreate' => $u->can('marketing.'.$k.'.create'), 'canAssign' => $u->can('marketing.'.$k.'.assign'), 'canExport' => $u->can('marketing.'.$k.'.export'), 'canArchive' => $u->can('marketing.'.$k.'.archive'), 'currency' => Currency::current(), 'currencyConsistent' => app(Currency::class)->consistent(), 'canCampaigns' => Access::available($u, 'campaigns'), 'canContent' => Access::available($u, 'content'), 'campaignOptions' => app(Work::class)->campaigns($u)->orderBy('name')->get(['id', 'name'])->toArray(), 'channels' => Labels::CHANNELS];
    }

    public function filters(Request $r): array
    {
        return $r->validate(['owner_id' => 'nullable|integer|min:1', 'campaign_id' => 'nullable|integer|min:1', 'channel' => 'nullable|string|max:80', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'status' => 'nullable|string|max:20', 'view' => ['nullable', Rule::in(['due', 'upcoming', 'archived', 'all'])]]);
    }

    public function query(Request $r, string $kind)
    {
        $this->filters($r);
        $w = app(Work::class);
        $q = match ($kind) {
            'campaigns' => $w->campaigns($r->user()),'content' => $w->content($r->user()),default => $w->reports($r->user())
        };
        if ($kind === 'reports') {
            $campaigns = $w->campaigns($r->user());
            foreach (['owner_id', 'channel'] as $key) {
                if ($r->filled($key)) {
                    $campaigns->where($key, $r->input($key));
                }
            }if ($r->filled('campaign_id')) {
                $campaigns->whereKey($r->integer('campaign_id'));
            }$q->whereIn('campaign_id', $campaigns->select('id'));
        } else {
            if ($kind === 'campaigns' && $r->filled('campaign_id')) {
                $q->whereKey($r->integer('campaign_id'));
            }
            foreach (['owner_id', 'channel', 'status'] as $key) {
                if ($r->filled($key)) {
                    $q->where($key, $r->input($key));
                }
            }if ($r->input('view') === 'archived') {
                $q->whereNotNull('archived_at');
            } elseif ($r->input('view') !== 'all') {
                $q->whereNull('archived_at');
            }
        }
        $start = $kind === 'content' ? 'planned_on' : 'starts_on';
        $end = $kind === 'content' ? 'planned_on' : 'ends_on';
        if ($r->filled('from')) {
            $q->where($start, '>=', $r->input('from'));
        }if ($r->filled('to')) {
            $q->where($end, '<=', $r->input('to'));
        }if ($kind === 'content' && in_array($r->input('view'), ['due', 'upcoming'])) {
            $q->where('status', '<>', 'published')->where('planned_on', $r->input('view') === 'due' ? '<=' : '>=', now(Installation::settings()['timezone'])->toDateString());
        }

        return $q;
    }

    public function index(Request $r)
    {
        $kind = $this->kind($r);
        abort_unless(Access::available($r->user(), $kind), 403);
        $table = app(Query::class)->run($this->query($r, $kind), 'marketing.'.$kind, $r);
        $table['page']->through(fn ($item) => [...$item->toArray(), 'record_url' => '/marketing/'.$kind.'/'.$item->getKey(), 'record_link_column' => $kind === 'campaigns' ? 'name' : 'title']);

        return Inertia::render('Marketing/Records', [...$this->options($r->user(), $kind), 'table' => $table, 'filters' => $this->filters($r)]);
    }

    public function reports(Request $r)
    {
        abort_unless(Access::available($r->user(), 'campaigns'), 403);
        $table = app(Query::class)->run($this->query($r, 'reports'), 'marketing.reports', $r);
        $table['page']->through(fn ($item) => [...$item->toArray(), 'record_url' => '/marketing/campaigns/'.$item->getAttribute('campaign_id'), 'record_link_column' => 'campaign']);
        $totals = app(Currency::class)->consistent() ? Metrics::totals(app(Query::class)->filtered($this->query($r, 'reports'), 'marketing.reports', $r)) : null;

        return Inertia::render('Marketing/Records', [...$this->options($r->user(), 'reports'), 'table' => $table, 'filters' => $this->filters($r), 'totals' => $totals]);
    }

    public function store(Request $r, Work $w)
    {
        $kind = $this->kind($r);
        $record = $w->save($r->user(), $kind, $r->all());

        return redirect('/marketing/'.$kind.'/'.$record->getKey());
    }

    public function show(Request $r, int $id, Work $w)
    {
        $kind = $this->kind($r);
        $record = $w->find($r->user(), $kind, $id);
        $u = Access::actor($r->user());
        $editable = $record->getAttribute('archived_at') === null && ($u->can('marketing.'.$kind.'.edit.all') || ($record->getAttribute('owner_id') === $u->id && $u->can('marketing.'.$kind.'.edit.own')));
        $reports = $kind === 'campaigns' ? $w->reports($u)->where('campaign_id', $id)->orderByDesc('starts_on')->get()->map(fn ($p) => [...$p->toArray(), 'cost_per_lead' => Metrics::cpl($p->actual_spend, $p->reported_leads), 'history' => $w->history($u, 'reports', $p)])->all() : [];

        return Inertia::render('Marketing/Record', [...$this->options($u, $kind), 'record' => $record->toArray(), 'editable' => $editable, 'history' => $w->history($u, $kind, $record), 'reports' => $reports, 'linkedOpportunities' => $kind === 'campaigns' ? $this->linked($u, $id) : null]);
    }

    private function linked($u, int $id): ?int
    {
        if (! app(Modules::class)->enabled('sales') || ! ($u->can('marketing.attribution.view') || $u->can('marketing.attribution.edit'))) {
            return null;
        }

        return \Awove\Core\Access::leads($u)->whereIn('id', Models\Attribution::where('linked_campaign_id', $id)->select('lead_id'))->count();
    }

    public function update(Request $r, int $id, Work $w)
    {
        $w->save($r->user(), $this->kind($r), $r->all(), $id);

        return back();
    }

    public function archive(Request $r, int $id, Work $w)
    {
        $w->archive($r->user(), $this->kind($r), $id, $r->all());

        return back();
    }

    public function storeReport(Request $r, int $campaign, Work $w)
    {
        $w->report($r->user(), $campaign, $r->all());

        return back();
    }

    public function updateReport(Request $r, int $campaign, int $report, Work $w)
    {
        $w->report($r->user(), $campaign, $r->all(), $report);

        return back();
    }

    public function document(Request $r, int $id, string $field, Work $w)
    {
        $kind = $this->kind($r);
        abort_unless(in_array($field, $kind === 'campaigns' ? ['materials_url', 'landing_url'] : ['asset_url', 'publication_url']), 404);
        $record = $w->find($r->user(), $kind, $id);
        $url = $record->getAttribute($field);
        abort_unless(is_string($url) && $url !== '', 404);

        return app(DocumentLinks::class)->redirect($url);
    }

    public function attribution(Request $r, int $lead, Attributions $a)
    {
        return response()->json($a->describe($r->user(), $lead))->header('Cache-Control', 'private, no-store');
    }

    public function updateAttribution(Request $r, int $lead, Attributions $a)
    {
        $a->update($r->user(), $lead, $r->all());

        return response()->json($a->describe($r->user(), $lead))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $r)
    {
        $kind = $this->kind($r);
        $u = Access::actor($r->user());
        abort_unless(Access::available($u, $kind === 'reports' ? 'campaigns' : $kind) && $u->can('marketing.'.($kind === 'reports' ? 'campaigns' : $kind).'.export'), 403);
        $columns = app(Catalog::class)->columns('marketing.'.$kind, $u);
        $q = app(Query::class)->filtered($this->query($r, $kind), 'marketing.'.$kind, $r);
        if ((clone $q)->count() > 5000) {
            throw ValidationException::withMessages(['export' => __('Export at most 5,000 filtered records.')]);
        }$rows = (function () use ($q, $columns) {
            foreach ($q->cursor() as $item) {
                $row = [];
                foreach (array_values($columns) as $i => $c) {
                    $v = $item->getAttribute('dt_'.$i);
                    foreach ($c->options as $o) {
                        if ((string) $o['value'] === (string) $v) {
                            $v = $o['label'];
                            break;
                        }
                    }$row[] = $v ?? '';
                }yield $row;
            }
        })();

        return app(Csv::class)->download('marketing-'.$kind.'.csv', array_map(fn ($c) => $c->label, array_values($columns)), $rows);
    }

    public function overview(Request $r, Work $w)
    {
        $u = Access::actor($r->user());
        abort_unless(Access::available($u, 'campaigns') || Access::available($u, 'content'), 403);
        $consistent = app(Currency::class)->consistent();
        $campaigns = Access::available($u, 'campaigns') ? $this->query($r, 'campaigns')->where('status', 'active')->orderBy('ends_on')->limit(10)->get()->toArray() : [];
        $content = Access::available($u, 'content') ? $this->query($r, 'content')->where('status', '<>', 'published')->where('planned_on', '>=', now(Installation::settings()['timezone'])->toDateString())->orderBy('planned_on')->limit(10)->get()->toArray() : [];
        $totals = $consistent && Access::available($u, 'campaigns') ? Metrics::totals($this->query($r, 'reports')) : null;

        return Inertia::render('Marketing/Overview', [...$this->options($u, Access::available($u, 'campaigns') ? 'campaigns' : 'content'), 'campaigns' => $campaigns, 'content' => $content, 'totals' => $totals, 'filters' => $this->filters($r)]);
    }
}
