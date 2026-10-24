<?php

namespace Awove\Operations;

use Awove\Core\Installation;
use Awove\Core\Services\Routines;
use Awove\Core\Tables\Query;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoutineController
{
    public function index(Request $request, Context $context, Routines $routines)
    {
        $filters = $context->filters($request);
        $table = app(Query::class)->run($this->query($request, $context), 'operations.routines', $request);
        $table['page']->through(fn ($record) => [...$record->toArray(), 'owner' => $record->owner?->only(['id', 'name']), 'editable' => $routines->editable($request->user(), $record), 'record_url' => '/operations/routines/'.$record->id, 'record_link_column' => 'name']);

        return Inertia::render('Operations/Routines', [...$context->options($request->user()), 'table' => $table, 'filters' => $filters]);
    }

    public function store(Request $request, Routines $routines)
    {
        $record = $routines->create($request->user(), 'operations', 'routines', $request->only(Routines::FIELDS));

        return redirect('/operations/routines/'.$record->id);
    }

    public function show(Request $request, int $id, Context $context, Routines $routines)
    {
        $record = $routines->find($request->user(), 'operations', 'routines', $id);

        return Inertia::render('Operations/Routine', [...$context->options($request->user()), 'record' => [...$record->toArray(), 'owner' => $record->owner?->only(['id', 'name']), 'editable' => $routines->editable($request->user(), $record)], 'history' => $routines->history($request->user(), $record), 'completions' => $routines->completions($request->user(), $record)]);
    }

    public function update(Request $request, int $id, Routines $routines)
    {
        $routines->update($request->user(), 'operations', 'routines', $id, $request->only([...Routines::FIELDS, 'version']));

        return back();
    }

    public function complete(Request $request, int $id, Routines $routines)
    {
        $routines->complete($request->user(), 'operations', 'routines', $id, $request->only(['version', 'completed_on', 'next_due_on', 'checked_items']));

        return back();
    }

    public function archive(Request $request, int $id, Routines $routines)
    {
        $routines->archive($request->user(), 'operations', 'routines', $id, $request->only('version'));

        return redirect('/operations/routines');
    }

    public function export(Request $request, Context $context)
    {
        $context->filters($request);

        return $context->export($request, $this->query($request, $context), 'operations.routines', 'operational-routines.csv');
    }

    public function query(Request $request, Context $context)
    {
        $query = $context->scopeDates(app(Routines::class)->query($request->user(), 'operations', 'routines'), $request, 'next_due_on');
        if ($request->input('view') === 'overdue') {
            $query->where('next_due_on', '<', now(Installation::settings()['timezone'])->toDateString());
        }

        if ($request->input('view') === 'upcoming') {
            $query->where('next_due_on', '>=', now(Installation::settings()['timezone'])->toDateString());
        }

        return $query;
    }
}
