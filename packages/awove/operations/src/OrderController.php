<?php

namespace Awove\Operations;

use Awove\Core\Installation;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Tables\Query;
use Awove\Operations\Models\SupplierOrder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrderController
{
    public function index(Request $request, Context $context, Orders $orders)
    {
        $filters = $context->filters($request);
        $query = $this->query($request, $context);
        $table = app(Query::class)->run($query, 'operations.orders', $request);
        $table['page']->through(fn ($order) => [...$orders->describe($request->user(), $order), 'record_url' => '/operations/orders/'.$order->id, 'record_link_column' => 'reference']);

        return Inertia::render('Operations/Orders', [...$context->options($request->user()), 'table' => $table, 'filters' => $filters]);
    }

    public function store(Request $request, Orders $orders)
    {
        $order = $orders->create($request->user(), $request->all());

        return redirect('/operations/orders/'.$order->id);
    }

    public function show(Request $request, int $id, Context $context, Orders $orders)
    {
        $order = SupplierOrder::findOrFail($id);
        Access::view($request->user(), $order);

        return Inertia::render('Operations/Order', [...$context->options($request->user()), 'record' => $orders->describe($request->user(), $order), 'receipts' => $orders->receipts($request->user(), $order), 'history' => $orders->history($request->user(), $order)]);
    }

    public function update(Request $request, int $id, Orders $orders)
    {
        $orders->update($request->user(), $id, $request->all());

        return back();
    }

    public function receipt(Request $request, int $id, Orders $orders)
    {
        $orders->receipt($request->user(), $id, $request->all());

        return back();
    }

    public function archive(Request $request, int $id, Orders $orders)
    {
        $orders->archive($request->user(), $id, $request->all());

        return redirect('/operations/orders');
    }

    public function document(Request $request, int $id)
    {
        $order = SupplierOrder::findOrFail($id);
        Access::view($request->user(), $order);
        abort_unless($order->document_url !== null, 404);

        return app(DocumentLinks::class)->redirect($order->document_url);
    }

    public function export(Request $request, Context $context)
    {
        $context->filters($request);

        return $context->export($request, $this->query($request, $context), 'operations.orders', 'supplier-orders.csv');
    }

    public function query(Request $request, Context $context)
    {
        $query = $context->scopeDates(Access::orders($request->user()), $request, 'expected_on');
        if ($request->filled('supplier_relationship_id')) {
            $query->where('supplier_relationship_id', $request->integer('supplier_relationship_id'));
        }
        if ($request->input('view') === 'outstanding') {
            $query->whereIn('status', ['ordered', 'part-received']);
        }
        if ($request->input('view') === 'overdue') {
            $query->whereIn('status', ['ordered', 'part-received'])->where('expected_on', '<', now(Installation::settings()['timezone'])->toDateString());
        }

        return $query;
    }
}
