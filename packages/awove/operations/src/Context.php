<?php

namespace Awove\Operations;

use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Services\Csv;
use Awove\Core\Services\WorkItems;
use Awove\Core\Tables\Catalog;
use Awove\Core\Tables\Query;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Context
{
    public function options($user): array
    {
        return ['owners' => $user->can('operations.view.all') ? config('auth.providers.users.model')::where('active', true)->get(['id', 'name'])->filter(fn ($owner) => $owner->can('operations.view.own') || $owner->can('operations.view.all'))->values()->all() : app(WorkItems::class)->owners($user, 'operations', 'requests'), 'customers' => Relations::options($user, 'customer'), 'suppliers' => Relations::options($user, 'supplier'), 'statuses' => OperationsModule::requestStatuses(), 'orderStatuses' => OperationsModule::orderStatuses(), 'canCreate' => $user->can('operations.create'), 'canAssign' => $user->can('operations.assign'), 'canArchive' => $user->can('operations.archive'), 'canExport' => $user->can('operations.export'), 'canViewAll' => $user->can('operations.view.all'), 'canClassify' => $user->can('identities.classifications.manage'), 'canViewDirectory' => $user->can('identities.classifications.view') || $user->can('identities.classifications.manage'), 'currency' => Currency::current(), 'currencyError' => app(Currency::class)->consistent() ? null : Currency::ERROR, 'today' => now(Installation::settings()['timezone'])->toDateString()];
    }

    public function filters(Request $request): array
    {
        $data = $request->validate(['owner_id' => 'nullable|integer|min:1', 'customer_relationship_id' => 'nullable|integer|min:1', 'supplier_relationship_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in(['archived', 'overdue', 'upcoming', 'outstanding', 'unassigned'])]]);
        foreach (['customer_relationship_id' => 'customer', 'supplier_relationship_id' => 'supplier'] as $key => $role) {
            if ($request->filled($key)) {
                abort_unless(Relations::describe($request->user(), $request->integer($key), $role) !== null, 403);
            }
        }
        if ($request->input('view') === 'unassigned') {
            abort_unless($request->user()->can('operations.view.all'), 403);
        }

        return $data;
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeDates(Builder $query, Request $request, string $date): Builder
    {
        $table = $query->getModel()->getTable();
        $request->input('view') === 'archived' ? $query->whereNotNull("$table.archived_at") : $query->whereNull("$table.archived_at");
        if ($request->filled('owner_id')) {
            $query->where("$table.owner_id", $request->integer('owner_id'));
        }
        if ($request->filled('from')) {
            $query->where($date, '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where($date, '<=', $request->input('to'));
        }
        if ($request->input('view') === 'unassigned') {
            $query->whereNull("$table.owner_id");
        }

        return $query;
    }

    public function export(Request $request, Builder $query, string $table, string $filename)
    {
        abort_unless(Access::available($request->user()) && $request->user()->can('operations.export'), 403);
        $columns = app(Catalog::class)->columns($table, $request->user());
        $query = app(Query::class)->filtered($query, $table, $request);
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['export' => 'Export at most 5,000 filtered records.']);
        }
        $rows = (function () use ($query, $columns) {
            foreach ($query->cursor() as $row) {
                $values = [];
                foreach (array_values($columns) as $index => $column) {
                    $value = $row->getAttribute('dt_'.$index);
                    if ($column->type === 'boolean') {
                        $value = $value ? 'Yes' : 'No';
                    }foreach ($column->options as $option) {
                        if ((string) $option['value'] === (string) $value) {
                            $value = $option['label'];
                            break;
                        }
                    }$values[] = $value ?? '';
                }yield $values;
            }
        })();

        return app(Csv::class)->download($filename, array_map(fn ($column) => $column->label, array_values($columns)), $rows);
    }
}
