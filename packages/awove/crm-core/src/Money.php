<?php

namespace Awove\Core;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Exact single-currency amounts. No conversion, tax, payroll or accounting rules. */
final class Money
{
    /** Normalize one numeric(14,2) input without ever converting through a floating point value. */
    public static function amount(mixed $value, string $field = 'amount', bool $positive = false): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || ! preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/D', trim($value))) {
            throw ValidationException::withMessages([$field => 'Enter a non-negative amount with at most two decimal places.']);
        }
        $number = BigDecimal::of(trim($value))->toScale(2, RoundingMode::Unnecessary);
        if ($positive && $number->compareTo('0') <= 0) {
            throw ValidationException::withMessages([$field => 'Enter an amount greater than zero.']);
        }

        return (string) $number;
    }

    /** @param iterable<string|int> $amounts */
    public static function sum(iterable $amounts): string
    {
        $total = BigDecimal::of('0.00');
        foreach ($amounts as $amount) {
            $total = $total->plus(self::decimal($amount));
        }

        return (string) $total->toScale(2, RoundingMode::Unnecessary);
    }

    public static function subtract(string $left, string $right): string
    {
        return (string) self::decimal($left)->minus(self::decimal($right))->toScale(2, RoundingMode::Unnecessary);
    }

    public static function compare(string $left, string $right): int
    {
        return self::decimal($left)->compareTo(self::decimal($right));
    }

    private static function decimal(mixed $value): BigDecimal
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || strlen($value) > 256 || ! preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            throw new \InvalidArgumentException('Money calculations require exact fixed-point decimal strings.');
        }

        return BigDecimal::of($value)->toScale(2, RoundingMode::Unnecessary);
    }
}
