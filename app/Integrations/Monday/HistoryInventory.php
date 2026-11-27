<?php

namespace App\Integrations\Monday;

/** Scoped history/file relationship identifiers only; never reads bodies or file contents. */
final class HistoryInventory
{
    private const UPDATES = 'query BaseCrmHistoryIds($ids: [ID!]!, $page: Int!) { '
        .'me { account { id } } version { value } '
        .'items(ids: $ids, limit: 1, exclude_nonactive: false) { id board { id } '
        .'updates(limit: 100, page: $page) { id item_id assets { id } replies { id assets { id } } } } }';

    private const FILES = 'query BaseCrmFileReferenceIds($ids: [ID!]!, $columns: [String!]!) { '
        .'me { account { id } } version { value } '
        .'items(ids: $ids, limit: 100, exclude_nonactive: false) { id board { id } '
        .'column_values(ids: $columns) { id __typename ... on FileValue { files { __typename '
        .'... on FileAssetValue { asset_id } '
        .'... on FileDocValue { file_id object_id doc { id } } '
        .'... on FileLinkValue { file_id kind } '
        .'... on FileAssetInvalidValue { asset_id } } } } } }';

    private const UNINSPECTED = ['item_assets_default_scope', 'activity_log_history', 'board_level_updates', 'update_and_reply_bodies',
        'file_bytes', 'workdoc_contents', 'external_provider_contents'];

    public function __construct(private readonly ReadOnlyClient $client) {}

