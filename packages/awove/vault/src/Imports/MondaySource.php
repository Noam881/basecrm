<?php

namespace Awove\Vault\Imports;

use App\Integrations\Monday\ReadOnlyClient;
use Awove\Vault\Schema;
use Illuminate\Http\Request;

/** Exactly one pinned node. Raw column JSON remains a string, never numerically decoded. */
class MondaySource
{
    private const QUERY = 'query BaseCrmVaultSource($ids:[ID!]!,$columns:[String!]!) { me { account { id } } version { value } '
        .'items(ids:$ids,limit:1,exclude_nonactive:false) { id name created_at updated_at creator { id name } board { id name columns(capabilities:[CALCULATED,VISIBILITY]) { id type title settings_str } } group { id title } parent_item { id } '
        .'column_values(ids:$columns,capabilities:[CALCULATED,VISIBILITY]) { id type text value column { title } ... on FormulaValue { display_value } ... on MirrorValue { display_value } } '
        .'updates(limit:100,page:1) { id item_id body created_at updated_at creator { id name } replies { id } assets { id } } } }';

    public function fetch(Request $request, MondayPlan $plan, array $node): array
    {
        $data = $this->query($request, self::QUERY, ['ids' => [$node['id']], 'columns' => array_column(array_filter($plan->columns($node), fn ($column) => $column['type'] !== 'name'), 'id')]);

        return $this->validate($plan, $node, $data);
    }

    /** @internal Fixed server-owned named queries only; never called with a caller-supplied query. */
    public function query(Request $request, string $query, array $variables): array
    {
        $window = ImportWindow::require($request);
        // The transport has an unchanged twenty-second deadline; do not start it at the grant boundary.
        abort_unless($window->expiresAt->greaterThan(now()->addSeconds(25)), 423);
        $token = app(MondayToken::class)->read();
        $oldToken = config('services.monday.token');
        $oldVersion = config('services.monday.api_version');
        try {
            config(['services.monday.token' => $token, 'services.monday.api_version' => ReadOnlyClient::API_VERSION]);
            $result = app(ReadOnlyClient::class)->query($query, $variables, 1048576);
        } finally {
            config(['services.monday.token' => $oldToken, 'services.monday.api_version' => $oldVersion]);
            sodium_memzero($token);
        }
        ImportWindow::require($request);
        abort_unless($result['ok'], ($result['error'] ?? null) === 'rate_limited' ? 429 : 503);

        return $result['data'];
    }

    public function validate(MondayPlan $plan, array $node, #[\SensitiveParameter] array $data): array
    {
        abort_unless(($data['me']['account']['id'] ?? null) === $plan->data['account_id'] && ($data['version']['value'] ?? null) === ReadOnlyClient::API_VERSION
            && is_array($data['items'] ?? null) && array_is_list($data['items']) && count($data['items']) === 1, 503);
        $item = $data['items'][0];
        abort_unless(is_array($item) && ($item['id'] ?? null) === $node['id'] && ($item['board']['id'] ?? null) === $node['board_id']
            && array_key_exists('parent_item', $item) && ($item['parent_item'] === null || is_array($item['parent_item']) && is_string($item['parent_item']['id'] ?? null))
            && ($item['parent_item']['id'] ?? null) === $node['parent_id']
            && is_string($item['name'] ?? null) && is_array($item['group'] ?? null) && is_string($item['group']['id'] ?? null)
            && is_string($item['group']['title'] ?? null) && is_array($item['column_values'] ?? null) && array_is_list($item['column_values'])
            && is_array($item['updates'] ?? null) && array_is_list($item['updates']) && count($item['updates']) < 100, 503);
        $this->attribution($item);
        $definitions = array_column($plan->columns($node), 'type', 'id');
        abort_unless(is_string($item['board']['name'] ?? null) && is_array($item['board']['columns'] ?? null) && array_is_list($item['board']['columns']), 503);
        $defined = [];
        foreach ($item['board']['columns'] as $column) {
            abort_unless(is_array($column) && is_string($column['id'] ?? null) && ! isset($defined[$column['id']])
                && isset($definitions[$column['id']]) && ($column['type'] ?? null) === $definitions[$column['id']]
                && is_string($column['title'] ?? null) && array_key_exists('settings_str', $column) && ($column['settings_str'] === null || is_string($column['settings_str'])), 503);
            abort_if(Schema::prohibited($column['id'], $column['title']), 422);
            $defined[$column['id']] = true;
        }
        abort_unless(count($defined) === count($definitions), 503);
        $expected = array_column(array_filter($plan->columns($node), fn ($column) => $column['type'] !== 'name'), 'type', 'id');
        $seen = [];
        foreach ($item['column_values'] as $column) {
            abort_unless(is_array($column) && is_string($column['id'] ?? null) && ! isset($seen[$column['id']])
                && isset($expected[$column['id']]) && ($column['type'] ?? null) === $expected[$column['id']]
                && array_key_exists('value', $column) && ($column['value'] === null || is_string($column['value']))
                && array_key_exists('text', $column) && ($column['text'] === null || is_string($column['text']))
                && is_string($column['column']['title'] ?? null), 503);
            if (in_array($column['type'], ['formula', 'mirror'], true)) {
                abort_unless(is_string($column['display_value'] ?? null), 503);
            }
            // A generic archival field must never bypass the native prohibited-label policy.
            abort_if(Schema::prohibited($column['id'], $column['column']['title']), 422);
            $seen[$column['id']] = true;
        }
        abort_unless(count($seen) === count($expected), 503);
        $updates = [];
        foreach ($item['updates'] as $update) {
            abort_unless(is_array($update) && is_string($update['id'] ?? null) && ! isset($updates['id:'.$update['id']])
                && ($update['item_id'] ?? null) === $node['id'] && is_string($update['body'] ?? null)
                && ($update['replies'] ?? null) === [] && ($update['assets'] ?? null) === [], 503);
            $this->attribution($update);
            $updates['id:'.$update['id']] = $update['id'];
        }
        $actual = array_values($updates);
        $expected = $node['update_ids'];
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        abort_unless($actual === $expected, 503);

        return $item;
    }

    private function attribution(array $row): void
    {
        foreach (['created_at', 'updated_at'] as $key) {
            abort_unless(array_key_exists($key, $row) && ($row[$key] === null || is_string($row[$key])
                && preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2}))?$/D', $row[$key])), 503);
        }
        abort_unless(array_key_exists('creator', $row) && ($row['creator'] === null || is_array($row['creator'])
            && is_string($row['creator']['id'] ?? null) && is_string($row['creator']['name'] ?? null)), 503);
    }
}
