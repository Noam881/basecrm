<?php

namespace App\Integrations\Monday;

/** Definition-only inventory. Unknown source settings are never interpreted as empty data. */
final class Metadata
{
    /** @return array{ok: true, board: array}|array{ok: false, error: string, board_id?: string} */
    public function board(array $board, string $settingsField): array
    {
        $id = $this->identifier($board['id'] ?? null);
        try {
            if ($id === null || ! in_array($settingsField, ['settings', 'settings_str'], true)) {
                $this->invalid();
            }
            foreach (['name', 'state', 'items_count', 'columns'] as $required) {
                if (! array_key_exists($required, $board)) {
                    $this->invalid();
                }
            }
            $name = $this->text($board['name'], 512);
            if (! in_array($board['state'], ['active', 'archived', 'deleted'], true)
                || ! is_int($board['items_count']) || $board['items_count'] < 0
                || ! is_array($board['columns']) || ! array_is_list($board['columns']) || count($board['columns']) > 1000) {
                $this->invalid();
            }
            $result = ['id' => $id, 'name' => $name, 'state' => $board['state'], 'items_count' => $board['items_count']];
            foreach (['board_kind' => ['private', 'public', 'share'], 'hierarchy_type' => ['classic', 'multi_level'],
                'permissions' => ['assignee', 'collaborators', 'everyone', 'owners'], 'access_level' => ['edit', 'view']] as $field => $values) {
                if (array_key_exists($field, $board)) {
                    if ($field === 'hierarchy_type' && $board[$field] === null) {
                        $result[$field] = null;
                    } elseif (in_array($board[$field], $values, true)) {
                        $result[$field] = $board[$field];
                    } else {
                        $this->invalid();
                    }
                }
            }
            if (array_key_exists('workspace_id', $board)) {
                $workspace = $board['workspace_id'] === null ? null : $this->identifier($board['workspace_id']);
                if ($board['workspace_id'] !== null && $workspace === null) {
                    $this->invalid();
                }
                $result['workspace_id'] = $workspace;
            }
            if (array_key_exists('updated_at', $board)) {
                $result['updated_at'] = $board['updated_at'] === null ? null : $this->text($board['updated_at'], 80);
            }
            if (array_key_exists('owners', $board)) {
                $result['owners'] = [];
                foreach ($this->list($board['owners'], 1000) as $owner) {
                    $ownerId = is_array($owner) ? $this->identifier($owner['id'] ?? null) : null;
                    if ($ownerId === null) {
                        $this->invalid();
                    }
                    $result['owners'][] = ['id' => $ownerId];
                }
            }
            if (array_key_exists('groups', $board)) {
                $result['groups'] = [];
                foreach ($this->list($board['groups'], 10000) as $group) {
                    if (! is_array($group)) {
                        $this->invalid();
                    }
                    $result['groups'][] = ['id' => $this->text($group['id'] ?? null, 160), 'title' => $this->text($group['title'] ?? null, 512)];
                }
            }
            $result['columns'] = $related = $seen = [];
            foreach ($board['columns'] as $column) {
                $clean = $this->column($column, $settingsField);
                if (in_array($clean['id'], $seen, true)) {
                    $this->invalid();
                }
                $seen[] = $clean['id'];
                $result['columns'][] = $clean;
                $related = [...$related, ...$clean['related_board_ids']];
            }
            $result['related_board_ids'] = array_values(array_unique($related, SORT_STRING));
            $result['columns_coverage'] = 'as_returned';
            $result['archive_item_coverage'] = 'unknown';

            return ['ok' => true, 'board' => $result];
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'invalid_metadata', ...($id !== null ? ['board_id' => $id] : [])];
        }
    }

    private function column(mixed $column, string $settingsField): array
    {
        if (! is_array($column) || ! is_string($column['type'] ?? null)
            || ! preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $column['type'])
            || (isset($column['archived']) && ! is_bool($column['archived']))) {
            $this->invalid();
        }
        $clean = ['id' => $this->text($column['id'] ?? null, 160), 'title' => $this->text($column['title'] ?? null, 512),
            'type' => $column['type'], 'archived' => $column['archived'] ?? null,
            'related_board_ids' => [], 'label_options' => [], 'settings_coverage' => 'not_inspected'];
        if (! in_array($column['type'], ['board_relation', 'subtasks', 'status', 'dropdown'], true)) {
            return $clean;
        }
        $clean['settings_coverage'] = 'unresolved';
        try {
            $settings = $column[$settingsField] ?? null;
            if (is_string($settings)) {
                if (strlen($settings) > 131072) {
                    return $clean;
                }
                $settings = json_decode($settings, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            }
            if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
                return $clean;
            }
            if (in_array($column['type'], ['board_relation', 'subtasks'], true)) {
                $ids = [];
                foreach ($this->list($settings['boardIds'] ?? null, 1000) as $related) {
                    $relatedId = $this->identifier($related);
                    if ($relatedId === null) {
                        $this->invalid();
                    }
                    $ids[] = $relatedId;
                }
                if (array_key_exists('allowMultipleItems', $settings) && ! is_bool($settings['allowMultipleItems'])) {
                    return $clean;
                }
                $clean['related_board_ids'] = array_values(array_unique($ids, SORT_STRING));
                $clean['allow_multiple_items'] = $settings['allowMultipleItems'] ?? null;
            } else {
                $clean['label_options'] = $this->labels($settings['labels'] ?? null);
            }
            $clean['settings_coverage'] = 'parsed';
        } catch (\Throwable) {
            // Do not return source JSON, exception messages or a partial label/relation interpretation.
        }

        return $clean;
    }

    /** @return list<array{id: string, label: string}> */
    private function labels(mixed $labels): array
    {
        if (! is_array($labels) || count($labels) > 1000) {
            $this->invalid();
        }
        $result = $seen = [];
        foreach ($labels as $key => $value) {
            if (is_string($value)) {
                $id = $this->identifier($key, true);
                $label = $value;
            } elseif (array_is_list($labels) && is_array($value)) {
                $id = $this->identifier($value['id'] ?? null, true);
                $label = $value['name'] ?? $value['label'] ?? null;
                if (array_key_exists('name', $value) && array_key_exists('label', $value) && $value['name'] !== $value['label']) {
                    $this->invalid();
                }
            } else {
                $this->invalid();
            }
            if ($id === null || in_array($id, $seen, true)) {
                $this->invalid();
            }
            $seen[] = $id;
            $result[] = ['id' => $id, 'label' => $this->text($label, 512, allowBlank: true)];
        }

        return $result;
    }

    private function identifier(mixed $value, bool $allowZero = false): ?string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/^[0-9]{1,64}$/D', (string) $value)) {
            return null;
        }

        return $allowZero || trim((string) $value, '0') !== '' ? (string) $value : null;
    }

    private function text(mixed $value, int $maximum, bool $allowBlank = false): string
    {
        if (! is_string($value) || (! $allowBlank && trim($value) === '') || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x{007F}-\x{009F}]/u', $value)) {
            $this->invalid();
        }

        return $value;
    }

    private function list(mixed $value, int $maximum): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximum) {
            $this->invalid();
        }

        return $value;
    }

    private function invalid(): never
    {
        throw new \UnexpectedValueException('invalid_metadata');
    }
}
