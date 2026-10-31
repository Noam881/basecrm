<?php

namespace Awove\Operations;

use Awove\Core\Installation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OverviewController
{
    public function __invoke(Request $request, Context $context)
    {
        $filters = $context->filters($request);
        $today = now(Installation::settings()['timezone'])->toDateString();
        $requests = app(RequestController::class)->query($request, $context)->whereNotIn('status', ['done', 'cancelled']);
        $orders = app(OrderController::class)->query($request, $context)->whereIn('status', ['ordered', 'part-received']);
        $routines = app(RoutineController::class)->query($request, $context)->whereNotNull('next_due_on');

        return Inertia::render('Operations/Overview', [...$context->options($request->user()), 'filters' => $filters, 'metrics' => ['open_requests' => (clone $requests)->count(), 'overdue_requests' => (clone $requests)->where('due_on', '<', $today)->count(), 'outstanding_orders' => (clone $orders)->count(), 'overdue_orders' => (clone $orders)->where('expected_on', '<', $today)->count(), 'upcoming_routines' => (clone $routines)->where('next_due_on', '>=', $today)->count(), 'overdue_routines' => (clone $routines)->where('next_due_on', '<', $today)->count()]]);
    }
}
