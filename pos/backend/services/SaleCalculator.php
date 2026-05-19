<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Money;

/*
 * Sale line/total math, ported from backend/app/Services/Pos/SaleCalculator.php.
 * brick/math → bcmath via CPHC\Money.  Same scales, same rounding semantics
 * (HALF_UP at every storage boundary).
 *
 * Every method returns a decimal string at scale 2 (money) or 4 (cost).
 */
final class SaleCalculator
{
    public const SCALE_MONEY = Money::SCALE_MONEY;  // 2
    public const SCALE_COST  = Money::SCALE_COST;   // 4

    /** qty (integer base units) × unit_mrp → scale-2 line subtotal. */
    public static function lineSubtotal(int $qty, string $unitMrp): string
    {
        return Money::round(Money::mul((string) $qty, $unitMrp), self::SCALE_MONEY);
    }

    /** subtotal − discount + tax, all rounded once at the end. */
    public static function lineTotal(string $lineSubtotal, string $lineDiscount = '0', string $lineTax = '0'): string
    {
        $v = Money::add(Money::sub($lineSubtotal, $lineDiscount), $lineTax);
        return Money::round($v, self::SCALE_MONEY);
    }

    /** @param iterable<string> $values */
    public static function sum(iterable $values): string
    {
        $sum = Money::zero(Money::SCALE_WORK);
        foreach ($values as $v) {
            $sum = Money::add($sum, $v);
        }
        return Money::round($sum, self::SCALE_MONEY);
    }

    /** Change = tendered − grand_total, clamped at zero. */
    public static function change(string $tendered, string $grandTotal): string
    {
        $diff = Money::sub($tendered, $grandTotal);
        if (Money::isNegative($diff)) {
            return Money::zero(self::SCALE_MONEY);
        }
        return Money::round($diff, self::SCALE_MONEY);
    }
}
