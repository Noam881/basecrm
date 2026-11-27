<?php

namespace Awove\Vault\Imports;

use App\Integrations\Monday\ReadOnlyClient;
use Awove\Core\Imports\Metadata;
use Awove\Core\Installation;

/** Only deployment-owned IDs/definitions. No caller chooses source or target scope. */
final readonly class MondayPlan
{
    private function __construct(public array $data, public string $hash) {}

    public static function load(): self
    {
        $bytes = PrivateFile::read('vault.monday_plan_file', 2097152);
        $hash = hash('sha256', $bytes);
        abort_unless(hash_equals(Metadata::digest(config('vault.monday_plan_sha256')), $hash), 503);
        $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        abort_unless(is_array($data), 503);

        return self::validate($data, $hash);
    }

    public static function validate(array $data, string $hash): self
    {
        Metadata::exact($data, ['version', 'installation_id', 'boundary', 'run_id', 'account_id', 'api_version', 'code_revision', 'mapping_revision', 'company_mapping_id', 'boards', 'nodes']);
        abort_unless($data['version'] === 1 && $data['installation_id'] === Installation::manifest()['id']
            && $data['api_version'] === ReadOnlyClient::API_VERSION && is_int($data['company_mapping_id']) && $data['company_mapping_id'] > 0, 503);
        Metadata::digest($hash);
        Metadata::digest($data['boundary']);
        Metadata::digest($data['mapping_revision']);
        Metadata::uuid($data['run_id']);
        Metadata::opaque($data['account_id']);
        abort_unless(is_string($data['code_revision']) && preg_match('/^[a-f0-9]{40}$/D', $data['code_revision']), 503);
        $boards = [];
        foreach (self::rows($data['boards'], 50) as $board) {
            Metadata::exact($board, ['id', 'columns']);
            $id = Metadata::opaque($board['id']);
            abort_if(isset($boards[$id]), 503);
            $columns = [];
            foreach (self::rows($board['columns'], 200) as $column) {
                Metadata::exact($column, ['id', 'type']);
                $columnId = Metadata::opaque($column['id']);
                abort_unless(is_string($column['type']) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $column['type']) && ! isset($columns[$columnId]), 503);
                $columns[$columnId] = $column['type'];
            }
            $boards[$id] = $columns;
        }
        $nodes = $updates = [];
        foreach (self::rows($data['nodes'], 1000) as $node) {
            Metadata::exact($node, ['id', 'board_id', 'parent_id', 'update_ids', 'assets', 'workdocs', ...array_key_exists('asset_occurrences', $node) ? ['asset_occurrences'] : []]);
            $id = Metadata::opaque($node['id']);
            $board = Metadata::opaque($node['board_id']);
            abort_unless(isset($boards[$board]) && ! isset($nodes[$id]), 503);
            if ($node['parent_id'] !== null) {
                Metadata::opaque($node['parent_id']);
                abort_if($node['parent_id'] === $id, 503);
            }
            foreach (self::ids($node['update_ids'], 99) as $update) {
                abort_if(isset($updates[$update]), 503);
                $updates[$update] = $id;
            }
            self::ids($node['assets'], 200);
            $occurrences = [];
            foreach (self::rows($node['asset_occurrences'] ?? [], 500, false) as $occurrence) {
                Metadata::exact($occurrence, ['id', 'column_id', 'index']);
                Metadata::opaque($occurrence['id']);
                Metadata::opaque($occurrence['column_id']);
                abort_unless(in_array($occurrence['id'], $node['assets'], true) && ($boards[$board][$occurrence['column_id']] ?? null) === 'file'
                    && is_int($occurrence['index']) && $occurrence['index'] >= 0 && $occurrence['index'] <= 10000, 503);
                $key = $occurrence['column_id'].':'.$occurrence['index'];
                abort_if(isset($occurrences[$key]), 503);
                $occurrences[$key] = true;
            }
            if (array_key_exists('asset_occurrences', $node)) {
                $covered = array_values(array_unique(array_column($node['asset_occurrences'], 'id')));
                $expectedAssets = $node['assets'];
                sort($covered, SORT_STRING);
                sort($expectedAssets, SORT_STRING);
                abort_unless($covered === $expectedAssets, 503);
            }
            foreach (self::rows($node['workdocs'], 100, false) as $doc) {
                Metadata::exact($doc, ['object_id', 'doc_id', 'file_id', 'column_id']);
                foreach ($doc as $value) {
                    Metadata::opaque($value);
                }
                abort_unless(isset($boards[$board][$doc['column_id']]), 503);
            }
            $nodes[$id] = $node;
        }
        foreach ($nodes as $node) {
            if ($node['parent_id'] !== null) {
                abort_unless(isset($nodes[$node['parent_id']]) && $nodes[$node['parent_id']]['parent_id'] === null, 503);
            }
        }

        return new self($data, $hash);
    }

    public function columns(array $node): array
    {
        foreach ($this->data['boards'] as $board) {
            if ($board['id'] === $node['board_id']) {
                return $board['columns'];
            }
        }
        abort(503);
    }

    private static function ids(mixed $ids, int $maximum): array
    {
        abort_unless(is_array($ids) && array_is_list($ids) && count($ids) <= $maximum, 503);
        foreach ($ids as $id) {
            Metadata::opaque($id);
        }
        abort_unless(count(array_unique($ids, SORT_STRING)) === count($ids), 503);

        return $ids;
    }

    private static function rows(mixed $rows, int $maximum, bool $required = true): array
    {
        abort_unless(is_array($rows) && array_is_list($rows) && count($rows) <= $maximum && (! $required || $rows !== []), 503);
        foreach ($rows as $row) {
            abort_unless(is_array($row) && ! array_is_list($row), 503);
        }

        return $rows;
    }
}
