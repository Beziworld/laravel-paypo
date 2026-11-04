<?php

declare(strict_types=1);

namespace Beziworld\PayPo\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Tolerant readers of scalar values from decoded PayPo payloads.
 *
 * @internal
 */
final class Values
{
    public static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Parses an RFC 3339 date; values without an offset are read in the given time zone.
     */
    public static function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), $timezone)->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
