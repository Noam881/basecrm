<?php

use Awove\Core\Imports\FieldConflict;

function importFieldValue(mixed $value): array
{
    return ['available' => true, 'present' => true, 'value' => $value];
}

function importFieldAbsent(): array
{
    return ['available' => true, 'present' => false];
}

it('preserves an independent CRM edit when the reviewed source value has not changed', function (mixed $current) {
    expect(FieldConflict::decide(importFieldValue('original'), importFieldValue('original'), importFieldValue('original'), importFieldValue($current)))
        ->toBe(FieldConflict::UNCHANGED_SOURCE);
})->with(['CRM text edit' => ['CRM edit'], 'explicit CRM clear' => [null], 'typed CRM edit' => [false], 'CRM list edit' => [[2, 1]]]);

it('preserves an observed absent target after an unchanged source', function () {
    expect(FieldConflict::decide(importFieldValue('original'), importFieldValue('original'), importFieldValue('original'), importFieldAbsent()))
        ->toBe(FieldConflict::UNCHANGED_SOURCE);
});

it('allows a changed source only when the target still equals the last applied target', function () {
    expect(FieldConflict::decide(importFieldValue('source before'), importFieldValue('source after'), importFieldValue('last applied'), importFieldValue('last applied')))
        ->toBe(FieldConflict::APPLY_SOURCE_CHANGE);
});

it('recognizes an already matching new target even when its baseline differs', function () {
    expect(FieldConflict::decide(importFieldValue('before'), importFieldValue('after'), importFieldValue('before'), importFieldValue('after')))
        ->toBe(FieldConflict::ALREADY_MATCHING);
});

it('reports a conflict when source and CRM changed to different values', function () {
    expect(FieldConflict::decide(importFieldValue('before'), importFieldValue('source edit'), importFieldValue('before'), importFieldValue('CRM edit')))
        ->toBe(FieldConflict::CONFLICT);
});

it('treats explicit source null as data and an observed source absence as unresolved', function () {
    expect(FieldConflict::decide(importFieldValue('before'), importFieldValue(null), importFieldValue('before'), importFieldValue('before')))
        ->toBe(FieldConflict::APPLY_SOURCE_CHANGE)
        ->and(FieldConflict::decide(importFieldValue('before'), importFieldAbsent(), importFieldValue('before'), importFieldValue('before')))
        ->toBe(FieldConflict::UNRESOLVED)
        ->and(FieldConflict::decide(importFieldAbsent(), importFieldValue(null), importFieldValue(null), importFieldValue(null)))
        ->toBe(FieldConflict::UNRESOLVED);
});

it('distinguishes a CRM explicit clear from an observed absent target', function () {
    expect(FieldConflict::decide(importFieldValue('before'), importFieldValue(null), importFieldValue('before'), importFieldAbsent()))
        ->toBe(FieldConflict::CONFLICT)
        ->and(FieldConflict::decide(importFieldValue('before'), importFieldValue(null), importFieldValue('before'), importFieldValue(null)))
        ->toBe(FieldConflict::ALREADY_MATCHING)
        ->and(FieldConflict::decide(importFieldValue(null), importFieldValue('after'), importFieldAbsent(), importFieldAbsent()))
        ->toBe(FieldConflict::APPLY_SOURCE_CHANGE)
        ->and(FieldConflict::decide(importFieldValue(null), importFieldValue('after'), importFieldAbsent(), importFieldValue(null)))
        ->toBe(FieldConflict::CONFLICT);
});

it('requires an initial mapping policy even when a new field is blank or already matches', function (array $target) {
    expect(FieldConflict::decide(null, importFieldValue(null), null, $target))->toBe(FieldConflict::NEEDS_INITIAL_MAPPING);
})->with(['absent' => [importFieldAbsent()], 'explicit null' => [importFieldValue(null)], 'occupied' => [importFieldValue('CRM data')]]);

it('keeps incomplete or unavailable previous snapshots unresolved instead of treating them as initial', function () {
    $unavailable = ['available' => false];
    expect(FieldConflict::decide(null, importFieldValue('after'), importFieldValue('before'), importFieldValue('before')))
        ->toBe(FieldConflict::UNRESOLVED)
        ->and(FieldConflict::decide(importFieldValue('before'), importFieldValue('after'), null, importFieldValue('before')))
        ->toBe(FieldConflict::UNRESOLVED)
        ->and(FieldConflict::decide($unavailable, importFieldValue('after'), $unavailable, importFieldValue('before')))
        ->toBe(FieldConflict::UNRESOLVED);
});

it('refuses unavailable snapshots in every position without inferring an empty value', function (int $position) {
    $snapshots = array_fill(0, 4, importFieldValue('before'));
    $snapshots[$position] = ['available' => false];
    expect(FieldConflict::decide(...$snapshots))->toBe(FieldConflict::UNRESOLVED);
})->with([0, 1, 2, 3]);

