<?php

namespace Awove\Core\Imports;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Bounded opaque metadata, never a raw payload, provenance, URL, filename or credential store. */
final class Metadata
{
    public const ERRORS = ['source_changed', 'target_changed', 'revision_conflict', 'mapping_context_changed', 'target_identity_conflict', 'reservation_expired', 'prior_unresolved_operation', 'operation_failed', 'unresolved_dependency', 'access_denied', 'validation_failed', 'transient_failure'];

    public static function exact(array $input, array $keys): void
    {
        if (array_diff(array_keys($input), $keys) !== [] || array_diff($keys, array_keys($input)) !== []) {
            self::invalid();
        }
    }

    public static function opaque(mixed $value, int $maximum = 255): string
    {
        if (! is_string($value) || strlen($value) > $maximum || ! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            self::invalid();
        }

        return $value;
    }

    public static function token(mixed $value, int $maximum = 64, bool $qualified = false): string
    {
        if (! is_string($value) || strlen($value) > $maximum
            || ! preg_match($qualified ? '/^[a-z][a-z0-9]*([.-][a-z0-9]+)*$/D' : '/^[a-z][a-z0-9-]*$/D', $value)) {
            self::invalid();
        }

        return $value;
    }

    public static function digest(mixed $value, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/D', $value)) {
            self::invalid();
        }

        return $value;
    }

    public static function uuid(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            self::invalid();
        }

        return strtolower($value);
    }

    public static function batch(array $input): array
    {
        self::exact($input, ['batch_key', 'provider', 'source_account', 'mapping_revision', 'code_revision', 'plan_revision']);
        if (! is_string($input['code_revision']) || ! preg_match('/^([a-f0-9]{40}|[a-f0-9]{64})$/D', $input['code_revision'])) {
            self::invalid();
        }

        return ['id' => self::uuid($input['batch_key']), 'provider' => self::token($input['provider'], 32),
            'source_account' => self::opaque($input['source_account']), 'mapping_revision' => self::digest($input['mapping_revision']),
            'code_revision' => $input['code_revision'], 'plan_revision' => self::digest($input['plan_revision'])];
    }

    public static function source(array $input): array
    {
        self::exact($input, ['board_id', 'entity_type', 'entity_id', 'target_role', 'relation_key']);

        return ['board_id' => self::opaque($input['board_id']), 'entity_type' => self::token($input['entity_type']),
            'entity_id' => self::opaque($input['entity_id']), 'target_role' => self::token($input['target_role']), 'relation_key' => self::opaque($input['relation_key'])];
    }

    public static function claim(array $input): array
    {
        self::exact($input, ['request_key', 'revision', 'source_fingerprint', 'target_fingerprint', 'previous_receipt_id']);
        if (! is_int($input['revision']) || $input['revision'] < 1 || $input['revision'] > 1000000
            || ($input['previous_receipt_id'] !== null && (! is_int($input['previous_receipt_id']) || $input['previous_receipt_id'] < 1))) {
            self::invalid();
        }

        return ['request_key' => self::uuid($input['request_key']), 'revision' => $input['revision'],
            'source_fingerprint' => self::digest($input['source_fingerprint']), 'observed_target_fingerprint' => self::digest($input['target_fingerprint'], true),
            'previous_receipt_id' => $input['previous_receipt_id']];
    }

    public static function result(array $input): array
    {
        if (($input['state'] ?? null) === 'verified') {
            self::exact($input, ['state', 'target_type', 'target_id', 'result_fingerprint']);

            return ['state' => 'verified', 'result_target_type' => self::token($input['target_type'], 64, true),
                'result_target_id' => self::opaque($input['target_id']), 'result_fingerprint' => self::digest($input['result_fingerprint']), 'error_code' => null];
        }
        self::exact($input, ['state', 'error_code']);
        if (! in_array($input['state'], ['failed', 'conflict'], true) || ! in_array($input['error_code'], self::ERRORS, true)) {
            self::invalid();
        }

        return ['state' => $input['state'], 'result_target_type' => null, 'result_target_id' => null, 'result_fingerprint' => null, 'error_code' => $input['error_code']];
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['import' => __('Invalid import metadata.')]);
    }
}
