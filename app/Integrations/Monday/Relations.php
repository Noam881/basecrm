<?php

namespace App\Integrations\Monday;

/** Source-ID edges only; callers supply scoped items and verified board-relation columns. */
final class Relations
{
    private const QUERY = 'query BaseCrmRelationEdges($ids: [ID!]!, $columns: [String!]!) { '
        .'me { account { id } } version { value } '
        .'items(ids: $ids, limit: 100, exclude_nonactive: false) { id board { id } '
        .'column_values(ids: $columns) { id __typename ... on BoardRelationValue { '
        .'linked_item_ids linked_items { id board { id } } } } } }';

    public function __construct(private readonly ReadOnlyClient $client) {}

    public function read(string $accountId, string $boardId, array $itemIds, array $columnIds): array
    {
        if ($this->identifier($accountId) === null || $this->identifier($boardId) === null
            || ! array_is_list($itemIds) || count($itemIds) > 100000 || ! array_is_list($columnIds)
            || $columnIds === [] || count($columnIds) > 100) {
            return ['ok' => false, 'edges' => [], 'unresolved' => [['reason' => 'invalid_selection']]];
        }
        $ids = [];
        foreach ($itemIds as $id) {
            if (($parsed = $this->identifier($id)) === null) {
                return ['ok' => false, 'edges' => [], 'unresolved' => [['reason' => 'invalid_selection']]];
            }
            $ids['item:'.$parsed] = $parsed;
        }
        foreach ($columnIds as $column) {
            if (! is_string($column) || $column === '' || strlen($column) > 255 || ! mb_check_encoding($column, 'UTF-8')
                || preg_match('/[\x00-\x1F\x7F]/u', $column)) {
                return ['ok' => false, 'edges' => [], 'unresolved' => [['reason' => 'invalid_selection']]];
            }
        }
        $columns = array_values(array_unique($columnIds));
        $edges = $unresolved = [];
        $limited = false;
        foreach (array_chunk(array_values($ids), 100) as $batch) {
            if ($limited) {
                $this->unresolvedBatch($unresolved, $batch, $boardId, 'deferred_after_rate_limit');

                continue;
            }
            $result = $this->client->query(self::QUERY, ['ids' => $batch, 'columns' => $columns], 1048576);
            if (! $result['ok']) {
                $this->unresolvedBatch($unresolved, $batch, $boardId, $result['error']);
                $limited = $result['error'] === 'rate_limited';

                continue;
            }
            $data = $result['data'];
            if ($this->identifier($data['me']['account']['id'] ?? null) !== $accountId
                || ($data['version']['value'] ?? null) !== ReadOnlyClient::API_VERSION) {
                $this->unresolvedBatch($unresolved, $batch, $boardId, 'account_or_version_mismatch');

                continue;
            }
            $items = $data['items'] ?? null;
            if (! is_array($items) || ! array_is_list($items) || count($items) > 100) {
                $this->unresolvedBatch($unresolved, $batch, $boardId, 'invalid_items_response');

                continue;
            }
            $seen = [];
            foreach ($items as $item) {
                $id = is_array($item) ? $this->identifier($item['id'] ?? null) : null;
                if ($id === null || ! in_array($id, $batch, true)) {
                    $unresolved[] = ['board_id' => $boardId, 'reason' => 'unexpected_or_invalid_item'];

                    continue;
                }
                $context = ['board_id' => $boardId, 'item_id' => $id];
                if (isset($seen['item:'.$id])) {
                    $unresolved[] = [...$context, 'reason' => 'duplicate_item'];
                }
                $seen['item:'.$id] = true;
                if ($this->identifier($item['board']['id'] ?? null) !== $boardId) {
                    $unresolved[] = [...$context, 'reason' => 'wrong_board'];

                    continue;
                }
                $values = $item['column_values'] ?? null;
                if (! is_array($values) || ! array_is_list($values)) {
                    $unresolved[] = [...$context, 'reason' => 'relation_columns_unavailable'];

                    continue;
                }
                $seenColumns = [];
                foreach ($values as $value) {
                    $column = is_array($value) ? ($value['id'] ?? null) : null;
                    if (! is_string($column) || ! in_array($column, $columns, true)) {
                        $unresolved[] = [...$context, 'reason' => 'unexpected_relation_column'];

                        continue;
                    }
                    $columnContext = [...$context, 'column_id' => $column];
                    if (isset($seenColumns[$column])) {
                        $unresolved[] = [...$columnContext, 'reason' => 'duplicate_relation_column'];
                    }
                    $seenColumns[$column] = true;
                    if (($value['__typename'] ?? null) !== 'BoardRelationValue') {
                        $unresolved[] = [...$columnContext, 'reason' => 'relation_type_mismatch'];

                        continue;
                    }
                    $parsed = $this->relation($value, $columnContext);
                    $unresolved = array_merge($unresolved, $parsed['unresolved']);
                    foreach ($parsed['edges'] as $edge) {
                        $key = json_encode([$id, $column, $edge['to_item_id']], JSON_THROW_ON_ERROR);
                        if (isset($edges[$key]) && ($edges[$key]['to_board_id'] ?? null) !== ($edge['to_board_id'] ?? null)) {
                            unset($edges[$key]['to_board_id']);
                            $unresolved[] = [...$columnContext, 'to_item_id' => $edge['to_item_id'], 'reason' => 'conflicting_linked_board'];
                        } else {
                            $edges[$key] ??= $edge;
                        }
                    }
                }
                foreach ($columns as $column) {
                    if (! isset($seenColumns[$column])) {
                        $unresolved[] = [...$context, 'column_id' => $column, 'reason' => 'relation_column_missing'];
                    }
                }
            }
            foreach ($batch as $id) {
                if (! isset($seen['item:'.$id])) {
                    $unresolved[] = ['board_id' => $boardId, 'item_id' => $id, 'reason' => 'item_missing_or_inaccessible'];
                }
            }
        }

        return ['ok' => $unresolved === [], 'edges' => array_values($edges), 'unresolved' => $unresolved];
    }

