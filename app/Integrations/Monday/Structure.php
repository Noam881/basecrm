<?php

namespace App\Integrations\Monday;

/** Active-item identity/hierarchy coverage only; no client values or credential contents. */
final class Structure
{
    private const ITEM_FIELDS = 'id state subitems { id state board { id } parent_item { id } }';

    public function __construct(private readonly ReadOnlyClient $client) {}

    public function enumerate(string $accountId, array $boardIds): array
    {
        $maximumPages = config('services.monday.structure_max_pages', 10000);
        if ($this->identifier($accountId) === null || $boardIds === [] || count($boardIds) > 100
            || ! array_is_list($boardIds) || ! is_int($maximumPages) || $maximumPages < 1 || $maximumPages > 10000) {
            return ['ok' => false, 'boards' => [], 'unresolved' => [['reason' => 'invalid_selection']]];
        }
        foreach ($boardIds as $id) {
            if (! is_string($id) || $this->identifier($id) === null) {
                return ['ok' => false, 'boards' => [], 'unresolved' => [['reason' => 'invalid_selection']]];
            }
        }

        $boards = $unresolved = [];
        foreach (array_values(array_unique($boardIds)) as $id) {
            $result = $this->board($accountId, $id, $maximumPages);
            if (! $result['ok']) {
                $unresolved[] = $result['unresolved'];

                continue;
            }
            $boards[] = $result['board'];
        }

        return ['ok' => $unresolved === [], 'boards' => $boards, 'unresolved' => $unresolved];
    }

    private function board(string $accountId, string $id, int $maximumPages): array
    {
        $initial = 'query BaseCrmStructurePage($ids: [ID!]!) { me { account { id } } version { value } '
            .'boards(ids: $ids, state: all) { id items_page(limit: 100) { cursor items { '.self::ITEM_FIELDS.' } } } }';
        $next = 'query BaseCrmStructureNext($cursor: String!) { me { account { id } } version { value } '
            .'next_items_page(cursor: $cursor, limit: 100) { cursor items { '.self::ITEM_FIELDS.' } } }';
        $parents = $children = $seen = $cursors = [];
        $active = 0;
        $cursor = null;
        $pages = 0;
        $failure = function (string $reason) use ($id, &$parents, &$children, &$active, &$pages): array {
            return ['ok' => false, 'unresolved' => ['board_id' => $id, 'reason' => $reason,
                'pages_completed' => $pages, 'observed_parent_item_ids' => $parents,
                'observed_subitems' => $children, 'observed_active_parent_count' => $active,
                'coverage' => 'incomplete', 'archived_coverage' => 'unresolved']];
        };
        while ($pages < $maximumPages) {
            $result = $this->client->query($pages === 0 ? $initial : $next,
                $pages === 0 ? ['ids' => [$id]] : ['cursor' => $cursor], 1048576);
            if (! $result['ok']) {
                return $failure($result['error']);
            }
            $data = $result['data'];
            if ($this->identifier($data['me']['account']['id'] ?? null) !== $accountId
                || ($data['version']['value'] ?? null) !== ReadOnlyClient::API_VERSION) {
                return $failure('account_or_version_mismatch');
            }
            if ($pages === 0) {
                $returned = $data['boards'] ?? null;
                if (! is_array($returned) || ! array_is_list($returned) || count($returned) !== 1
                    || $this->identifier($returned[0]['id'] ?? null) !== $id) {
                    return $failure('inaccessible_or_not_found');
                }
                $page = $returned[0]['items_page'] ?? null;
            } else {
                $page = $data['next_items_page'] ?? null;
            }
            $parsed = $this->page($page, $seen);
            if (! $parsed['ok']) {
                return $failure($parsed['error']);
            }
            $nextCursor = $parsed['cursor'];
            if ($nextCursor !== null) {
                $fingerprint = hash('sha256', $nextCursor);
                if (isset($cursors[$fingerprint])) {
                    return $failure('repeated_cursor');
                }
                $cursors[$fingerprint] = true;
                if ($parsed['parents'] === []) {
                    return $failure('empty_page_with_cursor');
                }
            }
            $parents = array_merge($parents, $parsed['parents']);
            $children = array_merge($children, $parsed['children']);
            $active += $parsed['active'];
            $seen = $parsed['seen'];
            $pages++;
            if ($nextCursor === null) {
                return ['ok' => true, 'board' => ['board_id' => $id, 'parent_item_ids' => $parents,
                    'subitems' => $children, 'active_parent_count' => $active,
                    'unique_subitem_count' => count($children), 'coverage' => 'active_only',
                    'archived_coverage' => 'unresolved']];
            }
            $cursor = $nextCursor;
        }

        return $failure('page_limit_reached');
    }

    private function page(mixed $page, array $seen): array
    {
        if (! is_array($page) || ! array_key_exists('cursor', $page) || ! is_array($page['items'] ?? null)
            || ! array_is_list($page['items']) || count($page['items']) > 100
            || ($page['cursor'] !== null && (! is_string($page['cursor']) || $page['cursor'] === '' || strlen($page['cursor']) > 4096))) {
            return ['ok' => false, 'error' => 'invalid_page'];
        }
        $parents = $children = [];
        $active = 0;
        foreach ($page['items'] as $item) {
            if (! is_array($item) || ($id = $this->identifier($item['id'] ?? null)) === null
                || ! $this->state($item['state'] ?? null) || ! is_array($item['subitems'] ?? null)
                || ! array_is_list($item['subitems'])) {
                return ['ok' => false, 'error' => 'invalid_item_structure'];
            }
            if (isset($seen['item:'.$id])) {
                return ['ok' => false, 'error' => 'duplicate_item_id'];
            }
            $seen['item:'.$id] = true;
            $parents[] = $id;
            $active += $item['state'] === 'active' ? 1 : 0;
            foreach ($item['subitems'] as $subitem) {
                if (! is_array($subitem) || ($childId = $this->identifier($subitem['id'] ?? null)) === null
                    || ($childBoard = $this->identifier($subitem['board']['id'] ?? null)) === null
                    || ($parent = $this->identifier($subitem['parent_item']['id'] ?? null)) === null
                    || ! $this->state($subitem['state'] ?? null)) {
                    return ['ok' => false, 'error' => 'invalid_subitem_structure'];
                }
                if (isset($seen['item:'.$childId])) {
                    return ['ok' => false, 'error' => 'duplicate_item_id'];
                }
                $seen['item:'.$childId] = true;
                $children[] = ['id' => $childId, 'board_id' => $childBoard, 'parent_item_id' => $parent, 'state' => $subitem['state']];
            }
        }

        return ['ok' => true, 'parents' => $parents, 'children' => $children, 'active' => $active,
            'seen' => $seen, 'cursor' => $page['cursor']];
    }

    private function identifier(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]{0,63}$/D', (string) $value)
            ? (string) $value : null;
    }

    private function state(mixed $state): bool
    {
        return in_array($state, ['active', 'archived', 'deleted'], true);
    }
}
