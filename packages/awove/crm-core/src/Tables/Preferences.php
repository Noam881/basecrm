<?php

namespace Awove\Core\Tables;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class Preferences
{
    public function save(Request $r): void
    {
        $table = $r->input('table', 'leads');
        $catalog = app(Catalog::class);
        validator(['table' => $table], ['table' => ['required', Rule::in($catalog->tableIds())]])->validate();
        DB::transaction(function () use ($r, $table, $catalog) {
            $user = config('auth.providers.users.model')::lockForUpdate()->findOrFail($r->user()->id);
            $columns = $catalog->columns($table, $user);
            $keys = array_keys($columns);
            $data = $r->validate([
                'columns' => 'required|array|min:1|max:100',
                'columns.*' => ['required', 'distinct', Rule::in($keys)],
                'widths' => ['sometimes', 'array:'.implode(',', $keys), 'max:100'],
                'widths.*' => 'integer|min:100|max:600',
                'pinned' => 'sometimes|array|max:100',
                'pinned.*' => ['required', 'distinct', Rule::in($keys)],
                'density' => ['sometimes', Rule::in(['comfortable', 'compact'])],
                'wrap' => 'sometimes|boolean',
                'views' => 'sometimes|array|max:20',
                'views.*.id' => 'required|distinct|string|max:80',
                'views.*.name' => 'required|string|max:60',
                'views.*.columns' => ['required', 'array', 'min:1', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_array($value) && count($value) !== count(array_unique($value, SORT_REGULAR))) {
                        $fail('Choose each column only once within a view.');
                    }
                }],
                'views.*.columns.*' => ['required', Rule::in($keys)],
                'views.*.state' => 'required|array:q,sorts,filters,match,per_page,sort,direction',
                'views.*.state.sorts.*' => 'array:id,direction',
                'views.*.state.filters.*' => 'array:id,op,value,value2',
                'timezone' => 'sometimes|timezone',
            ]);
            $preferences = $user->display_preferences ?? [];
            $stored = $preferences['tables'][$table] ?? [];
            if (! $stored && $table === 'leads' && isset($preferences['columns'])) {
                $stored['columns'] = $preferences['columns'];
            }
            $defaults = array_values(array_filter($catalog->defaults($table), fn ($id) => isset($columns[$id])));
            if ($defaults === []) {
                $defaults = [array_key_first($columns)];
            }
            $settings = [
                'columns' => $this->mergeColumns($stored['columns'] ?? [], $data['columns'], $columns, $defaults),
                'widths' => array_key_exists('widths', $data)
                    ? array_diff_key($stored['widths'] ?? [], $columns) + $data['widths']
                    : ($stored['widths'] ?? []),
                'pinned' => array_key_exists('pinned', $data)
                    ? $this->retainUnavailable($stored['pinned'] ?? [], $data['pinned'], $columns, fn ($id) => $id)
                    : ($stored['pinned'] ?? []),
                'density' => $data['density'] ?? $stored['density'] ?? 'comfortable',
                'wrap' => $data['wrap'] ?? $stored['wrap'] ?? false,
                'views' => $stored['views'] ?? [],
            ];
            if (array_key_exists('views', $data)) {
                $storedViews = array_column($stored['views'] ?? [], null, 'id');
                $settings['views'] = [];
                foreach ($data['views'] as $view) {
                    $previous = $storedViews[$view['id']] ?? [];
                    $state = app(Query::class)->state($view['state'], $columns);
                    $previousSorts = $previous['state']['sorts'] ?? [];
                    // A hidden-only view is projected with no sorts. Do not save Query's
                    // generated fallback sort as a user change during an ordinary layout edit.
                    if ($previousSorts && ($view['state']['sorts'] ?? null) === []
                        && ! array_filter($previousSorts, fn ($sort) => isset($columns[$sort['id']]))) {
                        $state['sorts'] = [];
                    }
                    foreach (['sorts', 'filters'] as $key) {
                        $state[$key] = $this->retainUnavailable($previous['state'][$key] ?? [], $state[$key], $columns, fn ($entry) => $entry['id']);
                    }
                    $settings['views'][] = [
                        'id' => $view['id'],
                        'name' => $view['name'],
                        'columns' => $this->mergeColumns($previous['columns'] ?? [], $view['columns'], $columns, $defaults),
                        'state' => $state,
                    ];
                }
            }
            // Retained dormant entries still count toward the existing query/layout
            // bounds, so re-enabling their module cannot produce an unusable view.
            validator($settings, [
                'columns' => 'array|max:100',
                'widths' => 'array|max:100',
                'pinned' => 'array|max:100',
                'views' => 'array|max:20',
                'views.*.columns' => 'array|max:100',
                'views.*.state.sorts' => 'array|max:5',
                'views.*.state.filters' => 'array|max:20',
            ])->validate();
            $preferences['tables'][$table] = $settings;
            if (isset($data['timezone'])) {
                $preferences['timezone'] = $data['timezone'];
            }
            $user->forceFill(['display_preferences' => $preferences])->save();
            $r->user()->setAttribute('display_preferences', $preferences);
        });
    }

    /** @param array<string, Column> $available */
    private function mergeColumns(array $stored, array $incoming, array $available, array $defaults): array
    {
        // Query supplies visible defaults when every saved column is unavailable.
        // Preserve that dormant-only selection when the fallback is echoed unchanged.
        if ($stored && ! array_filter($stored, fn ($id) => isset($available[$id])) && $incoming === $defaults) {
            return $stored;
        }

        return $this->retainUnavailable($stored, $incoming, $available, fn ($id) => $id);
    }

    /**
     * Replace only currently available slots; unavailable entries come exclusively
     * from locked server storage and retain their order without reaching the client.
     *
     * @param  array<string, Column>  $available
     */
    private function retainUnavailable(array $stored, array $incoming, array $available, callable $id): array
    {
        $result = [];
        $next = 0;
        foreach ($stored as $entry) {
            if (! isset($available[$id($entry)])) {
                $result[] = $entry;
            } elseif (array_key_exists($next, $incoming)) {
                $result[] = $incoming[$next++];
            }
        }

        return [...$result, ...array_slice($incoming, $next)];
    }
}
