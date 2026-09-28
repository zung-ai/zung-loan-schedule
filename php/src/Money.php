<?php

declare(strict_types=1);

namespace Zung\LoanSchedule;

final class Money
{
    /**
     * Round half away from zero to $decimals places, bit-for-bit the same as
     * PHP 8.4's round() (the rounding Zung's own schedule engine uses) on
     * every PHP version this library supports. PHP 8.1-8.3's built-in
     * round() pre-rounds and disagrees with 8.4 on many half-cent ties, so
     * it can't be used directly.
     *
     * Ported from php-src ext/standard/math.c (_php_math_round with
     * PHP_ROUND_HALF_UP, places >= 0); the Node implementation of this
     * library ports the same algorithm.
     *
     * Money::round(1.005, 2) === 1.01, Money::round(2.675, 2) === 2.68.
     *
     * @param int $decimals 0 to 22.
     */
    public static function round(float $value, int $decimals): float
    {
        if (!is_finite($value) || $value == 0.0) {
            return $value == 0.0 ? 0.0 : $value;
        }
        if ($decimals === 0 && $value == floor($value) && $value == ceil($value)) {
            return $value;
        }

        $exponent = (float) (10 ** $decimals);

        if ($value >= 0.0) {
            $integral = floor($value * $exponent);
            $next = $integral + 1.0;
        } else {
            $integral = ceil($value * $exponent);
            $next = $integral - 1.0;
        }

        if ($next / $exponent == $value) {
            $integral = $next;
        }

        // Beyond double precision: rounding is meaningless.
        if (abs($integral) >= 1e16) {
            return $value;
        }

        $edge = abs(($integral + self::copySign(0.5, $integral)) / $exponent);
        if (abs($value) >= $edge) {
            $integral += self::copySign(1.0, $integral);
        }

        $result = $integral / $exponent;

        return $result == 0.0 ? 0.0 : $result; // never -0
    }

    /**
     * C's copysign(): the magnitude of $x with the sign bit of $y (-0 counts as negative).
     */
    private static function copySign(float $x, float $y): float
    {
        // fdiv(): IEEE division, so -0.0 gives -INF instead of throwing.
        $negative = $y < 0.0 || ($y == 0.0 && fdiv(1.0, $y) < 0.0);

        return $negative ? -abs($x) : abs($x);
    }
}
