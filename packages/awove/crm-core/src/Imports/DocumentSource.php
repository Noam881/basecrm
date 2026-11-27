<?php

namespace Awove\Core\Imports;

/** Exact bounded provenance, never a URL/body/credential container or a caller's target identity. */
final readonly class DocumentSource
{
    private const KEYS = ['role', 'entity_type', 'source_id', 'source_parent_id', 'source_item_mapping_id', 'kind', 'column_id', 'source_index',
        'update_id', 'reply_id', 'history_mapping_id', 'author', 'source_created_at', 'source_updated_at'];

    private function __construct(public array $data) {}

    public static function from(array $input): self
    {
        $reference = ($input['kind'] ?? null) === 'generated-reference';
        if ($reference) {
            $input += ['reference_digest' => null];
        }
        Metadata::exact($input, $reference ? [...self::KEYS, 'parent_item_id', 'reference_revision', 'review_flags', 'reference_columns', 'reference_digest'] : self::KEYS);
        if ($reference) {
            abort_unless($input['entity_type'] === 'item' && $input['source_id'] === $input['source_parent_id']
                && is_int($input['reference_revision']) && $input['reference_revision'] >= 1 && $input['reference_revision'] <= 1000000
                && is_array($input['review_flags']) && array_is_list($input['review_flags']) && count($input['review_flags']) <= 20, 422, 'Invalid generated reference.');
            abort_unless(is_array($input['reference_columns']) && array_is_list($input['reference_columns']) && count($input['reference_columns']) <= 250 && count(array_unique($input['reference_columns'])) === count($input['reference_columns']), 422, 'Invalid generated reference.');
            foreach ($input['reference_columns'] as $column) {
                Metadata::opaque($column);
            }
            Metadata::digest($input['reference_digest'], true);
            if ($input['parent_item_id'] !== null) {
                $input['parent_item_id'] = Metadata::opaque($input['parent_item_id']);
            }
            foreach ($input['review_flags'] as $flag) {
                Metadata::opaque($flag, 64);
            }
            abort_unless(count(array_unique($input['review_flags'])) === count($input['review_flags']), 422, 'Invalid generated reference.');
        }
        $input['role'] = Metadata::token($input['role']);
        abort_unless(in_array($input['entity_type'], $reference ? ['item'] : ['asset', 'workdoc'], true)
            && in_array($input['kind'], ['file-column', 'item-assets-all', 'update', 'reply', 'generated-reference'], true), 422, 'Invalid document source context.');
        foreach (['source_id', 'source_parent_id'] as $key) {
            $input[$key] = Metadata::opaque($input[$key]);
        }
        foreach (['column_id', 'update_id', 'reply_id'] as $key) {
            if ($input[$key] !== null) {
                $input[$key] = Metadata::opaque($input[$key]);
            }
        }
        abort_unless(is_int($input['source_item_mapping_id']) && $input['source_item_mapping_id'] > 0
            && ($input['history_mapping_id'] === null || is_int($input['history_mapping_id']) && $input['history_mapping_id'] > 0)
            && ($input['source_index'] === null || is_int($input['source_index']) && $input['source_index'] >= 0 && $input['source_index'] <= 1000000), 422, 'Invalid document source context.');
        $history = in_array($input['kind'], ['update', 'reply'], true);
        abort_unless($history === ($input['history_mapping_id'] !== null) && $history === ($input['update_id'] !== null)
            && ($input['kind'] === 'reply') === ($input['reply_id'] !== null)
            && ($input['kind'] === 'file-column') === ($input['column_id'] !== null)
            && ($reference ? $input['source_index'] === null : ($history || $input['source_index'] !== null)), 422, 'Invalid document source association.');
        $attribution = LegacyPayload::validate('source-created', ['body_html' => null, ...array_intersect_key($input,
            array_flip(['author', 'source_created_at', 'source_updated_at']))]);
        foreach (['author', 'source_created_at', 'source_updated_at'] as $key) {
            $input[$key] = $attribution[$key];
        }

        $ordered = [];
        foreach ($reference ? [...self::KEYS, 'parent_item_id', 'reference_revision', 'review_flags', 'reference_columns', 'reference_digest'] : self::KEYS as $key) {
            $ordered[$key] = $input[$key];
        }

        return new self($ordered);
    }
}