    public function updates(string $accountId, string $boardId, array $itemIds): array
    {
        $ids = $this->selection($accountId, $boardId, $itemIds);
        $maximum = config('services.monday.history_max_pages', 10000);
        if ($ids === null || ! is_int($maximum) || $maximum < 1 || $maximum > 10000) {
            return $this->invalid();
        }
        $items = $unresolved = [];
        $limited = false;
        $globalUpdates = $globalReplies = [];
        foreach ($ids as $id) {
            $item = $this->historyItem($boardId, $id);
            $issues = [];
            if ($limited) {
                $issues[] = $this->issue($boardId, $id, 'deferred_after_rate_limit');
            } else {
                $seenUpdates = $seenPages = [];
                for ($page = 1; $page <= $maximum; $page++) {
                    $result = $this->client->query(self::UPDATES, ['ids' => [$id], 'page' => $page], 1048576);
                    if (! $result['ok']) {
                        $issues[] = $this->issue($boardId, $id, $result['error'], ['page' => $page]);
                        $limited = $result['error'] === 'rate_limited';
                        break;
                    }
                    $source = $this->singleItem($result['data'], $accountId, $boardId, $id);
                    if (! $source['ok']) {
                        $issues[] = $this->issue($boardId, $id, $source['error'], ['page' => $page]);
                        break;
                    }
                    $updates = $source['item']['updates'] ?? null;
                    if (! is_array($updates) || ! array_is_list($updates) || count($updates) > 100) {
                        $issues[] = $this->issue($boardId, $id, 'updates_unavailable', ['page' => $page]);
                        break;
                    }
                    $item['pages_read']++;
                    if ($updates === []) {
                        $item['pagination_complete'] = true;
                        break;
                    }
                    $pageIds = [];
                    foreach ($updates as $update) {
                        $updateId = is_array($update) ? $this->identifier($update['id'] ?? null) : null;
                        if ($updateId === null || $this->identifier($update['item_id'] ?? null) !== $id) {
                            $issues[] = $this->issue($boardId, $id, 'invalid_update_parent_or_id', ['page' => $page]);
                            break 2;
                        }
                        $pageIds[] = $updateId;
                    }
                    $fingerprint = hash('sha256', json_encode($pageIds, JSON_THROW_ON_ERROR));
                    if (isset($seenPages[$fingerprint])) {
                        $issues[] = $this->issue($boardId, $id, 'repeated_update_page', ['page' => $page]);
                        break;
                    }
                    $seenPages[$fingerprint] = true;
                    $pageSeen = [];
                    foreach ($pageIds as $updateId) {
                        if (isset($seenUpdates['update:'.$updateId]) || isset($pageSeen['update:'.$updateId])) {
                            $issues[] = $this->issue($boardId, $id, 'duplicate_update_id', ['page' => $page, 'update_id' => $updateId]);
                            break 2;
                        }
                        if (isset($globalUpdates['update:'.$updateId]) && $globalUpdates['update:'.$updateId] !== $id) {
                            $priorId = $globalUpdates['update:'.$updateId];
                            $issues[] = $this->issue($boardId, $id, 'update_belongs_to_multiple_items', ['page' => $page,
                                'update_id' => $updateId, 'previous_item_id' => $priorId]);
                            $this->invalidatePrior($items, $unresolved, $boardId, $priorId, 'update_belongs_to_multiple_items',
                                ['update_id' => $updateId, 'other_item_id' => $id]);
                            break 2;
                        }
                        $pageSeen['update:'.$updateId] = true;
                    }
                    foreach ($updates as $update) {
                        $updateId = (string) $update['id'];
                        $seenUpdates['update:'.$updateId] = true;
                        $globalUpdates['update:'.$updateId] = $id;
                        $item['updates'][] = ['source_kind' => 'update', 'item_id' => $id, 'update_id' => $updateId];
                        $context = ['update_id' => $updateId];
                        $this->assets($item, $issues, $update['assets'] ?? null, 'update_asset', $context);
                        $replies = $update['replies'] ?? null;
                        if (! is_array($replies) || ! array_is_list($replies)) {
                            $issues[] = $this->issue($boardId, $id, 'replies_unavailable', $context);

                            continue;
                        }
                        foreach ($replies as $reply) {
                            $replyId = is_array($reply) ? $this->identifier($reply['id'] ?? null) : null;
                            if ($replyId === null) {
                                $issues[] = $this->issue($boardId, $id, 'invalid_reply_id', $context);

                                continue;
                            }
                            $replyContext = [...$context, 'reply_id' => $replyId];
                            if (isset($globalReplies['reply:'.$replyId])) {
                                $prior = $globalReplies['reply:'.$replyId];
                                $issues[] = $this->issue($boardId, $id, 'duplicate_reply_id', [...$replyContext,
                                    'previous_item_id' => $prior['item_id'], 'previous_update_id' => $prior['update_id']]);
                                if ($prior['item_id'] !== $id) {
                                    $this->invalidatePrior($items, $unresolved, $boardId, $prior['item_id'], 'duplicate_reply_id',
                                        ['reply_id' => $replyId, 'update_id' => $prior['update_id'], 'other_item_id' => $id]);
                                }

                                continue;
                            }
                            $globalReplies['reply:'.$replyId] = ['item_id' => $id, 'update_id' => $updateId];
                            $item['replies'][] = ['source_kind' => 'reply', 'item_id' => $id, ...$replyContext];
                            $this->assets($item, $issues, $reply['assets'] ?? null, 'reply_asset', $replyContext);
                        }
                    }
                }
                if (! $item['pagination_complete'] && $page > $maximum) {
                    $issues[] = $this->issue($boardId, $id, 'history_page_limit_reached');
                }
            }
            $item['coverage'] = $item['pagination_complete'] && $issues === [] ? 'complete' : 'incomplete';
            $item['counts'] = ['updates' => count($item['updates']), 'replies' => count($item['replies']),
                'asset_relationships' => count($item['attachments']),
                'unique_assets' => count(array_unique(array_column($item['attachments'], 'asset_id')))];
            $items[] = $item;
            $unresolved = array_merge($unresolved, $issues);
        }

        return ['ok' => $unresolved === [], 'items' => $items, 'unresolved' => $unresolved,
            'scope' => 'selected_item_update_reply_attachment_ids', 'coverage_basis' => 'requested_projection_only',
            'uninspected_locations' => self::UNINSPECTED];
    }

