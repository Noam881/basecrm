<?php

namespace App\Integrations\Monday;

/** Definition-only discovery. Item values, updates and file bodies are separate checkpoints. */
final class Inventory
{
    public function __construct(private readonly ReadOnlyClient $client) {}

    public function schema(string $accountId): array
    {
        $type = 'kind name ofType { kind name ofType { kind name ofType { kind name } } }';
        $fields = 'name fields(includeDeprecated: true) { name type { '.$type.' } args { name type { '.$type.' } } }';
        $query = 'query BaseCrmMigrationSchema { me { account { id } } version { value } '
            .'api: __schema { queryType { '.$fields.' } } '
            .'board: __type(name: "Board") { '.$fields.' } '
            .'column: __type(name: "Column") { '.$fields.' } '
            .'item: __type(name: "Item") { '.$fields.' } '
            .'relation: __type(name: "BoardRelationValue") { '.$fields.' } '
            .'capability: __type(name: "ColumnCapability") { enumValues { name } } '
            .'state: __type(name: "State") { enumValues { name } } }';
        $result = $this->client->query($query, [], 1048576);
        if (! $result['ok']) {
            return $result;
        }
        $data = $result['data'];
        if (! $this->sameAccount($data, $accountId)) {
            return ['ok' => false, 'error' => 'account_or_version_mismatch'];
        }
        $contract = [];
        foreach (['query' => $data['api']['queryType'] ?? null, 'board' => $data['board'] ?? null,
            'column' => $data['column'] ?? null, 'item' => $data['item'] ?? null] as $key => $definition) {
            if (! is_array($definition) || ! is_array($definition['fields'] ?? null)) {
                return ['ok' => false, 'error' => 'schema_unavailable'];
            }
            $contract[$key] = array_column($definition['fields'], null, 'name');
        }
        $contract['state_values'] = array_column($data['state']['enumValues'] ?? [], 'name');
        $contract['relation'] = array_column($data['relation']['fields'] ?? [], null, 'name');
        $contract['column_capabilities'] = array_column($data['capability']['enumValues'] ?? [], 'name');

        return ['ok' => true, 'contract' => $contract];
    }

    public function boards(string $accountId, array $ids, array $contract): array
    {
        if (! preg_match('/^[1-9][0-9]{0,63}$/D', $accountId) || $ids === [] || count($ids) > 100) {
            return ['ok' => false, 'error' => 'invalid_selection'];
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/^[1-9][0-9]{0,63}$/D', $id)) {
                return ['ok' => false, 'error' => 'invalid_selection'];
            }
        }
        foreach (['id', 'name', 'state', 'items_count', 'columns'] as $required) {
            if (! isset($contract['board'][$required])) {
                return ['ok' => false, 'error' => 'schema_incompatible'];
            }
        }
        foreach (['id', 'title', 'type'] as $required) {
            if (! isset($contract['column'][$required])) {
                return ['ok' => false, 'error' => 'schema_incompatible'];
            }
        }
        $arguments = array_column($contract['query']['boards']['args'] ?? [], 'name');
        if (! in_array('ids', $arguments, true) || ! in_array('state', $arguments, true)
            || ! in_array('all', $contract['state_values'] ?? [], true)) {
            return ['ok' => false, 'error' => 'schema_incompatible'];
        }
        $settingsField = isset($contract['column']['settings']) ? 'settings'
            : (isset($contract['column']['settings_str']) ? 'settings_str' : '');
        $columnFields = 'id title type'.(isset($contract['column']['archived']) ? ' archived' : '')
            .($settingsField !== '' ? ' '.$settingsField : '');
        $columnSelector = 'columns';
        $capabilities = $contract['column_capabilities'] ?? [];
        $columnArguments = array_column($contract['board']['columns']['args'] ?? [], 'name');
        if (in_array('capabilities', $columnArguments, true) && $capabilities !== []) {
            foreach ($capabilities as $capability) {
                if (! is_string($capability) || ! preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/D', $capability)) {
                    return ['ok' => false, 'error' => 'schema_incompatible'];
                }
            }
            $columnSelector .= '(capabilities: [null, '.implode(', ', $capabilities).'])';
        }
        $boardFields = 'id name state items_count '.$columnSelector.' { '.$columnFields.' }';
        foreach (['board_kind', 'hierarchy_type', 'permissions', 'access_level', 'workspace_id', 'updated_at'] as $field) {
            if (isset($contract['board'][$field])) {
                $boardFields .= ' '.$field;
            }
        }
        if (isset($contract['board']['owners'])) {
            $boardFields .= ' owners { id }';
        }
        if (isset($contract['board']['groups'])) {
            $boardFields .= ' groups { id title }';
        }
        $query = 'query BaseCrmBoardInventory($ids: [ID!]!) { me { account { id } } version { value } '
            .'boards(ids: $ids, state: all) { '.$boardFields.' } }';
        $boards = [];
        $unresolved = [];
        foreach (array_values(array_unique($ids)) as $id) {
            $result = $this->client->query($query, ['ids' => [$id]], 1048576);
            if (! $result['ok']) {
                $unresolved[] = ['board_id' => $id, 'reason' => $result['error']];

                continue;
            }
            if (! $this->sameAccount($result['data'], $accountId)) {
                $unresolved[] = ['board_id' => $id, 'reason' => 'account_or_version_mismatch'];

                continue;
            }
            $returned = $result['data']['boards'] ?? null;
            if (! is_array($returned) || ! array_is_list($returned) || count($returned) !== 1
                || ! is_array($returned[0]) || (string) ($returned[0]['id'] ?? '') !== $id) {
                $unresolved[] = ['board_id' => $id, 'reason' => 'inaccessible_or_not_found'];

                continue;
            }
            $parsed = (new Metadata)->board($returned[0], $settingsField);
            if (! $parsed['ok']) {
                $unresolved[] = ['board_id' => $id, 'reason' => $parsed['error']];

                continue;
            }
            $parsed['board']['column_capability_filter'] = $columnSelector === 'columns' ? 'default' : 'all_schema_capabilities';
            $boards[] = $parsed['board'];
        }

