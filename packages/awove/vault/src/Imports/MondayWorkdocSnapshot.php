<?php

namespace Awove\Vault\Imports;

use Awove\Vault\Schema;

/** A truthful structured TXT export; this is not an uploaded asset or a PDF. */
final class MondayWorkdocSnapshot
{
    public static function payload(MondayWorkdocPlan $plan, array $document, string $parentEntry, string $parentItem, #[\SensitiveParameter] array $original): array
    {
        $bytes = json_encode(['format' => 'basecrm-monday-workdoc-v1', 'plan_hash' => $plan->hash, 'main_plan_hash' => $plan->main->hash,
            'source_account' => $plan->main->data['account_id'], 'scope' => $document, 'parent_entry_id' => $parentEntry, 'parent_item_id' => $parentItem,
            'original' => $original], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        abort_unless(strlen($bytes) <= 450000, 422);
        $fields = [];
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $chunk = mb_strcut($bytes, $offset, 90000, 'UTF-8');
            abort_unless($chunk !== '', 422);
            $fields[] = ['id' => 'source.workdoc.'.count($fields), 'label' => __('Original workdoc export part').' '.(count($fields) + 1),
                'type' => 'secret_multiline', 'value' => $chunk, 'order' => count($fields), 'hidden' => true];
            $offset += strlen($chunk);
        }

        return Schema::payload(['fields' => $fields, 'notes' => '']);
    }

    public static function export(#[\SensitiveParameter] array $payload): string
    {
        $parts = [];
        foreach ($payload['fields'] ?? [] as $field) {
            if (is_string($field['id'] ?? null) && preg_match('/^source\.workdoc\.(\d+)$/D', $field['id'], $match)) {
                abort_unless(is_string($field['value'] ?? null) && ! isset($parts[(int) $match[1]]), 409);
                $parts[(int) $match[1]] = $field['value'];
            }
        }
        ksort($parts);
        abort_unless($parts !== [] && array_keys($parts) === range(0, count($parts) - 1), 409);
        $bytes = implode('', $parts);
        $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        abort_unless(is_array($value) && ($value['format'] ?? null) === 'basecrm-monday-workdoc-v1', 409);

        return $bytes;
    }
}
