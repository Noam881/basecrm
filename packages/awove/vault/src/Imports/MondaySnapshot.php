<?php

namespace Awove\Vault\Imports;

use Awove\Vault\Schema;

/** Lossless bounded source export inside native encrypted fields, never raw HTML rendering. */
final class MondaySnapshot
{
    public static function payload(MondayPlan $plan, array $node, #[\SensitiveParameter] array $source): array
    {
        $original = json_encode(['format' => 'basecrm-monday-source-v1', 'plan_hash' => $plan->hash,
            'source_account' => $plan->data['account_id'], 'scope' => $node, 'original' => $source], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        abort_unless(strlen($original) <= 450000, 422);
        $fields = [];
        $offset = 0;
        while ($offset < strlen($original)) {
            $chunk = mb_strcut($original, $offset, 90000, 'UTF-8');
            abort_unless($chunk !== '', 422);
            $fields[] = self::field('source.export.'.count($fields), __('Original source export part').' '.(count($fields) + 1), $chunk);
            $offset += strlen($chunk);
        }
        $archiveCount = count($fields);
        if (mb_strlen($source['name']) <= 100000) {
            $fields[] = self::field('source.original-title', __('Original title'), $source['name']);
        }
        foreach ($source['column_values'] as $column) {
            if (count($fields) >= 60) {
                break; // Every column, including overflow, is already preserved in the complete export.
            }
            $value = $column['display_value'] ?? $column['text'] ?? $column['value'];
            $label = $column['column']['title'];
            if (! is_string($value) || mb_strlen($value) > 100000 || mb_strlen($label) > 120 || trim($label) === '') {
                continue; // Native convenience field unavailable; source export retains the exact value/label.
            }
            $fields[] = self::field('source.column.'.count($fields), $label, $value);
        }
        foreach ($fields as $order => &$field) {
            $field['order'] = $order;
        }
        unset($field);
        // Optional native projections must not crowd out the complete source export.
        while (count($fields) > $archiveCount && strlen(json_encode(['fields' => $fields, 'notes' => ''], JSON_THROW_ON_ERROR)) > 524288) {
            array_pop($fields);
        }

        return Schema::payload(['fields' => $fields, 'notes' => '']);
    }

    public static function export(#[\SensitiveParameter] array $payload): string
    {
        $parts = [];
        foreach ($payload['fields'] ?? [] as $field) {
            if (is_string($field['id'] ?? null) && preg_match('/^source\.export\.(\d+)$/D', $field['id'], $match)) {
                abort_unless(is_string($field['value'] ?? null) && ! isset($parts[(int) $match[1]]), 409);
                $parts[(int) $match[1]] = $field['value'];
            }
        }
        ksort($parts);
        abort_unless($parts !== [] && array_keys($parts) === range(0, count($parts) - 1), 409);
        $original = implode('', $parts);
        $parsed = json_decode($original, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        abort_unless(is_array($parsed) && ($parsed['format'] ?? null) === 'basecrm-monday-source-v1', 409);

        return $original;
    }

    private static function field(string $id, string $label, string $value): array
    {
        return ['id' => $id, 'label' => $label, 'type' => 'secret_multiline', 'value' => $value, 'order' => 0, 'hidden' => true];
    }
}
