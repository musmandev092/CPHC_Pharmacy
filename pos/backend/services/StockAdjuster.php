<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;
use CPHC\Money;

/*
 * Manual stock adjustment.  Port of
 * backend/app/Services/Inventory/StockAdjustmentService.php.
 *
 * qty_delta is SIGNED — positive adds, negative removes.  Refuses to result
 * in negative current_qty.  Records the cost impact at the batch's current
 * cost_per_unit.  Emits an ADJUSTMENT inventory_movement and an
 * STOCK_ADJUSTED audit row.
 */
final class StockAdjuster
{
    public const REASONS = [
        'DAMAGE', 'EXPIRY_WRITEOFF', 'SHRINKAGE', 'COUNT_CORRECTION',
        'SAMPLE', 'DONATION', 'OTHER',
    ];

    public static function adjust(int $userId, int $batchId, int $qtyDelta, string $reason, ?string $notes = null): int
    {
        if ($qtyDelta === 0) {
            throw new \InvalidArgumentException('qty_delta cannot be zero');
        }
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Invalid adjustment reason');
        }

        return (int) Db::transaction(function () use ($userId, $batchId, $qtyDelta, $reason, $notes) {
            $batch = Db::fetchOne(
                'SELECT id, branch_id, medicine_id, current_qty,
                        cost_per_unit::text AS cost_per_unit
                   FROM batches WHERE id = :id FOR UPDATE',
                [':id' => $batchId],
            );
            if ($batch === null) {
                throw new \RuntimeException('Batch not found');
            }
            $qtyBefore = (int) $batch['current_qty'];
            $qtyAfter  = $qtyBefore + $qtyDelta;
            if ($qtyAfter < 0) {
                throw new \RuntimeException("Resulting stock cannot be negative (would be {$qtyAfter})");
            }

            $costImpact = Money::round(
                Money::mul((string) $batch['cost_per_unit'], (string) abs($qtyDelta)),
                2,
            );

            $adjNumber = self::nextAdjustmentNumber();
            $row = Db::fetchOne(
                "INSERT INTO stock_adjustments (
                    branch_id, adjustment_number, medicine_id, batch_id,
                    qty_delta, reason, notes, performed_by, cost_impact
                 ) VALUES (
                    :b, :n, :m, :ba, :d, :r, :notes, :u, :ci
                 ) RETURNING id",
                [
                    ':b'    => (int) $batch['branch_id'],
                    ':n'    => $adjNumber,
                    ':m'    => (int) $batch['medicine_id'],
                    ':ba'   => (int) $batch['id'],
                    ':d'    => $qtyDelta,
                    ':r'    => $reason,
                    ':notes'=> $notes,
                    ':u'    => $userId,
                    ':ci'   => $costImpact,
                ],
            );
            $adjId = (int) $row['id'];

            Db::execute(
                'UPDATE batches SET current_qty = :q, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                [':q' => $qtyAfter, ':id' => (int) $batch['id']],
            );

            Db::execute(
                "INSERT INTO inventory_movements (
                    branch_id, medicine_id, batch_id, movement_type,
                    qty_delta, qty_before, qty_after,
                    ref_table, ref_id, performed_by
                 ) VALUES (
                    :b, :m, :ba, 'ADJUSTMENT',
                    :delta, :before, :after,
                    'stock_adjustments', :ref, :u
                 )",
                [
                    ':b'     => (int) $batch['branch_id'],
                    ':m'     => (int) $batch['medicine_id'],
                    ':ba'    => (int) $batch['id'],
                    ':delta' => $qtyDelta,
                    ':before'=> $qtyBefore,
                    ':after' => $qtyAfter,
                    ':ref'   => $adjId,
                    ':u'     => $userId,
                ],
            );

            Audit::write($userId, 'STOCK_ADJUSTED', 'stock_adjustments', $adjId, null, [
                'adjustment_number' => $adjNumber,
                'qty_delta'         => $qtyDelta,
                'reason'            => $reason,
                'cost_impact'       => $costImpact,
            ]);

            return $adjId;
        });
    }

    private static function nextAdjustmentNumber(): string
    {
        $today = (new \DateTimeImmutable('now'))->format('Ymd');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $count = (int) Db::fetchOne(
                "SELECT count(*) AS c FROM stock_adjustments WHERE created_at >= CURRENT_DATE"
            )['c'];
            $candidate = sprintf('ADJ-%s-%04d', $today, $count + 1 + $attempt);
            $exists = Db::fetchOne(
                'SELECT 1 AS e FROM stock_adjustments WHERE adjustment_number = :n LIMIT 1',
                [':n' => $candidate],
            );
            if ($exists === null) return $candidate;
        }
        return sprintf('ADJ-%s-%s', $today, substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
