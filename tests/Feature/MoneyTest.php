<?php

use Awove\Core\Money;
use Illuminate\Validation\ValidationException;

it('normalizes exact financial input and rejects floating point and unsupported precision', function () {
    expect(Money::amount('0012.5'))->toBe('12.50')->and(Money::amount(0))->toBe('0.00')
        ->and(Money::amount('999999999999.99'))->toBe('999999999999.99');
    foreach ([12.5, 0.1, '1e3', '-1', '+1', '0.001', '1000000000000', '', 'NaN', null, [], '12,50'] as $value) {
        expect(fn () => Money::amount($value))->toThrow(ValidationException::class);
    }
    expect(fn () => Money::amount('0', 'payment', true))->toThrow(ValidationException::class)
        ->and(Money::amount('0.01', 'payment', true))->toBe('0.01');
});

it('calculates sums and remaining balances without record caps or floating point rounding', function () {
    expect(Money::sum(['0.01', '0.09']))->toBe('0.10')->and(Money::sum([]))->toBe('0.00')
        ->and(Money::sum(['999999999999.99', '999999999999.99']))->toBe('1999999999999.98')
        ->and(Money::subtract('100.00', '33.33'))->toBe('66.67')->and(Money::subtract('0.00', '0.01'))->toBe('-0.01')
        ->and(Money::compare('999999999999999.99', '999999999999999.98'))->toBe(1)
        ->and(Money::compare('0.10', '0.1'))->toBe(0);
    expect(fn () => Money::sum([0.1]))->toThrow(InvalidArgumentException::class);
});
