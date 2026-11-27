<?php

namespace Awove\Core\Imports;

/**
 * Pure decisions for established field mappings; never authorizes or performs a write.
 *
 * Adapters must supply all four snapshots in the same reviewed destination representation.
 * A snapshot is ['available' => false], an observed absent field is
 * ['available' => true, 'present' => false], and an observed value is
 * ['available' => true, 'present' => true, 'value' => $value]. Explicit null is a value.
 * Only a null previous-source/previous-target pair means an initial unmapped field.
 * A preserved CRM edit must not become the last-applied baseline: callers advance that
 * baseline only after an actual apply or a verified match, never after unchanged source,
 * conflict or unresolved work. Otherwise a later source change could overwrite the edit.
 *
 * Values are opaque PHP scalars/arrays: no money, identifier, credential or JSON normalization.
 * Strict comparison retains scalar types and list order, and conservatively treats associative
 * key order as significant. Objects are unsupported; adapters must represent JSON object/list
 * distinctions explicitly, including an empty object versus an empty list, before comparison.
 * Snapshots and values are neither returned, logged, hashed nor persisted by this helper.
 */
final class FieldConflict
{
    public const UNCHANGED_SOURCE = 'unchanged_source';

    public const APPLY_SOURCE_CHANGE = 'apply_source_change';

    public const ALREADY_MATCHING = 'already_matching';

    public const CONFLICT = 'conflict';

    public const UNRESOLVED = 'unresolved';

    public const NEEDS_INITIAL_MAPPING = 'needs_initial_mapping';

    /**
     * @param  array<string, mixed>|null  $previousSource
     * @param  array<string, mixed>  $nextSource
     * @param  array<string, mixed>|null  $previousAppliedTarget
     * @param  array<string, mixed>  $currentTarget
     */
    public static function decide(?array $previousSource, array $nextSource, ?array $previousAppliedTarget, array $currentTarget): string
    {
        foreach ([$previousSource, $nextSource, $previousAppliedTarget, $currentTarget] as $snapshot) {
            if ($snapshot !== null && ! self::valid($snapshot)) {
                return self::UNRESOLVED;
            }
        }

        if (! self::observed($nextSource) || ! $nextSource['present'] || ! self::observed($currentTarget)) {
            return self::UNRESOLVED;
        }
        if ($previousSource === null || $previousAppliedTarget === null) {
            return $previousSource === null && $previousAppliedTarget === null
                ? self::NEEDS_INITIAL_MAPPING : self::UNRESOLVED;
        }
        if (! self::observed($previousSource) || ! $previousSource['present'] || ! self::observed($previousAppliedTarget)) {
            return self::UNRESOLVED;
        }

        if (self::same($previousSource, $nextSource)) {
            return self::UNCHANGED_SOURCE;
        }
        if (self::same($currentTarget, $nextSource)) {
            return self::ALREADY_MATCHING;
        }

        return self::same($previousAppliedTarget, $currentTarget) ? self::APPLY_SOURCE_CHANGE : self::CONFLICT;
    }

    /** @param array<string, mixed> $snapshot */
    private static function valid(array $snapshot): bool
    {
        if (! is_bool($snapshot['available'] ?? null)) {
            return false;
        }
        if ($snapshot['available'] === false) {
            return count($snapshot) === 1;
        }
        if (! is_bool($snapshot['present'] ?? null)) {
            return false;
        }
        if ($snapshot['present'] === false) {
            return count($snapshot) === 2;
        }
        if (count($snapshot) !== 3 || ! array_key_exists('value', $snapshot)) {
            return false;
        }

        $remainingNodes = 10000;

        return self::supported($snapshot['value'], 0, $remainingNodes);
    }

    private static function supported(mixed $value, int $depth, int &$remainingNodes): bool
    {
        if ($depth > 32 || --$remainingNodes < 0) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (! self::supported($entry, $depth + 1, $remainingNodes)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_string($value) || is_bool($value) || is_int($value)
            || (is_float($value) && is_finite($value));
    }

    /** @param array<string, mixed> $snapshot */
    private static function observed(array $snapshot): bool
    {
        return $snapshot['available'] === true;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private static function same(array $left, array $right): bool
    {
        return $left['present'] === $right['present']
            && (! $left['present'] || $left['value'] === $right['value']);
    }
}
