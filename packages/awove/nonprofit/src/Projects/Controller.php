<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Models\WorkItem;
use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\WorkItems;
use Awove\Core\Tables\Query;
use Awove\Nonprofit\Projects\Models\Document;
use Awove\Nonprofit\Projects\Models\Project;
use Awove\Nonprofit\Projects\Models\ProjectWork;
use Illuminate\Http\Request;
use Inertia\Inertia;

class Controller
{
    public function index(Request $r, Context $context)
    {
        $f = $context->filters($r);
        $s = $f['section'] ?? 'projects';
        $table = app(Query::class)->run($context->query($r), 'nonprofit.'.$s, $r);
        $table['page']->through(function ($row) use ($r, $s) {
            $u = Access::actor($r->user());
            $data = $row instanceof Project ? [...app(Records::class)->describe($u, $row), 'record_url' => '/nonprofit/projects/'.$row->id, 'record_link_column' => 'name'] : app(Work::class)->describe($u, $row);
            // These cells come only from the scoped shared Query, and current field grants are rechecked.
            $allowed = array_fill_keys(array_map(fn ($column) => $column->id, (new Tables)->columns('nonprofit.'.$s, $u)), true);
            $data['cells'] = array_intersect_key($row->getAttribute('cells') ?? [], $allowed);

            return $data;
        });

        return Inertia::render('Nonprofit/Projects/Index', [...$context->options($r->user()), 'section' => $s, 'filters' => $f, 'table' => $table]);
    }

    public function store(Request $r)
    {
        $p = app(Records::class)->save($r->user(), $r->all());

        return redirect(Access::visible($r->user(), $p) ? '/nonprofit/projects/'.$p->id : '/nonprofit/projects');
    }

    public function update(Request $r, int $id)
    {
        $p = app(Records::class)->save($r->user(), $r->all(), $id);

        return redirect(Access::visible($r->user(), $p) ? '/nonprofit/projects/'.$id : '/nonprofit/projects');
    }

    public function archive(Request $r, int $id)
    {
        app(Records::class)->archive($r->user(), $id, $r->all());

        return redirect('/nonprofit/projects');
    }

    public function show(Request $r, int $id, Context $context)
    {
        $u = Access::actor($r->user());
        $p = Access::find($u, $id);
        $period = $r->validate(['actual_from' => 'nullable|date_format:Y-m-d', 'actual_to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('actual_from') ? ['after_or_equal:actual_from'] : [])]]);
        $work = [];
        foreach (['project-tasks', 'project-milestones'] as $kind) {
            $work[$kind] = app(WorkItems::class)->query($u, 'nonprofit', $kind)->whereIn('crm_work_items.id', ProjectWork::where('project_id', $p->id)->select('work_item_id'))->orderBy('due_on')->get()->map(fn ($w) => app(Work::class)->describe($u, $w))->all();
        }

        return Inertia::render('Nonprofit/Projects/Record', [...$context->options($u, $p), 'record' => app(Records::class)->describe($u, $p), 'history' => app(Records::class)->history($u, $p), 'budgets' => app(Records::class)->budgets($u, $p), 'meetings' => app(Meetings::class)->forProject($u, $p), 'work' => $work, 'resources' => app(Links::class)->resources($u, $p), 'resourceOptions' => app(Connections::class)->resourceOptions($u), 'payments' => app(Links::class)->payments($u, $p), 'paymentOptions' => $u->can('nonprofit.projects.budgets.view') ? app(Connections::class)->paymentOptions($u) : [], 'actuals' => app(Actuals::class)->forProject($u, $p, $period['actual_from'] ?? null, $period['actual_to'] ?? null), 'documents' => app(Records::class)->documents($u, $p)]);
    }

    public function meeting(Request $r, int $id, ?int $meeting = null)
    {
        app(Meetings::class)->save($r->user(), $id, $r->all(), $meeting);

        return back();
    }

    public function resource(Request $r, int $id)
    {
        app(Links::class)->addResource($r->user(), $id, $r->all());

        return back();
    }

    public function unlinkResource(Request $r, int $id, int $link)
    {
        app(Links::class)->archiveResource($r->user(), $id, $link, $r->all());

        return back();
    }

    public function payment(Request $r, int $id)
    {
        app(Links::class)->addPayment($r->user(), $id, $r->all());

        return back();
    }

    public function unlinkPayment(Request $r, int $id, int $link)
    {
        app(Links::class)->archivePayment($r->user(), $id, $link, $r->all());

        return back();
    }

    public function document(Request $r, int $id)
    {
        app(Records::class)->document($r->user(), $id, $r->all());

        return back();
    }

    public function openDocument(Request $r, int $id)
    {
        $u = Access::actor($r->user());
        abort_unless($u->can('nonprofit.projects.documents.view'), 403);
        $doc = Document::findOrFail($id);
        Access::find($u, $doc->project_id);

        return app(DocumentLinks::class)->redirect($doc->getAttribute('url'));
    }

    public function archiveDocument(Request $r, int $id)
    {
        app(Records::class)->archiveDocument($r->user(), $id, $r->all());

        return back();
    }

    public function export(Request $r, Context $context, Csv $csv)
    {
        $u = Access::actor($r->user());
        abort_unless($u->can('nonprofit.projects.export'), 403);
        $f = $context->filters($r);
        $s = $f['section'] ?? 'projects';
        $q = app(Query::class)->filtered($context->query($r), 'nonprofit.'.$s, $r);
        abort_if((clone $q)->count() > 5000, 422, __('Narrow the filters to at most 5,000 records.'));
        $headers = $s === 'projects' ? ['id', 'name', 'objective', 'owner', 'starts_on', 'ends_on', 'status', ...($u->can('nonprofit.projects.budgets.view') ? ['planned_budget', 'currency'] : [])] : ['id', 'title', 'project', 'description', 'owner', 'priority', 'status', 'due_on', 'completed_on'];
        $rows = (function () use ($q, $u, $headers) {
            foreach ($q->cursor() as $row) {
                $d = match (true) {
                    $row instanceof Project => app(Records::class)->describe($u, $row), $row instanceof WorkItem => app(Work::class)->describe($u, $row), default => throw new \LogicException('Unsupported Projects export row.')
                };
                yield array_map(fn ($k) => is_array($d[$k] ?? null) ? ($d[$k]['name'] ?? '') : ($d[$k] ?? ''), $headers);
            }
        })();

        return $csv->download('nonprofit-'.$s.'.csv', $headers, $rows);
    }
}