it('does not coerce reviewed scalar types or numeric formatting', function (mixed $before, mixed $after) {
    expect(FieldConflict::decide(importFieldValue($before), importFieldValue($after), importFieldValue($before), importFieldValue($before)))
        ->toBe(FieldConflict::APPLY_SOURCE_CHANGE)
        ->and(FieldConflict::decide(importFieldValue('source before'), importFieldValue('source after'), importFieldValue($before), importFieldValue($after)))
        ->toBe(FieldConflict::CONFLICT);
})->with([
    'decimal string and float' => ['12.00', 12.0],
    'integer and float' => [1, 1.0],
    'string and integer' => ['1', 1],
    'boolean and integer' => [false, 0],
    'null and empty string' => [null, ''],
    'numeric formatting' => ['001.00', '1.00'],
    'opaque whitespace' => [' answer ', 'answer'],
    'opaque marker-looking value' => ['__ABSENT__', 'unavailable'],
]);

it('preserves JSON list order and conservative associative key order', function (array $before, array $after) {
    expect(FieldConflict::decide(importFieldValue($before), importFieldValue($after), importFieldValue($before), importFieldValue($before)))
        ->toBe(FieldConflict::APPLY_SOURCE_CHANGE)
        ->and(FieldConflict::decide(importFieldValue('before'), importFieldValue('after'), importFieldValue($before), importFieldValue($after)))
        ->toBe(FieldConflict::CONFLICT);
})->with([
    'list order' => [[1, 2], [2, 1]],
    'nested scalar type' => [[['value' => 1]], [['value' => '1']]],
    'associative order' => [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]],
    'list and map keys' => [[1, 2], ['first' => 1, 'second' => 2]],
]);

it('never treats marker-looking or nested snapshot-looking field values as unavailable', function () {
    foreach (['unavailable', '__ABSENT__', 'null', ['available' => false], ['present' => false, 'value' => null]] as $value) {
        expect(FieldConflict::decide(importFieldValue('before'), importFieldValue($value), importFieldValue('before'), importFieldValue('before')))
            ->toBe(FieldConflict::APPLY_SOURCE_CHANGE);
    }
});

it('rejects malformed envelopes rather than guessing availability or presence', function (array $invalid) {
    foreach ([0, 1, 2, 3] as $position) {
        $snapshots = array_fill(0, 4, importFieldValue('before'));
        $snapshots[$position] = $invalid;
        expect(FieldConflict::decide(...$snapshots))->toBe(FieldConflict::UNRESOLVED);
    }
})->with([
    'empty' => [[]],
    'missing availability' => [['present' => true, 'value' => null]],
    'string availability' => [['available' => 'true', 'present' => true, 'value' => null]],
    'integer presence' => [['available' => true, 'present' => 1, 'value' => null]],
    'missing present value' => [['available' => true, 'present' => true]],
    'value on absent' => [['available' => true, 'present' => false, 'value' => null]],
    'present on unavailable' => [['available' => false, 'present' => false]],
    'value on unavailable' => [['available' => false, 'value' => null]],
    'unexpected key' => [['available' => true, 'present' => true, 'value' => null, 'clear' => true]],
]);

it('refuses unsupported or nonfinite values without casting objects or invoking their hooks', function () {
    $object = new class
    {
        public function __toString(): string
        {
            throw new LogicException('Object hooks must never run.');
        }
    };
    $resource = fopen('php://memory', 'r+');
    try {
        foreach ([new stdClass, $object, $resource, INF, -INF, NAN, ['nested' => new stdClass]] as $value) {
            expect(FieldConflict::decide(importFieldValue('before'), importFieldValue($value), importFieldValue('before'), importFieldValue('before')))
                ->toBe(FieldConflict::UNRESOLVED);
        }
    } finally {
        fclose($resource);
    }
});

it('bounds deeply nested, recursive and oversized field values without mutating them', function () {
    $deep = 'leaf';
    for ($depth = 0; $depth < 34; $depth++) {
        $deep = [$deep];
    }
    $recursive = [];
    $recursive['self'] = &$recursive;
    foreach ([$deep, $recursive, array_fill(0, 10001, 'entry')] as $value) {
        expect(FieldConflict::decide(importFieldValue('before'), importFieldValue($value), importFieldValue('before'), importFieldValue('before')))
            ->toBe(FieldConflict::UNRESOLVED);
    }
});

it('returns only a fixed decision and leaves caller snapshots unchanged', function () {
    $source = importFieldValue(['typed' => ['005.00', false, null, "opaque\0bytes"]]);
    $target = importFieldValue(['CRM edit']);
    $beforeSource = $source;
    $beforeTarget = $target;
    expect(FieldConflict::decide($source, $source, $source, $target))->toBe(FieldConflict::UNCHANGED_SOURCE)
        ->and($source)->toBe($beforeSource)
        ->and($target)->toBe($beforeTarget);
});