    public function files(string $accountId, string $boardId, array $itemIds, array $fileColumnIds): array
    {
        $ids = $this->selection($accountId, $boardId, $itemIds);
        if ($ids === null || ! array_is_list($fileColumnIds) || count($fileColumnIds) > 100) {
            return $this->invalid();
        }
        foreach ($fileColumnIds as $column) {
            if (! is_string($column) || $column === '' || strlen($column) > 255 || ! mb_check_encoding($column, 'UTF-8')
                || preg_match('/[\x00-\x1F\x7F]/u', $column)) {
                return $this->invalid();
            }
        }
        $columns = array_values(array_unique($fileColumnIds));
        $items = $unresolved = [];
        foreach ($ids as $id) {
            $items['item:'.$id] = ['board_id' => $boardId, 'item_id' => $id, 'files' => [],
                'coverage' => $columns === [] ? 'not_requested' : 'incomplete'];
        }
        $limited = false;
        foreach ($columns === [] ? [] : array_chunk($ids, 100) as $batch) {
            if ($limited) {
                foreach ($batch as $id) {
                    $unresolved[] = $this->issue($boardId, $id, 'deferred_after_rate_limit');
                }

                continue;
            }
            $result = $this->client->query(self::FILES, ['ids' => $batch, 'columns' => $columns], 1048576);
            if (! $result['ok'] || ! $this->sameAccount($result['data'], $accountId)) {
                $reason = $result['ok'] ? 'account_or_version_mismatch' : $result['error'];
                foreach ($batch as $id) {
                    $unresolved[] = $this->issue($boardId, $id, $reason);
                }
                $limited = $reason === 'rate_limited';

                continue;
            }
            $returned = $result['data']['items'] ?? null;
            if (! is_array($returned) || ! array_is_list($returned) || count($returned) > 100) {
                foreach ($batch as $id) {
                    $unresolved[] = $this->issue($boardId, $id, 'items_unavailable');
                }

                continue;
            }
            $seenItems = [];
            foreach ($returned as $source) {
                $id = is_array($source) ? $this->identifier($source['id'] ?? null) : null;
                if ($id === null || ! in_array($id, $batch, true)) {
                    $unresolved[] = ['board_id' => $boardId, 'reason' => 'unexpected_or_invalid_item'];

                    continue;
                }
                $key = 'item:'.$id;
                if (isset($seenItems[$key])) {
                    $unresolved[] = $this->issue($boardId, $id, 'duplicate_item');
                    $items[$key]['coverage'] = 'incomplete';

                    continue;
                }
                $seenItems[$key] = true;
                if ($this->identifier($source['board']['id'] ?? null) !== $boardId) {
                    $unresolved[] = $this->issue($boardId, $id, 'wrong_board');

                    continue;
                }
                $values = $source['column_values'] ?? null;
                if (! is_array($values) || ! array_is_list($values)) {
                    $unresolved[] = $this->issue($boardId, $id, 'file_columns_unavailable');

                    continue;
                }
                $issues = $seenColumns = [];
                foreach ($values as $value) {
                    $column = is_array($value) ? ($value['id'] ?? null) : null;
                    if (! is_string($column) || ! in_array($column, $columns, true)) {
                        $issues[] = $this->issue($boardId, $id, 'unexpected_file_column');

                        continue;
                    }
                    if (isset($seenColumns[$column])) {
                        $issues[] = $this->issue($boardId, $id, 'duplicate_file_column', ['column_id' => $column]);

                        continue;
                    }
                    $seenColumns[$column] = true;
                    if (($value['__typename'] ?? null) !== 'FileValue' || ! is_array($value['files'] ?? null)
                        || ! array_is_list($value['files'])) {
                        $issues[] = $this->issue($boardId, $id, 'file_column_unavailable', ['column_id' => $column]);

                        continue;
                    }
                    $seenFiles = [];
                    foreach ($value['files'] as $index => $file) {
                        $parsed = $this->file($file, ['column_id' => $column, 'source_index' => $index]);
                        $reference = $parsed['file'];
                        $fileKey = json_encode(array_diff_key($reference, ['source_index' => true]), JSON_THROW_ON_ERROR);
                        if (isset($seenFiles[$fileKey])) {
                            $issues[] = $this->issue($boardId, $id, 'duplicate_file_reference', ['column_id' => $column, 'source_index' => $index]);
                        }
                        $seenFiles[$fileKey] = true;
                        $items[$key]['files'][] = $reference;
                        foreach ($parsed['reasons'] as $reason) {
                            $issues[] = $this->issue($boardId, $id, $reason, ['column_id' => $column, 'source_index' => $index]);
                        }
                    }
                }
                foreach ($columns as $column) {
                    if (! isset($seenColumns[$column])) {
                        $issues[] = $this->issue($boardId, $id, 'file_column_missing', ['column_id' => $column]);
                    }
                }
                $items[$key]['coverage'] = $issues === [] ? 'complete' : 'incomplete';
                $unresolved = array_merge($unresolved, $issues);
            }
            foreach ($batch as $id) {
                if (! isset($seenItems['item:'.$id])) {
                    $unresolved[] = $this->issue($boardId, $id, 'item_missing_or_inaccessible');
                }
            }
        }
        foreach ($items as &$item) {
            $kinds = array_count_values(array_column($item['files'], 'source_kind'));
            $item['counts'] = ['file_references' => count($item['files']), 'uploaded_assets' => $kinds['uploaded_asset'] ?? 0,
                'workdocs' => $kinds['workdoc'] ?? 0, 'external_links' => $kinds['external_link'] ?? 0,
                'invalid_assets' => $kinds['invalid_asset'] ?? 0];
        }
        unset($item);

        return ['ok' => $unresolved === [], 'items' => array_values($items), 'unresolved' => $unresolved,
            'scope' => 'explicit_file_column_reference_ids', 'coverage_basis' => 'requested_projection_only',
            'uninspected_locations' => self::UNINSPECTED];
    }

