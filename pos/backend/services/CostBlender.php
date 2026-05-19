<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Money;

/*
 * Cost-blending math for GRN lines.  Port of
 * backend/app/Services/Pricing/CostBlender.php — brick/math → bcmath via Money.
 *
 *     blended_cost_per_base_unit =
 *         (unit_cost × paid_qty) / ((paid_qty + foc_qty) × units_per_purchase)
 *
 *     mrp_per_base_unit = mrp_per_purchase_unit / units_per_purchase
 *
 * Both at scale 4 to match the DECIMAL(12,4) `cost_per_unit` column in
 * batches.  Line totals stay scale 2 for the invoice subtotal.
 */
final class CostBlender
{
    public const SCALE_COST = 4;
    public const SCALE_MRP  = 4;

    public static function blendedCostPerBaseUnit(int $paidQty, int $focQty, string $unitCost, int $unitsPerPurchase): string
    {
        if ($paidQty < 0 || $focQty < 0 || $unitsPerPurchase < 1) {
            throw new \InvalidArgumentException('paid_qty/foc_qty must be ≥ 0 and units_per_purchase ≥ 1');
        }
        $totalBaseUnits = ($paidQty + $focQty) * $unitsPerPurchase;
        if ($totalBaseUnits === 0) {
            return Money::zero(self::SCALE_COST);
        }
        return Money::round(
            Money::div(Money::mul($unitCost, (string) $paidQty), (string) $totalBaseUnits, Money::SCALE_WORK),
            self::SCALE_COST,
        );
    }

    public static function mrpPerBaseUnit(string $mrpPerPurchaseUnit, int $unitsPerPurchase): string
    {
        if ($unitsPerPurchase < 1) {
            throw new \InvalidArgumentException('units_per_purchase must be ≥ 1');
        }
        return Money::round(
            Money::div($mrpPerPurchaseUnit, (string) $unitsPerPurchase, Money::SCALE_WORK),
            self::SCALE_MRP,
        );
    }

    /** Supplier-invoice line total in purchase units: unit_cost × paid_qty. */
    public static function lineTotal(int $paidQty, string $unitCost): string
    {
        return Money::round(Money::mul($unitCost, (string) $paidQty), 2);
    }
}