        return ['ok' => $unresolved === [], 'boards' => $boards, 'unresolved' => $unresolved,
            'selected_board_ids' => array_values(array_unique($ids)), 'settings_field' => $settingsField,
            'item_values_read' => false, 'archived_items_coverage' => 'unresolved'];
    }

    /** Names/counts only, so an out-of-list dependency can be discussed before extracting it. */
    public function references(string $accountId, array $ids): array
    {
        $boards = $unresolved = [];
        if ($ids === [] || count($ids) > 100) {
            return ['ok' => false, 'error' => 'invalid_selection'];
        }
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/^[1-9][0-9]{0,63}$/D', $id)) {
                return ['ok' => false, 'error' => 'invalid_selection'];
            }
        }
        $query = 'query BaseCrmBoardReferences($ids: [ID!]!) { me { account { id } } version { value } '
            .'boards(ids: $ids, state: all) { id name state items_count } }';
        foreach (array_values(array_unique($ids)) as $id) {
            $result = $this->client->query($query, ['ids' => [$id]]);
            if (! $result['ok']) {
                $unresolved[] = ['board_id' => $id, 'reason' => $result['error']];

                continue;
            }
            $returned = $result['data']['boards'] ?? null;
            if (! $this->sameAccount($result['data'], $accountId) || ! is_array($returned) || ! array_is_list($returned)
                || count($returned) !== 1 || ! is_array($returned[0]) || (string) ($returned[0]['id'] ?? '') !== $id) {
                $unresolved[] = ['board_id' => $id, 'reason' => 'unresolved_reference'];

                continue;
            }
            $board = $returned[0];
            if (! is_string($board['name'] ?? null) || trim($board['name']) === '' || mb_strlen($board['name']) > 512
                || preg_match('/[\x00-\x1F\x7F]/u', $board['name'])
                || ! in_array($board['state'] ?? null, ['active', 'archived', 'deleted'], true)
                || ! is_int($board['items_count'] ?? null) || $board['items_count'] < 0) {
                $unresolved[] = ['board_id' => $id, 'reason' => 'invalid_metadata'];

                continue;
            }
            $boards[] = ['id' => $id, 'name' => $board['name'], 'state' => $board['state'], 'items_count' => $board['items_count']];
        }

        return ['ok' => $unresolved === [], 'boards' => $boards, 'unresolved' => $unresolved,
            'item_values_read' => false, 'scope' => 'dependency_names_only'];
    }

    private function sameAccount(array $data, string $accountId): bool
    {
        $me = $data['me'] ?? null;
        $account = is_array($me) ? ($me['account'] ?? null) : null;
        $id = is_array($account) ? ($account['id'] ?? null) : null;
        $version = $data['version'] ?? null;

        return (is_string($id) || is_int($id)) && (string) $id === $accountId
            && is_array($version) && ($version['value'] ?? null) === ReadOnlyClient::API_VERSION;
    }
}