    private function assets(array &$item, array &$issues, mixed $assets, string $kind, array $context): void
    {
        if (! is_array($assets) || ! array_is_list($assets)) {
            $issues[] = $this->issue($item['board_id'], $item['item_id'], 'assets_unavailable', ['source_kind' => $kind, ...$context]);

            return;
        }
        $seen = [];
        foreach ($assets as $asset) {
            $id = is_array($asset) ? $this->identifier($asset['id'] ?? null) : null;
            if ($id === null) {
                $issues[] = $this->issue($item['board_id'], $item['item_id'], 'invalid_asset_id', ['source_kind' => $kind, ...$context]);

                continue;
            }
            if (isset($seen['asset:'.$id])) {
                $issues[] = $this->issue($item['board_id'], $item['item_id'], 'duplicate_asset_id', ['source_kind' => $kind, ...$context, 'asset_id' => $id]);

                continue;
            }
            $seen['asset:'.$id] = true;
            $item['attachments'][] = ['source_kind' => $kind, 'item_id' => $item['item_id'], ...$context, 'asset_id' => $id];
        }
    }

    private function file(mixed $value, array $context): array
    {
        $type = is_array($value) ? ($value['__typename'] ?? null) : null;
        $kind = match ($type) {
            'FileAssetValue' => 'uploaded_asset', 'FileDocValue' => 'workdoc',
            'FileLinkValue' => 'external_link', 'FileAssetInvalidValue' => 'invalid_asset', default => 'unsupported_file',
        };
        $file = ['source_kind' => $kind, ...$context];
        $reasons = [];
        $fields = match ($kind) {
            'uploaded_asset', 'invalid_asset' => ['asset_id'], 'workdoc' => ['file_id', 'object_id'],
            'external_link' => ['file_id'], default => [],
        };
        foreach ($fields as $field) {
            $id = $field === 'file_id' ? $this->fileIdentifier($value[$field] ?? null) : $this->identifier($value[$field] ?? null);
            if ($id === null) {
                $reasons[] = 'file_identifier_unavailable';
            } else {
                $file[$field] = $id;
            }
        }
        if ($kind === 'workdoc') {
            $id = $this->identifier($value['doc']['id'] ?? null);
            if ($id === null) {
                $reasons[] = 'workdoc_identifier_unavailable';
            } else {
                $file['doc_id'] = $id;
            }
        } elseif ($kind === 'external_link') {
            $linkKind = $value['kind'] ?? null;
            if (! in_array($linkKind, ['box', 'dropbox', 'google_drive', 'link', 'onedrive'], true)) {
                $reasons[] = 'external_link_kind_unavailable';
            } else {
                $file['kind'] = $linkKind;
            }
        } elseif ($kind === 'invalid_asset') {
            $reasons[] = 'invalid_source_asset';
        } elseif ($kind === 'unsupported_file') {
            $reasons[] = 'unsupported_file_kind';
        }

        return ['file' => $file, 'reasons' => array_values(array_unique($reasons))];
    }