    private function relation(array $value, array $context): array
    {
        $known = $declared = $details = $boards = $unresolved = [];
        if (! is_array($value['linked_item_ids'] ?? null) || ! array_is_list($value['linked_item_ids'])) {
            $unresolved[] = [...$context, 'reason' => 'linked_item_ids_unavailable'];
        } else {
            foreach ($value['linked_item_ids'] as $raw) {
                if (($id = $this->identifier($raw)) === null) {
                    $unresolved[] = [...$context, 'reason' => 'invalid_linked_item_id'];

                    continue;
                }
                $key = 'item:'.$id;
                if (isset($declared[$key])) {
                    $unresolved[] = [...$context, 'to_item_id' => $id, 'reason' => 'duplicate_linked_item_id'];
                }
                $declared[$key] = true;
                $known[$key] = $id;
            }
        }
        if (! is_array($value['linked_items'] ?? null) || ! array_is_list($value['linked_items'])) {
            $unresolved[] = [...$context, 'reason' => 'linked_items_unavailable'];
        } else {
            foreach ($value['linked_items'] as $item) {
                $id = is_array($item) ? $this->identifier($item['id'] ?? null) : null;
                if ($id === null) {
                    $unresolved[] = [...$context, 'reason' => 'invalid_linked_item'];

                    continue;
                }
                $key = 'item:'.$id;
                $known[$key] = $id;
                if (isset($details[$key])) {
                    $unresolved[] = [...$context, 'to_item_id' => $id, 'reason' => 'duplicate_linked_item'];
                    $boards[$key] = null;
                } else {
                    $boards[$key] = $this->identifier($item['board']['id'] ?? null);
                }
                $details[$key] = true;
            }
        }
        $edges = [];
        foreach ($known as $key => $id) {
            $edge = ['from_item_id' => $context['item_id'], 'column_id' => $context['column_id'], 'to_item_id' => $id];
            if (! isset($declared[$key])) {
                $unresolved[] = [...$context, 'to_item_id' => $id, 'reason' => 'linked_item_ids_mismatch'];
            }
            if (! isset($details[$key])) {
                $unresolved[] = [...$context, 'to_item_id' => $id, 'reason' => 'linked_item_inaccessible'];
            } elseif (($boards[$key] ?? null) === null) {
                $unresolved[] = [...$context, 'to_item_id' => $id, 'reason' => 'linked_board_unavailable'];
            } else {
                $edge['to_board_id'] = $boards[$key];
            }
            $edges[] = $edge;
        }

        return ['edges' => $edges, 'unresolved' => $unresolved];
    }

    private function unresolvedBatch(array &$unresolved, array $ids, string $board, string $reason): void
    {
        foreach ($ids as $id) {
            $unresolved[] = ['board_id' => $board, 'item_id' => $id, 'reason' => $reason];
        }
    }

    private function identifier(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]{0,63}$/D', (string) $value)
            ? (string) $value : null;
    }
}
