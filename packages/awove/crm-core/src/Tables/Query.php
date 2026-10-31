<?php

namespace Awove\Core\Tables;

use Awove\Core\Installation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Query
{
    public function __construct(private Catalog $catalog) {}

    public function run(Builder $query, string $table, Request $request, string $pageName = 'page'): array
    {
        $columns = $this->catalog->columns($table, $request->user());
        $state = $this->state($request->all(), $columns);
        $query = $this->prepare($query, $columns, $state, $request);
        $defaults = array_values(array_filter($this->catalog->defaults($table), fn ($key) => isset($columns[$key])));
        if ($defaults === []) {
            $defaults = [array_key_first($columns)];
        }
        $page = $query->paginate($state['per_page'], ['*'], $pageName)->withQueryString();
        $page->getCollection()->transform(function ($row) use ($columns) {
            $cells = [];
            foreach (array_keys($columns) as $i => $id) {
                $cells[$id] = $row->getAttribute('dt_'.$i);
                $row->offsetUnset('dt_'.$i);
            }$row->setAttribute('cells', $cells);

            return $row;
        });
        $saved = $request->user()->display_preferences['tables'][$table] ?? [];
        if (! $saved && $table === 'leads' && isset($request->user()->display_preferences['columns'])) {
            $saved['columns'] = $request->user()->display_preferences['columns'];
        }
        $saved['columns'] = array_values(array_filter($saved['columns'] ?? $defaults, fn ($key) => isset($columns[$key])));
        if (! $saved['columns']) {
            $saved['columns'] = $defaults;
        }

        $saved['pinned'] = array_values(array_filter($saved['pinned'] ?? [], fn ($key) => isset($columns[$key])));
        $saved['widths'] = array_intersect_key($saved['widths'] ?? [], $columns);
        $saved['views'] = array_map(function ($view) use ($columns, $defaults) {
            $view['columns'] = array_values(array_filter($view['columns'], fn ($key) => isset($columns[$key])));
            if (! $view['columns']) {
                $view['columns'] = $defaults;
            }
            foreach (['sorts', 'filters'] as $key) {
                $view['state'][$key] = array_values(array_filter($view['state'][$key] ?? [], fn ($entry) => isset($columns[$entry['id']])));
            }

            return $view;
        }, $saved['views'] ?? []);

        return ['id' => $table, 'page' => $page, 'columns' => array_map(fn ($c) => [...$c->metadata(), 'operators' => $c->operators()], array_values($columns)), 'state' => $state, 'preferences' => $saved, 'defaultColumns' => $defaults];
    }

    /**
     * Reuse identical permission-scoped field validation and filters for streamed exports.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function filtered(Builder $query, string $table, Request $request): Builder
    {
        $columns = $this->catalog->columns($table, $request->user());

        return $this->prepare($query, $columns, $this->state($request->all(), $columns), $request);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string,Column>  $columns
     * @return Builder<TModel>
     */
    private function prepare(Builder $query, array $columns, array $state, Request $request): Builder
    {
        $base = $query->getModel()->getTable();
        $query->select("$base.*");
        foreach (array_values($columns) as $i => $column) {
            $query->selectRaw("({$column->expression}) AS dt_$i");
        }
        if ($state['q'] !== '') {
            $term = '%'.addcslashes($state['q'], '%_\\').'%';
            $query->where(function ($q) use ($columns, $term) {
                foreach ($columns as $c) {
                    $q->orWhereRaw("CAST(({$c->expression}) AS TEXT) ILIKE ?", [$term]);
                }
            });
        }
        if ($state['filters']) {
            $zone = Installation::display($request->user())['timezone'];
            $query->where(function ($q) use ($state, $columns, $zone) {
                foreach ($state['filters'] as $filter) {
                    $method = $state['match'] === 'any' ? 'orWhere' : 'where';
                    $q->$method(fn ($q) => $this->applyFilter($q, $columns[$filter['id']], $filter, $zone));
                }
            });
        }
        foreach ($state['sorts'] as $sort) {
            $c = $columns[$sort['id']];
            $sql = $c->id === 'priority' ? "CASE ({$c->expression}) WHEN 'low' THEN 1 WHEN 'normal' THEN 2 WHEN 'high' THEN 3 END" : $c->expression;
            if ($c->type === 'text' || $c->type === 'select' && $c->id !== 'priority') {
                $sql = "LOWER(CAST(($sql) AS TEXT))";
            }$query->orderByRaw("($sql) {$sort['direction']} NULLS LAST");
        }
        $query->orderBy("$base.id");

        return $query;
    }

    /** @param array<string,Column> $columns */
    public function state(array $input, array $columns): array
    {
        $data = validator($input, ['q' => 'nullable|string|max:160', 'sorts' => 'sometimes|array|max:5', 'sorts.*.id' => ['required', Rule::in(array_keys($columns))], 'sorts.*.direction' => ['required', Rule::in(['asc', 'desc'])], 'filters' => 'sometimes|array|max:20', 'filters.*.id' => ['required', Rule::in(array_keys($columns))], 'filters.*.op' => 'required|string', 'filters.*.value' => 'nullable', 'filters.*.value2' => 'nullable', 'match' => ['sometimes', Rule::in(['all', 'any'])], 'per_page' => ['sometimes', Rule::in([10, 20, 25, 50, 100])], 'sort' => ['nullable', Rule::in(array_keys($columns))], 'direction' => ['nullable', Rule::in(['asc', 'desc'])]])->validate();
        $sorts = $data['sorts'] ?? (isset($data['sort']) ? [['id' => $data['sort'], 'direction' => $data['direction'] ?? 'asc']] : []);
        if (! $sorts) {
            $default = isset($columns['due_at']) ? 'due_at' : (isset($columns['created_at']) ? 'created_at' : array_key_first($columns));
            $sorts = $default ? [['id' => $default, 'direction' => $default === 'created_at' ? 'desc' : 'asc']] : [];
        }
        foreach ($data['filters'] ?? [] as $i => $f) {
            $c = $columns[$f['id']];
            if (! in_array($f['op'], $c->operators())) {
                throw ValidationException::withMessages(["filters.$i.op" => __('Unsupported operator for this field.')]);
            }if (in_array($f['op'], ['empty', 'not_empty'])) {
                continue;
            }
            $values = $f['op'] === 'between' ? [$f['value'] ?? null, $f['value2'] ?? null] : ($f['op'] === 'in' ? ($f['value'] ?? null) : [$f['value'] ?? null]);
            if (! is_array($values) || count($values) > 100 || ! $values) {
                throw ValidationException::withMessages(["filters.$i.value" => __('Choose one or more values.')]);
            }
            foreach ($values as $v) {
                $rule = match ($c->type) {
                    'number' => 'numeric','date','datetime' => 'date_format:Y-m-d','boolean' => Rule::in(['true', 'false', '1', '0']),default => 'string'
                };
                validator(['value' => $v], ['value' => ['required', $rule, $c->type === 'number' ? 'between:-999999999999999,999999999999999' : 'max:500']])->validate();
            }
            if ($f['op'] === 'between' && $values[0] > $values[1]) {
                throw ValidationException::withMessages(["filters.$i.value2" => __('The range end must be after its start.')]);
            }
        }

        return ['q' => $data['q'] ?? '', 'sorts' => $sorts, 'filters' => $data['filters'] ?? [], 'match' => $data['match'] ?? 'all', 'per_page' => (int) ($data['per_page'] ?? 25)];
    }

    private function applyFilter($query, Column $c, array $f, string $zone): void
    {
        $sql = "({$c->expression})";
        if ($c->type === 'datetime') {
            $sql = "(($sql) AT TIME ZONE '".str_replace("'", "''", $zone)."')::date";
        }
        $op = $f['op'];
        $v = $f['value'] ?? null;
        if (in_array($op, ['empty', 'not_empty'])) {
            $query->whereRaw("($sql IS NULL OR CAST($sql AS TEXT) = '')".($op === 'not_empty' ? ' = FALSE' : ''));

            return;
        }
        if ($op === 'in') {
            $query->whereIn(DB::raw($sql), $v);

            return;
        }
        if (in_array($op, ['contains', 'not_contains', 'starts', 'ends'])) {
            $term = addcslashes($v, '%_\\');
            $term = match ($op) {
                'starts' => $term.'%','ends' => '%'.$term,default => '%'.$term.'%'
            };
            $query->whereRaw("CAST($sql AS TEXT) ".($op === 'not_contains' ? 'NOT ILIKE' : 'ILIKE').' ?', [$term]);

            return;
        }
        if ($op === 'between') {
            $query->whereBetween(DB::raw($sql), [$v, $f['value2']]);

            return;
        }
        $operator = match ($op) {
            'eq' => '=','neq' => '<>','gt' => '>','gte' => '>=','lt' => '<','lte' => '<=',default => throw new \LogicException('Unvalidated operator.')
        };
        if ($c->type === 'text') {
            $query->whereRaw("LOWER(CAST($sql AS TEXT)) $operator LOWER(?)", [$v]);
        } else {
            $query->whereRaw("$sql $operator ?", [$v]);
        }
    }
}
