<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Decimal money math, replacing brick/math with the bcmath PHP extension.
 *
 * Two scales in this codebase:
 *   - SCALE_MONEY = 2   for any value stored as money (subtotal, tax, total,
 *                       discount, tendered, change).  Round HALF_UP at every
 *                       storage boundary.
 *   - SCALE_COST  = 4   for blended cost-per-base-unit calculations (see
 *                       CostBlender), so rounding error doesn't accumulate
 *                       across multi-line GRN posts before the final scale-2
 *                       snapshot.
 *
 * IMPORTANT: bcmath silently TRUNCATES, brick/math threw on precision loss.
 * To preserve the old semantics, every "final" value goes through
 * Money::round() (HALF_UP).  Intermediate values use raw bc* with a wider
 * working scale, never stored.
 *
 * All inputs accepted as strings to avoid float coercion.  Pass ints/floats
 * only when you trust their representation (e.g. quantities from form input
 * that have already been validated).
 */
final class Money
{
    public const SCALE_MONEY = 2;
    public const SCALE_COST  = 4;

    /** Working scale wider than either output so trailing digits are available
     *  to the HALF_UP rounder.  bcmath default is 0, so always pass explicitly. */
    public const SCALE_WORK  = 8;

    public static function add(string|int|float $a, string|int|float $b, int $scale = self::SCALE_WORK): string
    {
        return bcadd(self::s($a), self::s($b), $scale);
    }

    public static function sub(string|int|float $a, string|int|float $b, int $scale = self::SCALE_WORK): string
    {
        return bcsub(self::s($a), self::s($b), $scale);
    }

    public static function mul(string|int|float $a, string|int|float $b, int $scale = self::SCALE_WORK): string
    {
        return bcmul(self::s($a), self::s($b), $scale);
    }

    public static function div(string|int|float $a, string|int|float $b, int $scale = self::SCALE_WORK): string
    {
        $bs = self::s($b);
        if (bccomp($bs, '0', self::SCALE_WORK) === 0) {
            throw new \DivisionByZeroError('Money::div by zero');
        }
        return bcdiv(self::s($a), $bs, $scale);
    }

    public static function cmp(string|int|float $a, string|int|float $b, int $scale = self::SCALE_MONEY): int
    {
        return bccomp(self::s($a), self::s($b), $scale);
    }

    /** Round HALF_UP to the requested scale.  Default = money (2). */
    public static function round(string|int|float $v, int $scale = self::SCALE_MONEY): string
    {
        $s = self::s($v);

        // Strip sign, work on magnitude, restore at end.
        $negative = ($s !== '' && $s[0] === '-');
        if ($negative) {
            $s = substr($s, 1);
        }

        // Locate decimal point.
        $dot = strpos($s, '.');
        if ($dot === false) {
            // Integer — already at scale "0".  Pad if scale > 0.
            $out = $scale > 0 ? $s . '.' . str_repeat('0', $scale) : $s;
            return $negative && bccomp($out, '0', $scale) !== 0 ? '-' . $out : $out;
        }

        $int  = substr($s, 0, $dot);
        $frac = substr($s, $dot + 1);

        if (strlen($frac) <= $scale) {
            $frac = str_pad($frac, $scale, '0', STR_PAD_RIGHT);
            $out  = $scale > 0 ? $int . '.' . $frac : $int;
            return $negative && bccomp($out, '0', $scale) !== 0 ? '-' . $out : $out;
        }

        // We have more digits than $scale.  HALF_UP on the next digit.
        $keep  = substr($frac, 0, $scale);
        $next  = (int) $frac[$scale];
        $value = $scale > 0 ? $int . '.' . $keep : $int;
        if ($next >= 5) {
            // Add 1 to the last kept position.
            $bump = $scale > 0 ? '0.' . str_repeat('0', $scale - 1) . '1' : '1';
            $value = bcadd($value, $bump, $scale);
        } else {
            // Force scale by an idempotent op.
            $value = bcadd($value, '0', $scale);
        }
        return $negative && bccomp($value, '0', $scale) !== 0 ? '-' . $value : $value;
    }

    /** Format for display (no rounding semantics — caller must round first). */
    public static function fmt(string|int|float $v, int $scale = self::SCALE_MONEY, string $thousands = ','): string
    {
        $rounded = self::round($v, $scale);
        $negative = ($rounded !== '' && $rounded[0] === '-');
        if ($negative) {
            $rounded = substr($rounded, 1);
        }
        $dot = strpos($rounded, '.');
        $int = $dot === false ? $rounded : substr($rounded, 0, $dot);
        $frac = $dot === false ? '' : substr($rounded, $dot);

        $int = strrev(implode($thousands, str_split(strrev($int), 3)));
        return ($negative ? '-' : '') . $int . $frac;
    }

    public static function zero(int $scale = self::SCALE_MONEY): string
    {
        return $scale > 0 ? '0.' . str_repeat('0', $scale) : '0';
    }

    public static function isZero(string|int|float $v, int $scale = self::SCALE_MONEY): bool
    {
        return bccomp(self::s($v), '0', $scale) === 0;
    }

    public static function isNegative(string|int|float $v, int $scale = self::SCALE_MONEY): bool
    {
        return bccomp(self::s($v), '0', $scale) < 0;
    }

    private static function s(string|int|float $v): string
    {
        if (is_string($v)) {
            $t = trim($v);
            return $t === '' ? '0' : $t;
        }
        if (is_int($v)) {
            return (string) $v;
        }
        // float → decimal string at maximum precision so bc* can absorb it.
        return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.') ?: '0';
    }
}
