<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in this app is stored as integer nanodollars (1 USD = 1,000,000,000).
 *
 * Pixelforge reports costs with exactly 9 decimals, and every rate × token
 * product lands on a whole nanodollar, so integers give us exact arithmetic
 * with no float drift and no rounding anywhere in the billing path.
 */
final class Money
{
    public const NANOS_PER_DOLLAR = 1_000_000_000;

    /**
     * Parse a decimal USD string ("12.50", "0.169080000") into nanodollars without floats.
     */
    public static function parse(string $decimal): int
    {
        $decimal = trim($decimal);

        if (! preg_match('/^(-)?(\d+)(?:\.(\d{0,9}))?$/', $decimal, $m)) {
            throw new InvalidArgumentException("Not a USD amount with at most 9 decimals: [{$decimal}]");
        }

        $nanos = (int) $m[2] * self::NANOS_PER_DOLLAR + (int) str_pad($m[3] ?? '', 9, '0');

        return ($m[1] ?? '') === '-' ? -$nanos : $nanos;
    }

    /**
     * Exact 9-decimal string, e.g. "0.046125000" (same shape as Pixelforge's cost_usd).
     */
    public static function toDecimal(int $nanos): string
    {
        $sign = $nanos < 0 ? '-' : '';
        $abs = abs($nanos);

        return $sign.intdiv($abs, self::NANOS_PER_DOLLAR).'.'.str_pad((string) ($abs % self::NANOS_PER_DOLLAR), 9, '0', STR_PAD_LEFT);
    }

    /**
     * Human display, exact but without trailing zeros: "$0.046125", "$5.00", "-$0.12".
     */
    public static function format(int $nanos): string
    {
        $decimal = self::toDecimal(abs($nanos));
        [$whole, $fraction] = explode('.', $decimal);
        $fraction = str_pad(rtrim($fraction, '0'), 2, '0');

        return ($nanos < 0 ? '-' : '').'$'.number_format((int) $whole).'.'.$fraction;
    }
}
