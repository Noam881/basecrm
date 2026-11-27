<?php

namespace Awove\Finance\History;

use Awove\Core\Imports\LegacyPayload;
use Awove\Core\Imports\Metadata;
use Illuminate\Validation\ValidationException;

/** Reviewed financial fields only. Raw JSON is retained as strings, never decoded through floats. */
final class Payload
{
    public const MAX_FACT_BYTES = 1048576;

    public const MAX_FACTS = 100;

    public const VALUE_KINDS = ['money', 'decimal', 'hours', 'count', 'percent', 'text', 'date', 'timestamp', 'status', 'link', 'reference', 'formula-output', 'json'];

    public static function validate(string $kind, array $input): array
    {
        Metadata::exact($input, ['label', 'facts', 'body_html', 'author', 'source_created_at', 'source_updated_at']);
        if (! in_array($kind, ['facts', 'update', 'reply'], true) || ($input['label'] !== null && (! self::text($input['label']) || mb_strlen($input['label']) > 500))
            || ! is_array($input['facts']) || ! array_is_list($input['facts']) || count($input['facts']) > self::MAX_FACTS
            || ($kind !== 'facts' && $input['facts'] !== [])) {
            self::invalid();
        }
        $facts = [];
        $keys = [];
        $bytes = 0;
        foreach ($input['facts'] as $fact) {
            if (! is_array($fact)) {
                self::invalid();
            }
            Metadata::exact($fact, ['key', 'label', 'value_kind', 'availability', 'raw_json', 'unit', 'currency']);
            $key = Metadata::opaque($fact['key']);
            if (in_array($key, $keys, true) || ! self::text($fact['label']) || mb_strlen($fact['label']) > 255
                || ! in_array($fact['value_kind'], self::VALUE_KINDS, true) || ! in_array($fact['availability'], ['present', 'missing', 'unavailable'], true)
                || ($fact['unit'] !== null && (! self::text($fact['unit']) || mb_strlen($fact['unit']) > 40))
                || ($fact['currency'] !== null && (! is_string($fact['currency']) || ! preg_match('/^[A-Z]{3}$/D', $fact['currency'])))
                || ($fact['value_kind'] !== 'money' && $fact['currency'] !== null)) {
                self::invalid();
            }
            $raw = $fact['raw_json'];
            if ($fact['availability'] === 'present') {
                if (! self::text($raw) || strlen($raw) > self::MAX_FACT_BYTES || ! json_validate($raw, 32)) {
                    self::invalid();
                }
                $type = self::jsonType($raw);
                $bytes += strlen($raw);
            } else {
                if ($raw !== null) {
                    self::invalid();
                }
                $type = null;
            }
            $keys[] = $key;
            $facts[] = ['key' => $key, 'label' => $fact['label'], 'value_kind' => $fact['value_kind'], 'availability' => $fact['availability'],
                'raw_json' => $raw, 'json_type' => $type, 'unit' => $fact['unit'], 'currency' => $fact['currency']];
        }
        if ($bytes > self::MAX_FACT_BYTES) {
            self::invalid();
        }
        $text = LegacyPayload::validate($kind === 'facts' ? ($input['body_html'] === null ? 'source-created' : 'update') : $kind,
            array_intersect_key($input, array_flip(['body_html', 'author', 'source_created_at', 'source_updated_at'])));

        return ['label' => $input['label'], 'facts' => $facts, ...$text];
    }

    public static function display(array $fact): ?string
    {
        if ($fact['availability'] !== 'present') {
            return null;
        }

        // Only top-level strings are decoded. Numbers/objects/arrays retain their exact lexical values.
        return $fact['json_type'] === 'string' ? json_decode($fact['raw_json'], false, 32, JSON_THROW_ON_ERROR) : $fact['raw_json'];
    }

    private static function jsonType(string $raw): string
    {
        return match (ltrim($raw)[0]) {
            '"' => 'string', '{' => 'object', '[' => 'array', 't', 'f' => 'boolean', 'n' => 'null', default => 'number',
        };
    }

    private static function text(mixed $value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['history' => __('The historical finance payload is invalid or exceeds its supported limits.')]);
    }
}