    private function singleItem(array $data, string $account, string $board, string $id): array
    {
        if (! $this->sameAccount($data, $account)) {
            return ['ok' => false, 'error' => 'account_or_version_mismatch'];
        }
        $items = $data['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || count($items) !== 1 || ! is_array($items[0])
            || $this->identifier($items[0]['id'] ?? null) !== $id) {
            return ['ok' => false, 'error' => 'item_missing_or_out_of_scope'];
        }
        if ($this->identifier($items[0]['board']['id'] ?? null) !== $board) {
            return ['ok' => false, 'error' => 'wrong_board'];
        }

        return ['ok' => true, 'item' => $items[0]];
    }

    private function sameAccount(array $data, string $account): bool
    {
        return is_array($data['me'] ?? null) && is_array($data['me']['account'] ?? null) && is_array($data['version'] ?? null)
            && $this->identifier($data['me']['account']['id'] ?? null) === $account
            && ($data['version']['value'] ?? null) === ReadOnlyClient::API_VERSION;
    }

    private function invalidatePrior(array &$items, array &$issues, string $board, string $id, string $reason, array $context): void
    {
        foreach ($items as &$item) {
            if ($item['item_id'] === $id) {
                $item['coverage'] = 'incomplete';
            }
        }
        unset($item);
        $issues[] = $this->issue($board, $id, $reason, $context);
    }

    private function selection(string $account, string $board, array $ids): ?array
    {
        if ($this->identifier($account) === null || $this->identifier($board) === null || ! array_is_list($ids) || count($ids) > 100000) {
            return null;
        }
        $result = [];
        foreach ($ids as $id) {
            if (($parsed = $this->identifier($id)) === null) {
                return null;
            }
            $result['item:'.$parsed] = $parsed;
        }

        return array_values($result);
    }

    private function historyItem(string $board, string $id): array
    {
        return ['board_id' => $board, 'item_id' => $id, 'updates' => [], 'replies' => [], 'attachments' => [],
            'pages_read' => 0, 'pagination_complete' => false, 'coverage' => 'incomplete'];
    }

    private function identifier(mixed $id): ?string
    {
        return (is_string($id) || is_int($id)) && preg_match('/^[1-9][0-9]{0,63}$/D', (string) $id) ? (string) $id : null;
    }

    private function fileIdentifier(mixed $id): ?string
    {
        if (is_int($id)) {
            return $this->identifier($id);
        }

        return is_string($id) && trim($id) !== '' && strlen($id) <= 255 && mb_check_encoding($id, 'UTF-8')
            && ! preg_match('/[\x00-\x1F\x7F]/u', $id) ? $id : null;
    }

    private function issue(string $board, string $item, string $reason, array $context = []): array
    {
        return ['board_id' => $board, 'item_id' => $item, ...$context, 'reason' => $reason];
    }

    private function invalid(): array
    {
        return ['ok' => false, 'items' => [], 'unresolved' => [['reason' => 'invalid_selection']],
            'scope' => 'metadata_only', 'uninspected_locations' => self::UNINSPECTED];
    }
}
