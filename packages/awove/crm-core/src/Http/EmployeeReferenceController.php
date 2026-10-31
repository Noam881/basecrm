<?php

namespace Awove\Core\Http;

use Awove\Core\Models\EmployeeReference;
use Awove\Core\Services\Csv;
use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Tables\Catalog;
use Awove\Core\Tables\Query;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EmployeeReferenceController
{
    public function index(Request $request, EmployeeReferences $references)
    {
        abort_unless($references->available($request->user()), 403);
        $filters = $request->validate(['active' => ['nullable', Rule::in(['1', '0'])]]);
        $table = app(Query::class)->run($this->query($request, $references), 'employees', $request);
        $table['page']->through(fn (EmployeeReference $record) => [...$record->toArray(), 'cells' => $record->getAttribute('cells'), 'record_url' => '/people/employees/'.$record->id, 'record_link_column' => 'name']);

        return Inertia::render('Core/Employees', [...$this->options($request, $references), 'table' => $table, 'filters' => $filters]);
    }

    public function store(Request $request, EmployeeReferences $references)
    {
        $record = $references->create($request->user(), $request->all());

        return redirect('/people/employees/'.$record->id);
    }

    public function show(Request $request, int $id, EmployeeReferences $references)
    {
        $record = $references->find($request->user(), $id);

        return Inertia::render('Core/Employee', [...$this->options($request, $references), 'record' => $references->describe($request->user(), $record), 'history' => $references->history($request->user(), $id)]);
    }

    public function update(Request $request, int $id, EmployeeReferences $references)
    {
        $references->update($request->user(), $id, $request->all());

        return back();
    }

    public function active(Request $request, int $id, EmployeeReferences $references)
    {
        $data = $request->validate(['active' => 'required|boolean', 'version' => 'required|integer|min:1']);
        $references->setActive($request->user(), $id, $data['version'], $data['active']);

        return back();
    }

    public function export(Request $request, EmployeeReferences $references, Csv $csv)
    {
        abort_unless($references->available($request->user()) && $request->user()->fresh()->can('exports.view'), 403);
        $request->validate(['active' => ['nullable', Rule::in(['1', '0'])]]);
        $columns = app(Catalog::class)->columns('employees', $request->user());
        $query = app(Query::class)->filtered($this->query($request, $references), 'employees', $request);
        abort_if((clone $query)->count() > 10000, 422, 'Narrow the filters to export at most 10,000 employee references.');
        $rows = (function () use ($query, $columns) {
            foreach ($query->cursor() as $row) {
                yield array_map(fn ($column, $i) => $column->type === 'boolean' ? ($row->getAttribute('dt_'.$i) ? 'Yes' : 'No') : ($row->getAttribute('dt_'.$i) ?? ''), array_values($columns), array_keys(array_values($columns)));
            }
        })();

        return $csv->download('employee-references.csv', array_map(fn ($column) => $column->label, array_values($columns)), $rows);
    }

    private function query(Request $request, EmployeeReferences $references)
    {
        return $references->query($request->user())->when($request->filled('active'), fn ($q) => $q->where('active', $request->input('active') === '1'));
    }

    private function options(Request $request, EmployeeReferences $references): array
    {
        $user = $request->user()->fresh();
        $canManage = $user->can('employees.references.manage');

        return ['canManage' => $canManage, 'canExport' => $user->can('exports.view'), ...($canManage ? $references->linkOptions($user) : ['contacts' => [], 'accounts' => []])];
    }
}
