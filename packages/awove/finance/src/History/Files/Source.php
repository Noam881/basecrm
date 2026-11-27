<?php

namespace Awove\Finance\History\Files;

use Awove\Core\Imports\LegacyPayload;
use Awove\Core\Imports\Metadata;

/** Exact source facts, not caller target IDs, paths, URLs, authorization flags or recovery hints. */
final readonly class Source
{
    private function __construct(public array $data) {}

    public static function from(array $input): self
    {
        $keys = ['role', 'board_id', 'asset_id', 'item_mapping_id', 'item_id', 'entry_mapping_id', 'kind', 'column_id', 'source_index', 'update_id', 'reply_id', 'author', 'source_created_at', 'source_updated_at'];
        Metadata::exact($input, $keys);
        abort_unless($input['role'] === 'finance-history-files' && in_array($input['kind'], ['file-column', 'item-assets-all', 'update', 'reply'], true), 422, 'Invalid Finance file source.');
        foreach (['board_id', 'asset_id', 'item_id'] as $key) {
            Metadata::opaque($input[$key]);
        }
        foreach (['column_id', 'update_id', 'reply_id'] as $key) {
            if ($input[$key] !== null) {
                Metadata::opaque($input[$key]);
            }
        }
        abort_unless(is_int($input['item_mapping_id']) && $input['item_mapping_id'] > 0 && is_int($input['entry_mapping_id']) && $input['entry_mapping_id'] > 0
            && ($input['kind'] === 'file-column') === ($input['column_id'] !== null)
            && in_array($input['kind'], ['update', 'reply'], true) === ($input['update_id'] !== null)
            && ($input['kind'] === 'reply') === ($input['reply_id'] !== null)
            && (in_array($input['kind'], ['update', 'reply'], true) ? $input['source_index'] === null : is_int($input['source_index']) && $input['source_index'] >= 0 && $input['source_index'] <= 1000000), 422, 'Invalid Finance file association.');
        $times = [];
        foreach (['source_created_at', 'source_updated_at'] as $key) {
            $value = $input[$key];
            if (self::precision($value) === 'date') {
                validator([$key => $value], [$key => 'required|date_format:Y-m-d'])->validate();
                $times[$key] = null;
            } else {
                $times[$key] = $value;
            }
        }
        $attribution = LegacyPayload::validate('source-created', ['body_html' => null, 'author' => $input['author'], ...$times]);
        // JSONB may reorder the author object's keys. Use the existing canonical author envelope,
        // while retaining exact timestamp/date-only source strings unchanged.
        $input['author'] = $attribution['author'];

        return new self(array_replace(array_fill_keys($keys, null), $input));
    }

    public static function precision(mixed $value): ?string
    {
        return $value === null ? null : (is_string($value) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) ? 'date' : 'timestamp');
    }
}
