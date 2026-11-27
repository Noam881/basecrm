<?php

namespace Awove\Core\Imports;

/** Pure original-cell envelope. Does not decode values, decide ownership, or persist anything. */
final class OriginalFields
{
    public static function validate(array $input): array
    {
        Metadata::exact($input, ['cells']);
        abort_unless(is_array($input['cells']) && array_is_list($input['cells']) && count($input['cells']) <= 250, 422, 'Invalid original fields.');
        $seen = [];
        $cells = [];
        foreach ($input['cells'] as $cell) {
            abort_unless(is_array($cell), 422, 'Invalid original fields.');
            Metadata::exact($cell, ['id', 'label', 'type', 'representation', 'available', 'value']);
            $id = Metadata::opaque($cell['id']);
            abort_unless(! isset($seen[$id]) && is_bool($cell['available']) && in_array($cell['representation'], ['json-string', 'graphql-string'], true)
                && is_string($cell['label']) && mb_check_encoding($cell['label'], 'UTF-8') && mb_strlen($cell['label']) <= 512
                && ! str_contains($cell['label'], "\0") && ($cell['value'] === null || is_string($cell['value']) && mb_check_encoding($cell['value'], 'UTF-8')
                    && strlen($cell['value']) <= 1048576 && ! str_contains($cell['value'], "\0"))
                && ($cell['available'] || $cell['value'] === null), 422, 'Invalid original fields.');
            $seen[$id] = true;
            $cells[] = ['id' => $id, 'label' => $cell['label'], 'type' => Metadata::opaque($cell['type'], 64),
                'representation' => $cell['representation'], 'available' => $cell['available'], 'value' => $cell['value']];
        }
        abort_unless(strlen(json_encode($cells, JSON_THROW_ON_ERROR)) <= 2097152, 422, 'Original fields are too large.');

        return ['cells' => $cells];
    }
}
