<?php

namespace Awove\Operations;

use Awove\Core\Installation;
use Awove\Core\Models\WorkItem;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\WorkItems;
use Awove\Core\Tables\Query;
use Awove\Operations\Models\RequestDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RequestController
{
    public function index(Request $request, Context $context)
    {
        $filters = $context->filters($request);
        $query = $this->query($request, $context);
        $table = app(Query::class)->run($query, 'operations.requests', $request);
        $table['page']->through(fn ($item) => [...$this->describe($request->user(), $item), 'record_url' => '/operations/requests/'.$item->id, 'record_link_column' => 'title']);

        return Inertia::render('Operations/Requests', [...$context->options($request->user()), 'table' => $table, 'filters' => $filters]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('operations.create'), 403);
        $details = $this->details($request);
        $item = DB::transaction(function () use ($request, $details) {
            $item = app(WorkItems::class)->create($request->user(), 'operations', 'requests', $request->only(WorkItems::FIELDS), array_keys($details));
            RequestDetails::create(['work_item_id' => $item->id, ...$details]);

            return $item;
        });

        return redirect('/operations/requests/'.$item->id);
    }

    public function show(Request $request, int $id, Context $context)
    {
        $item = app(WorkItems::class)->find($request->user(), 'operations', 'requests', $id);

        return Inertia::render('Operations/Request', [...$context->options($request->user()), 'record' => $this->describe($request->user(), $item), 'history' => app(WorkItems::class)->history($request->user(), $item)]);
    }

    public function update(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $items = app(WorkItems::class);
            $existing = $items->find($request->user(), 'operations', 'requests', $id);
            abort_unless($items->editable($request->user(), $existing), 403);
            $old = RequestDetails::firstOrNew(['work_item_id' => $id]);
            $details = $this->details($request, $old);
            $changed = [];
            foreach ($details as $key => $value) {
                if ($old->getAttribute($key) !== $value) {
                    $changed[] = $key;
                }
            }$items->update($request->user(), 'operations', 'requests', $id, $request->only([...WorkItems::FIELDS, 'version']), $changed);
            $old->fill($details)->save();
        });

        return back();
    }

    public function archive(Request $request, int $id)
    {
        app(WorkItems::class)->archive($request->user(), 'operations', 'requests', $id, $request->only('version'));

        return redirect('/operations/requests');
    }

    public function document(Request $request, int $id)
    {
        app(WorkItems::class)->find($request->user(), 'operations', 'requests', $id);
        $url = RequestDetails::where('work_item_id', $id)->value('document_url');
        abort_unless(is_string($url) && $url !== '', 404);

        return app(DocumentLinks::class)->redirect($url);
    }

    public function export(Request $request, Context $context)
    {
        $context->filters($request);

        return $context->export($request, $this->query($request, $context), 'operations.requests', 'operations-requests.csv');
    }

    public function query(Request $request, Context $context)
    {
        $query = $context->scopeDates(app(WorkItems::class)->query($request->user(), 'operations', 'requests'), $request, 'crm_work_items.due_on')->leftJoin('operations_request_details', 'operations_request_details.work_item_id', '=', 'crm_work_items.id');
        if ($request->filled('customer_relationship_id')) {
            $query->where('operations_request_details.customer_relationship_id', $request->integer('customer_relationship_id'));
        }
        if ($request->input('view') === 'overdue') {
            $query->whereNotIn('status', ['done', 'cancelled'])->where('due_on', '<', now(Installation::settings()['timezone'])->toDateString());
        }

        return $query;
    }

    public function describe($user, WorkItem $item): array
    {
        $details = RequestDetails::where('work_item_id', $item->id)->first();
        $customer = Relations::describe($user, $details?->customer_relationship_id, 'customer');

        return [...$item->toArray(), 'owner' => $item->owner?->only(['id', 'name']), 'customer' => $customer, 'customer_relationship_id' => $customer ? $details->customer_relationship_id : null, 'notes' => $details?->notes, 'document_url' => $details?->document_url, 'document_link' => $details?->document_url ? '/operations/requests/'.$item->id.'/document' : null, 'editable' => app(WorkItems::class)->editable($user, $item)];
    }

    private function details(Request $request, ?RequestDetails $existing = null): array
    {
        $data = $request->validate(['customer_relationship_id' => 'nullable|integer|min:1', 'notes' => 'nullable|string|max:10000', 'document_url' => 'nullable|string|max:2048']);
        $data = [...['customer_relationship_id' => $existing?->customer_relationship_id, 'notes' => $existing?->notes, 'document_url' => $existing?->document_url], ...$data];
        $data['customer_relationship_id'] = filled($data['customer_relationship_id']) ? (int) $data['customer_relationship_id'] : null;
        if (! $data['customer_relationship_id'] && $existing?->customer_relationship_id && ! Relations::describe($request->user(), $existing->customer_relationship_id, 'customer')) {
            $data['customer_relationship_id'] = $existing->customer_relationship_id;
        }
        Relations::authorize($request->user(), $data['customer_relationship_id'], 'customer', $existing?->customer_relationship_id);
        $data['document_url'] = app(DocumentLinks::class)->validate($data['document_url']);

        return $data;
    }
}
