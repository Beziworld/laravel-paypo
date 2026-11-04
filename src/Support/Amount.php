<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Support;

use InvalidArgumentException;

/**
 * Converts between PayPo minor units (grosz) and decimal strings.
 */
final class Amount
{
    /**
     * Converts a decimal amount such as "249.90" or "249,90" to minor units (24990).
     *
     * @throws InvalidArgumentException When the value is not a decimal number with at most two fractional digits.
     */
    public static function toMinor(string|int $decimal): int
    {
        if (is_int($decimal)) {
            return $decimal * 100;
        }

        $value = str_replace([' ', ','], ['', '.'], trim($decimal));

        if (preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $value, $match) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid decimal amount.', $decimal));
        }

        $minor = (int) $match[2] * 100 + (int) str_pad($match[3] ?? '', 2, '0');

        return $match[1] === '-' ? -$minor : $minor;
    }

    /**
     * Converts minor units (24990) to a decimal string ("249.90").
     */
    public static function toDecimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($minor, 100), $minor % 100);
    }
}
