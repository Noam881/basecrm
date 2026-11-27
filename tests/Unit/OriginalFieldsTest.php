<?php

use Awove\Core\Imports\OriginalFields;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

it('preserves exact original JSON strings and distinguishes null unavailable and typed strings', function () {
    $cell = ['id' => 'source-field', 'label' => 'Original field', 'type' => 'numbers', 'representation' => 'json-string', 'available' => true, 'value' => '123456789012345678901234567890.0012300'];
    expect(OriginalFields::validate(['cells' => [$cell]])['cells'][0])->toBe($cell);
    foreach ([null, 'null', '""', '{}', '[]', '{malformed-source-json'] as $value) {
        $input = [...$cell, 'value' => $value];
        expect(OriginalFields::validate(['cells' => [$input]])['cells'][0])->toBe($input);
    }
    $missing = [...$cell, 'available' => false, 'value' => null];
    expect(OriginalFields::validate(['cells' => [$missing]])['cells'][0])->toBe($missing);
});
it('refuses floating point coercion duplicate cells unavailable values and unsafe envelopes', function () {
    $cell = ['id' => 'amount', 'label' => 'Original amount', 'type' => 'numbers', 'representation' => 'json-string', 'available' => true, 'value' => '1.2300'];
    foreach ([[...$cell, 'value' => 1.23], [...$cell, 'available' => false], [...$cell, 'value' => str_repeat('x', 1048577)], [...$cell, 'label' => "unsafe\0label"]] as $bad) {
        expect(fn () => OriginalFields::validate(['cells' => [$bad]]))->toThrow(HttpException::class);
    }
    expect(fn () => OriginalFields::validate(['cells' => [$cell, $cell]]))->toThrow(HttpException::class);
    expect(fn () => OriginalFields::validate(['cells' => [$cell], 'target_id' => 1]))->toThrow(ValidationException::class);
});
