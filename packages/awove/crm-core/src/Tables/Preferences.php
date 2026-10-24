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
        $columns = $catalog->columns($table, $r->user());
        $keys = array_keys($columns);
        $data = $r->validate(['columns' => 'required|array|min:1|max:100', 'columns.*' => ['required', 'distinct', Rule::in($keys)], 'widths' => 'sometimes|array|max:100', 'widths.*' => 'integer|min:100|max:600', 'pinned' => 'sometimes|array|max:100', 'pinned.*' => ['required', 'distinct', Rule::in($keys)], 'density' => ['sometimes', Rule::in(['comfortable', 'compact'])], 'wrap' => 'sometimes|boolean', 'views' => 'sometimes|array|max:20', 'views.*.id' => 'required|string|max:80', 'views.*.name' => 'required|string|max:60', 'views.*.columns' => 'required|array|min:1|max:100', 'views.*.columns.*' => ['required', 'distinct', Rule::in($keys)], 'views.*.state' => 'required|array', 'timezone' => 'sometimes|timezone']);
        $views = [];
        foreach ($data['views'] ?? [] as $v) {
            $views[] = ['id' => $v['id'], 'name' => $v['name'], 'columns' => $v['columns'], 'state' => app(Query::class)->state($v['state'], $columns)];
        }
        $settings = ['columns' => $data['columns'], 'widths' => array_intersect_key($data['widths'] ?? [], $columns), 'pinned' => $data['pinned'] ?? [], 'density' => $data['density'] ?? 'comfortable', 'wrap' => $data['wrap'] ?? false, 'views' => $views];
        DB::transaction(function () use ($r, $table, $settings, $data) {
            $user = config('auth.providers.users.model')::lockForUpdate()->findOrFail($r->user()->id);
            $preferences = $user->display_preferences ?? [];
            $preferences['tables'][$table] = $settings;
            if (isset($data['timezone'])) {
                $preferences['timezone'] = $data['timezone'];
            }$user->forceFill(['display_preferences' => $preferences])->save();
            $r->user()->setAttribute('display_preferences', $preferences);
        });
    }
}
