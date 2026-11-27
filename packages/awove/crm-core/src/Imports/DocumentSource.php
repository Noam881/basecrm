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
        Metadata::exact($input, self::KEYS);
        $input['role'] = Metadata::token($input['role']);
        abort_unless(in_array($input['entity_type'], ['asset', 'workdoc'], true)
            && in_array($input['kind'], ['file-column', 'item-assets-all', 'update', 'reply'], true), 422, 'Invalid document source context.');
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
            && ($history || $input['source_index'] !== null), 422, 'Invalid document source association.');
        $attribution = LegacyPayload::validate('source-created', ['body_html' => null, ...array_intersect_key($input,
            array_flip(['author', 'source_created_at', 'source_updated_at']))]);
        foreach (['author', 'source_created_at', 'source_updated_at'] as $key) {
            $input[$key] = $attribution[$key];
        }

        $ordered = [];
        foreach (self::KEYS as $key) {
            $ordered[$key] = $input[$key];
        }

        return new self($ordered);
    }
}
