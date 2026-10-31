<?php

namespace Awove\Operations;

/** Exact thousandths for operational quantities; monetary totals remain database decimals. */
class Quantity
{
    public static function units(string $quantity): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,3}))?$/D', $quantity, $parts)) {
            throw new \InvalidArgumentException('Use a nonnegative quantity with at most three decimal places.');
        }

        return ((int) $parts[1]) * 1000 + (int) str_pad($parts[2] ?? '', 3, '0');
    }

    public static function format(int $units): string
    {
        return intdiv($units, 1000).'.'.str_pad((string) ($units % 1000), 3, '0', STR_PAD_LEFT);
    }
}
