<?php

namespace Awove\Vault\Imports;

use Awove\Core\Imports\Metadata;

/** Independent deployment-owned supplement; never changes the armed item plan. */
final readonly class MondayWorkdocPlan
{
    private function __construct(public array $data, public string $hash, public MondayPlan $main) {}

    public static function load(): self
    {
        $bytes = PrivateFile::read('vault.monday_workdoc_plan_file', 2097152);
        $hash = hash('sha256', $bytes);
        abort_unless(hash_equals(Metadata::digest(config('vault.monday_workdoc_plan_sha256')), $hash), 503);
        $data = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        abort_unless(is_array($data), 503);

        return self::validate($data, $hash, MondayPlan::load());
    }

    public static function validate(array $data, string $hash, MondayPlan $main): self
    {
        Metadata::exact($data, ['version', 'main_plan_hash', 'run_id', 'code_revision', 'mapping_revision', 'documents']);
        Metadata::digest($hash);
        Metadata::digest($data['mapping_revision']);
        Metadata::uuid($data['run_id']);
        abort_unless($data['version'] === 1 && $data['main_plan_hash'] === $main->hash && $data['run_id'] !== $main->data['run_id']
            && is_string($data['code_revision']) && preg_match('/^[a-f0-9]{40}$/D', $data['code_revision'])
            && is_array($data['documents']) && array_is_list($data['documents']) && count($data['documents']) >= 1 && count($data['documents']) <= 20, 503);
        $expected = [];
        foreach ($main->data['nodes'] as $node) {
            foreach ($node['workdocs'] as $doc) {
                $expected[] = ['item_id' => $node['id'], 'board_id' => $node['board_id'], ...$doc];
            }
        }
        abort_unless(array_column($data['documents'], 'source') === $expected, 503);
        $seenDocs = $seenBlocks = [];
        foreach ($data['documents'] as $document) {
            Metadata::exact($document, ['source', 'blocks']);
            $source = $document['source'];
            Metadata::exact($source, ['item_id', 'board_id', 'object_id', 'doc_id', 'file_id', 'column_id']);
            foreach ($source as $id) {
                Metadata::opaque($id);
            }
            abort_if(isset($seenDocs['id:'.$source['doc_id']]), 503);
            $seenDocs['id:'.$source['doc_id']] = true;
            $node = array_values(array_filter($main->data['nodes'], fn ($node) => $node['id'] === $source['item_id']))[0];
            abort_unless(in_array(['id' => $source['column_id'], 'type' => 'file'], $main->columns($node), true)
                && is_array($document['blocks']) && array_is_list($document['blocks']) && count($document['blocks']) <= 1500, 503);
            $blocks = [];
            foreach ($document['blocks'] as $block) {
                Metadata::exact($block, ['id', 'doc_id', 'parent_block_id', 'type']);
                Metadata::opaque($block['id']);
                abort_unless($block['doc_id'] === $source['doc_id'] && in_array($block['type'], self::TYPES, true)
                    && ! isset($seenBlocks['id:'.$block['id']]), 503);
                if ($block['parent_block_id'] !== null) {
                    Metadata::opaque($block['parent_block_id']);
                    abort_if($block['parent_block_id'] === $block['id'], 503);
                }
                $seenBlocks['id:'.$block['id']] = true;
                $blocks['id:'.$block['id']] = $block;
            }
            foreach ($blocks as $block) {
                $visited = [];
                while ($block['parent_block_id'] !== null) {
                    $parent = 'id:'.$block['parent_block_id'];
                    abort_unless(isset($blocks[$parent]) && ! isset($visited[$parent]), 503);
                    $visited[$parent] = true;
                    $block = $blocks[$parent];
                }
            }
        }

        return new self($data, $hash, $main);
    }

    public const TYPES = ['normal text', 'bulleted list', 'numbered list', 'large title', 'medium title', 'small title', 'code', 'quote'];
}
