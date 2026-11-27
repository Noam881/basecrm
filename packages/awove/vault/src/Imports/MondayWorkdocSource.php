<?php

namespace Awove\Vault\Imports;

use App\Integrations\Monday\ReadOnlyClient;
use Awove\Core\Imports\Metadata;
use Illuminate\Http\Request;

/** Protected in-memory reads. JSON scalars stay escaped strings, not decoded/re-encoded values. */
class MondayWorkdocSource
{
    private const QUERY = 'query BaseCrmVaultWorkdoc($ids:[ID!]!,$items:[ID!]!,$columns:[String!]!,$page:Int!) { me { account { id } } version { value } '
        .'items(ids:$items,limit:1,exclude_nonactive:false) { id board { id } column_values(ids:$columns,capabilities:[CALCULATED,VISIBILITY]) { id type value text '
        .'... on FileValue { files { __typename ... on FileDocValue { object_id file_id created_at creator_id creator { id name } doc { id object_id } } } } } } '
        .'docs(ids:$ids,limit:1,page:1) { id object_id name settings created_at updated_at created_by { id name } doc_kind doc_folder_id workspace_id '
        .'blocks(limit:100,page:$page) { id doc_id parent_block_id type position content created_at updated_at created_by { id name } } } }';

    public function fetch(Request $request, MondayWorkdocPlan $plan, array $document, #[\SensitiveParameter] array $column, \Closure $authorize): array
    {
        $source = $document['source'];
        $original = null;
        $attachment = null;
        $blocks = [];
        $maximumPage = (int) ceil(count($document['blocks']) / 100) + 1;
        for ($page = 1; $page <= $maximumPage; $page++) {
            $authorize();
            $data = app(MondaySource::class)->query($request, self::QUERY, ['ids' => [$source['doc_id']], 'items' => [$source['item_id']],
                'columns' => [$source['column_id']], 'page' => $page]);
            $authorize();
            [$doc, $file] = $this->page($plan, $document, $column, $data, count($blocks));
            $pageBlocks = $doc['blocks'];
            unset($doc['blocks']);
            if ($original === null) {
                $original = $doc;
                $attachment = $file;
            }
            abort_unless($original === $doc && $attachment === $file, 409);
            $blocks = [...$blocks, ...$pageBlocks];
            abort_unless(strlen(json_encode([$original, $blocks, $attachment], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)) <= 450000, 422);
            if ($pageBlocks === []) {
                abort_unless(count($blocks) === count($document['blocks']), 409);
                $authorize();

                return ['document' => [...$original, 'blocks' => $blocks], 'attachment' => $attachment, 'terminal_empty_page' => $page];
            }
        }
        abort(409);
    }

    public function page(MondayWorkdocPlan $plan, array $document, #[\SensitiveParameter] array $expectedColumn, #[\SensitiveParameter] array $data, int $offset): array
    {
        $source = $document['source'];
        abort_unless(($data['me']['account']['id'] ?? null) === $plan->main->data['account_id'] && ($data['version']['value'] ?? null) === ReadOnlyClient::API_VERSION
            && is_array($data['items'] ?? null) && array_is_list($data['items']) && count($data['items']) === 1
            && is_array($data['docs'] ?? null) && array_is_list($data['docs']) && count($data['docs']) === 1, 503);
        $item = $data['items'][0];
        abort_unless(($item['id'] ?? null) === $source['item_id'] && ($item['board']['id'] ?? null) === $source['board_id']
            && is_array($item['column_values'] ?? null) && array_is_list($item['column_values']) && count($item['column_values']) === 1, 503);
        $column = $item['column_values'][0];
        abort_unless(($column['id'] ?? null) === $source['column_id'] && ($column['type'] ?? null) === 'file' && ($expectedColumn['type'] ?? null) === 'file'
            && array_key_exists('value', $column) && array_key_exists('text', $column) && $column['value'] === $expectedColumn['value'] && $column['text'] === $expectedColumn['text']
            && is_array($column['files'] ?? null) && array_is_list($column['files']), 409);
        $matches = array_values(array_filter($column['files'], fn ($file) => is_array($file) && ($file['__typename'] ?? null) === 'FileDocValue'
            && ($file['object_id'] ?? null) === $source['object_id'] && ($file['file_id'] ?? null) === $source['file_id']));
        abort_unless(count($matches) === 1, 409);
        $file = $matches[0];
        Metadata::exact($file, ['__typename', 'object_id', 'file_id', 'created_at', 'creator_id', 'creator', 'doc']);
        abort_unless(($file['doc']['id'] ?? null) === $source['doc_id'] && ($file['doc']['object_id'] ?? null) === $source['object_id'], 409);
        $this->date($file['created_at']);
        $this->author($file['creator']);
        abort_unless($file['creator_id'] === null || is_string($file['creator_id']), 503);
        abort_unless($file['creator'] === null || $file['creator_id'] === null || $file['creator']['id'] === $file['creator_id'], 503);
        $doc = $data['docs'][0];
        Metadata::exact($doc, ['id', 'object_id', 'name', 'settings', 'created_at', 'updated_at', 'created_by', 'doc_kind', 'doc_folder_id', 'workspace_id', 'blocks']);
        abort_unless($doc['id'] === $source['doc_id'] && $doc['object_id'] === $source['object_id'] && is_string($doc['name'])
            && is_string($doc['doc_kind']) && ($doc['doc_folder_id'] === null || is_string($doc['doc_folder_id'])) && ($doc['workspace_id'] === null || is_string($doc['workspace_id']))
            && is_array($doc['blocks']) && array_is_list($doc['blocks']) && count($doc['blocks']) <= 100, 503);
        $this->jsonScalar($doc['settings']);
        $this->date($doc['created_at']);
        $this->date($doc['updated_at']);
        $this->author($doc['created_by']);
        foreach ($doc['blocks'] as $index => $block) {
            Metadata::exact($block, ['id', 'doc_id', 'parent_block_id', 'type', 'position', 'content', 'created_at', 'updated_at', 'created_by']);
            $expected = $document['blocks'][$offset + $index] ?? null;
            abort_unless($expected !== null && array_intersect_key($block, $expected) === $expected
                && ($block['position'] === null || (is_int($block['position']) || is_float($block['position'])) && is_finite((float) $block['position'])), 409);
            $this->jsonScalar($block['content']);
            $this->date($block['created_at']);
            $this->date($block['updated_at']);
            $this->author($block['created_by']);
        }

        return [$doc, $file];
    }

    private function jsonScalar(mixed $value): void
    {
        abort_unless($value === null || is_string($value), 503);
        if (is_string($value)) {
            json_decode($value, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); // Validate only; retain the exact source string.
        }
    }

    private function author(mixed $author): void
    {
        abort_unless($author === null || is_array($author) && is_string($author['id'] ?? null) && is_string($author['name'] ?? null), 503);
        if (is_array($author)) {
            Metadata::exact($author, ['id', 'name']);
        }
    }

    private function date(mixed $date): void
    {
        abort_unless($date === null || is_string($date) && strlen($date) <= 128
            && preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2}))?$/D', $date, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]), 503);
    }
}
