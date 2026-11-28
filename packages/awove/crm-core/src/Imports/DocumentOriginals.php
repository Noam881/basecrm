<?php

namespace Awove\Core\Imports;

use Illuminate\Validation\ValidationException;

/** Bounded source document graph. Original serialized JSON/date strings are never normalized. */
final class DocumentOriginals
{
    public static function validate(DocumentSource $source, #[\SensitiveParameter] array $input): array
    {
        Metadata::exact($input, ['document_metadata', 'attachment_metadata', 'blocks']);
        $data = $source->data;
        $document = $input['document_metadata'];
        abort_unless(is_array($document) && ! array_is_list($document) && ($document['id'] ?? null) === $data['source_id'], 422, 'Invalid original document.');
        if ($data['entity_type'] === 'item-description') {
            Metadata::exact($document, ['id']);
            abort_unless($input['attachment_metadata'] === null, 422, 'Invalid original description.');
        } else {
            abort_unless(($document['object_id'] ?? null) === $data['object_id'] && is_array($input['attachment_metadata']), 422, 'Original document identity changed.');
            $attachment = $input['attachment_metadata'];
            abort_unless(($attachment['file_id'] ?? null) === $data['file_id'] && ($attachment['object_id'] ?? null) === $data['object_id'], 422, 'Original attachment identity changed.');
        }
        abort_unless(is_array($input['blocks']) && array_is_list($input['blocks']) && count($input['blocks']) <= 1000, 422, 'Invalid original document blocks.');
        $seen = [];
        foreach ($input['blocks'] as $block) {
            abort_unless(is_array($block), 422, 'Invalid original document block.');
            Metadata::exact($block, ['id', 'doc_id', 'parent_block_id', 'type', 'position', 'content', 'created_at', 'updated_at', 'created_by']);
            $id = Metadata::opaque($block['id']);
            abort_unless(! array_key_exists($id, $seen) && ($block['doc_id'] === null || $block['doc_id'] === $data['source_id'])
                && ($block['type'] === null || is_string($block['type']))
                && ($block['position'] === null || (is_int($block['position']) || is_float($block['position'])) && is_finite((float) $block['position'])), 422, 'Original block identity changed.');
            $seen[$id] = $block['parent_block_id'];
            if ($block['parent_block_id'] !== null) {
                Metadata::opaque($block['parent_block_id']);
            }
            foreach (['created_at', 'updated_at'] as $key) {
                self::text($block[$key], 128, true);
            }
            if ($block['created_by'] !== null) {
                abort_unless(is_array($block['created_by']), 422, 'Invalid original author.');
                Metadata::exact($block['created_by'], ['id', 'name']);
                Metadata::opaque($block['created_by']['id']);
                self::text($block['created_by']['name'], 16384, true);
            }
            self::text($block['content'], 1048576, true);
            abort_unless($block['content'] === null || json_validate($block['content'], 64), 422, 'Invalid original block JSON.');
        }
        foreach ($input['blocks'] as $block) {
            $path = [];
            $at = $block['id'];
            while ($at !== null) {
                abort_unless(array_key_exists($at, $seen) && ! isset($path[$at]), 422, 'Original block parent is missing or cyclic.');
                $path[$at] = true;
                $at = $seen[$at];
            }
        }
        self::tree($document);
        if ($input['attachment_metadata'] !== null) {
            self::tree($input['attachment_metadata']);
        }
        abort_unless(strlen(json_encode($input, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)) <= 2097152, 422, 'Original document is too large.');

        return $input;
    }

    /** Source-only attribution: never substitute the parent Item or attachment's creator/time. */
    public static function attribution(#[\SensitiveParameter] array $payload): array
    {
        $metadata = $payload['document_metadata'];
        $author = ['source_id' => null, 'name' => null];
        if (($metadata['created_by'] ?? null) !== null) {
            $original = $metadata['created_by'];
            abort_unless(is_array($original), 422, 'Invalid original document author.');
            Metadata::exact($original, ['id', 'name']);
            $author['source_id'] = Metadata::opaque($original['id']);
            self::text($original['name'], 16384, true);
            if ($original['name'] !== null && mb_strlen($original['name']) <= 255 && strlen($original['name']) <= 1024) {
                $author['name'] = $original['name'];
            }
        }
        $dates = [];
        foreach (['created_at', 'updated_at'] as $key) {
            $value = $metadata[$key] ?? null;
            self::text($value, 128, true);
            try {
                LegacyPayload::validate('source-created', ['body_html' => null, 'author' => ['source_id' => null, 'name' => null], 'source_created_at' => $value, 'source_updated_at' => null]);
                $dates['source_'.$key] = $value;
            } catch (ValidationException) {
                $dates['source_'.$key] = null;
            }
        }

        return ['author' => $author, ...$dates];
    }

    private static function text(#[\SensitiveParameter] mixed $value, int $maximum, bool $nullable = false): void
    {
        abort_unless($value === null && $nullable || is_string($value) && strlen($value) <= $maximum && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0"), 422, 'Invalid original document text.');
    }

    private static function tree(#[\SensitiveParameter] array $value, int $depth = 0): void
    {
        abort_unless($depth < 16 && count($value) <= 100, 422, 'Invalid original document metadata.');
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                self::text($key, 255);
            }
            if (is_array($item)) {
                self::tree($item, $depth + 1);
            } elseif (is_string($item)) {
                self::text($item, 1048576);
            } else {
                abort_unless($item === null || is_bool($item) || is_int($item) || is_float($item) && is_finite($item), 422, 'Invalid original document metadata.');
            }
        }
    }
}
