<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is stored and computed as integer nanodollars (1 USD = 1_000_000_000 nano).
 *
 * The Pixelforge API never returns a price, only token counts and per-million rates
 * given as exact decimal strings. Floats drift, so every conversion happens here, in
 * integers, and nothing outside this class does arithmetic on money.
 */
final class Money
{
    public const NANO_PER_USD = 1_000_000_000;

    /**
     * Convert a "USD per 1M tokens" rate such as "12.50" into nanoUSD per token.
     *
     * $12.50 per 1M tokens = $0.0000125 per token = 12_500 nano per token. Rates with up
     * to three fractional digits are exact because one thousandth of a dollar per
     * million tokens is one nanodollar per token. Any finer precision cannot be
     * represented, so it is rounded half-up to the nearest nano per token.
     */
    public static function rateToNanoPerToken(string $ratePerMillion): int
    {
        [$whole, $fraction] = array_pad(explode('.', $ratePerMillion, 2), 2, '');

        if (! preg_match('/^\d+$/', $whole) || ($fraction !== '' && ! preg_match('/^\d+$/', $fraction))) {
            throw new InvalidArgumentException("Not a decimal amount: {$ratePerMillion}");
        }

        $milli = (int) str_pad(substr($fraction, 0, 3), 3, '0');
        $nano = (int) $whole * 1_000 + $milli;

        // Round half-up when the provider publishes a fourth decimal place.
        if (strlen($fraction) > 3 && (int) $fraction[3] >= 5) {
            $nano++;
        }

        return $nano;
    }

    /**
     * Parse an exact decimal USD amount string (as Pixelforge returns them) into nano.
     *
     * "0.046125000" => 46_125_000 nano.
     */
    public static function fromDecimal(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        if (! preg_match('/^\d+$/', $whole) || ($fraction !== '' && ! preg_match('/^\d+$/', $fraction))) {
            throw new InvalidArgumentException("Not a decimal amount: {$amount}");
        }

        $nano = (int) $whole * self::NANO_PER_USD
            + (int) str_pad(substr($fraction, 0, 9), 9, '0');

        return $negative ? -$nano : $nano;
    }

    /**
     * Format nano as Pixelforge does: nine decimal places, unrounded.
     */
    public static function toDecimal(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $nano = abs($nano);

        return sprintf('%s%d.%09d', $sign, intdiv($nano, self::NANO_PER_USD), $nano % self::NANO_PER_USD);
    }

    /**
     * Human-facing amount, trimmed of trailing zeros: "$0.046125".
     */
    public static function display(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $decimal = rtrim(rtrim(self::toDecimal(abs($nano)), '0'), '.');

        return $sign.'$'.$decimal;
    }

    /**
     * Apply the markup, rounding half up to the nano.
     */
    public static function withMarkup(int $nano, int $percent = 25): int
    {
        return intdiv($nano * (100 + $percent) + 50, 100);
    }

    /**
     * Cost of tokens at a per-token nano rate. Exact: integers only.
     */
    public static function cost(int $tokens, int $nanoPerToken): int
    {
        return $tokens * $nanoPerToken;
    }
}
