<?php

namespace Awove\Finance\Payroll;

use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Tables\Query;
use Awove\Finance\Access;
use Awove\Finance\Context;
use Awove\Finance\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class Controller
{
    public function index(Request $request, Preparations $service)
    {
        $filters = $this->filters($request);
        $table = app(Query::class)->run($this->query($request, $service), 'finance.payroll', $request);
        $table['page']->through(function ($record) use ($request, $service) {
            $cells = $record->getAttribute('cells');

            return [...$service->describe($request->user(), $record), 'cells' => $cells, 'record_url' => '/finance/payroll/'.$record->id, 'record_link_column' => 'employee'];
        });

        return Inertia::render('Finance/Payroll', [...$this->options($request), 'table' => $table, 'filters' => $filters]);
    }

    public function store(Request $request, Preparations $service)
    {
        $record = $service->create($request->user(), $request->all());

        return redirect('/finance/payroll/'.$record->id);
    }

    public function show(Request $request, int $id, Preparations $service)
    {
        $record = $service->find($request->user(), $id);

        return Inertia::render('Finance/PayrollRecord', [...$this->options($request), 'record' => $service->describe($request->user(), $record), 'editable' => $service->editable($request->user(), $record), 'documents' => $service->documents($request->user(), $id), 'history' => $service->history($request->user(), $id)]);
    }

    public function update(Request $request, int $id, Preparations $service)
    {
        $service->update($request->user(), $id, $request->all());

        return back();
    }

    public function archive(Request $request, int $id, Preparations $service)
    {
        $service->archive($request->user(), $id, $request->all());

        return back();
    }

    public function addDocument(Request $request, int $id, Preparations $service)
    {
        $service->addDocument($request->user(), $id, $request->all());

        return back();
    }

    public function document(Request $request, int $id, int $document, Preparations $service)
    {
        $service->find($request->user(), $id);
        $record = Document::where('preparation_id', $id)->findOrFail($document);

        return app(DocumentLinks::class)->redirect($record->getAttribute('url'));
    }

    public function export(Request $request, Preparations $service)
    {
        $user = Access::actor($request->user(), 'payroll');
        abort_unless($user->can('finance.payroll.export'), 403);
        $this->filters($request);
        $query = app(Query::class)->filtered($this->query($request, $service), 'finance.payroll', $request);
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['export' => 'Export at most 5,000 filtered payroll preparations.']);
        }
        // Each row carries the actual schema snapshot; no reinterpretation under changed configuration.
        $rows = (function () use ($query, $user) {
            foreach ($query->cursor() as $record) {
                $employee = app(EmployeeReferences::class)->summary($user, $record->getAttribute('employee_reference_id'));
                yield [$record->id, $employee['name'] ?? '', $record->getAttribute('period_start_on')->toDateString(), $record->getAttribute('period_end_on')->toDateString(), Preparations::statuses()[$record->getAttribute('status')], $record->getAttribute('currency'), json_encode($record->getAttribute('input_schema'), JSON_THROW_ON_ERROR), json_encode($record->getAttribute('inputs'), JSON_THROW_ON_ERROR), $record->getAttribute('sent_on')?->toDateString() ?? ''];
            }
        })();

        return app(Csv::class)->download('payroll-preparations.csv', ['ID', 'Employee', 'Period start', 'Period end', 'Status', 'Currency', 'Agreed input definitions', 'Manual inputs', 'Sent date'], $rows);
    }

    private function options(Request $request): array
    {
        $user = Access::actor($request->user(), 'payroll');

        return [...app(Context::class)->options($user, 'payroll'), 'statuses' => Preparations::statuses(), 'employees' => app(EmployeeReferences::class)->options($user, true), 'canManageEmployees' => $user->can('employees.references.view') && $user->can('employees.references.manage'), 'canArchive' => $user->can('finance.payroll.archive'), 'inputSchema' => app(Inputs::class)->configured(), 'currencyError' => Currency::consistent() ? null : Currency::ERROR];
    }

    private function filters(Request $request): array
    {
        return $request->validate(['owner_id' => 'nullable|integer|min:1', 'status' => ['nullable', Rule::in(array_keys(Preparations::STATUSES))], 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'archived' => 'nullable|boolean']);
    }

    /** @return Builder<Preparation> */
    private function query(Request $request, Preparations $service): Builder
    {
        $query = $service->query($request->user(), $request->boolean('archived'));
        foreach (['owner_id', 'status'] as $key) {
            if ($request->filled($key)) {
                $query->where($key, $request->input($key));
            }
        }
        if ($request->filled('from')) {
            $query->where('period_end_on', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('period_start_on', '<=', $request->input('to'));
        }

        return $query;
    }
}
