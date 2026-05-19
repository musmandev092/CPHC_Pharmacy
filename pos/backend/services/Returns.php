<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;
use CPHC\Money;

/*
 * Returns state machine — port of backend/app/Services/Returns/ReturnService.php.
 *
 *  initiate($cashierId, $saleItemId, $qtyDisplay, $reason, $authManagerId, $physCond):
 *      Inserts row with status=PENDING_REVIEW + RETURN_QUARANTINE inventory_movement.
 *      Stock current_qty unchanged (physically out, awaiting adjudication).
 *
 *  adjudicate($returnId, $newStatus, $adminId, $notes):
 *      Sets status / adjudicated_by / adjudicated_at.
 *      APPROVED_RESTOCK    → batches.current_qty += qty_returned + RETURN_RESTOCK movement.
 *      RETURN_TO_SUPPLIER  → stock stays out, no movement (RETURN_QUARANTINE already
 *                            captured the physical removal).
 *      WRITE_OFF           → same as RETURN_TO_SUPPLIER.
 *      Then recomputeSaleStatus($originalSaleId).
 *
 * recomputeSaleStatus: sums adjudicated returns vs sold base units → sets
 * REFUNDED_PARTIAL / REFUNDED_FULL on the original sale.
 */
final class Returns
{
    public const REASONS = ['CUSTOMER_CHANGED_MIND','DAMAGED','WRONG_ITEM','ADVERSE_REACTION','EXPIRED','OTHER'];
    public const FINAL_STATUSES = ['APPROVED_RESTOCK','RETURN_TO_SUPPLIER','WRITE_OFF'];

    /**
     * Initiate a return.
     *
     * @param int  $qtyDisplay   Quantity in BASE units (e.g. tablets, ampoules)
     *                           — even if the original sale was per-pack, the
     *                           return is always expressed in base units so a
     *                           customer can bring back 3 loose tablets out of
     *                           a 10-tab strip.
     */
    public static function initiate(
        int $cashierId,
        int $saleItemId,
        int $qtyDisplay,
        string $reason,
        int $authorizingManagerId,
        ?string $physicalCondition = null,
        ?int $narcoticWitnessUserId = null,
    ): int {
        if ($qtyDisplay < 1) {
            throw new \InvalidArgumentException('qty_to_return must be ≥ 1');
        }
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException('Invalid return reason');
        }

        return (int) Db::transaction(function () use ($cashierId, $saleItemId, $qtyDisplay, $reason, $authorizingManagerId, $physicalCondition, $narcoticWitnessUserId) {
            $pdo = Db::pdo();

            // Lock sale_item + pull sale meta + controlled-schedule in one go.
            $saleItem = Db::fetchOne(
                "SELECT si.id, si.sale_id, si.medicine_id, si.batch_id,
                        si.qty_in_base_units, si.sold_unit_factor,
                        si.unit_mrp::text AS unit_mrp,
                        s.branch_id,
                        m.controlled_schedule::text AS controlled_schedule,
                        m.brand_name
                   FROM sale_items si
                   JOIN sales s ON s.id = si.sale_id
                   JOIN medicines m ON m.id = si.medicine_id
                  WHERE si.id = :id FOR UPDATE",
                [':id' => $saleItemId],
            );
            if ($saleItem === null) {
                throw new \RuntimeException('Sale item not found.');
            }

            // DRAP narcotic return rule — same two-person check as sale-time.
            // Witness must be supplied, must not equal the cashier, must be
            // a MANAGER or ADMIN.
            if ($saleItem['controlled_schedule'] === 'NARCOTIC') {
                if ($narcoticWitnessUserId === null) {
                    throw new \RuntimeException(
                        "Narcotic return requires a second-person witness (manager/admin) — '{$saleItem['brand_name']}' is a controlled drug.",
                    );
                }
                if ($narcoticWitnessUserId === $cashierId) {
                    throw new \RuntimeException('Narcotic witness must be a different user than the cashier.');
                }
                $w = Db::fetchOne(
                    "SELECT role::text AS role FROM users
                      WHERE id = :id AND is_active = TRUE AND deleted_at IS NULL",
                    [':id' => $narcoticWitnessUserId],
                );
                if ($w === null || !in_array($w['role'], ['MANAGER', 'ADMIN'], true)) {
                    throw new \RuntimeException('Narcotic witness must be an active manager or admin.');
                }
            }

            // qty_to_return is always in BASE units now (tablets, ampoules…).
            // Customer who bought 1 STRIP = 10 tabs can return 3 loose tabs.
            $unitFactor      = max(1, (int) $saleItem['sold_unit_factor']);
            $baseQtyToReturn = $qtyDisplay;

            $already = (int) Db::fetchOne(
                'SELECT COALESCE(SUM(qty_returned_units), 0) AS s FROM returns WHERE sale_item_id = :sid',
                [':sid' => (int) $saleItem['id']],
            )['s'];

            $remainingBase = (int) $saleItem['qty_in_base_units'] - $already;
            if ($baseQtyToReturn > $remainingBase) {
                // remaining now reported in BASE units (tablets/ampoules),
                // matching the form's qty input.
                throw new \RuntimeException(
                    $remainingBase <= 0
                        ? 'This item has already been fully returned.'
                        : "Only {$remainingBase} unit(s) are still returnable for this item."
                );
            }

            $refund = Money::round(
                Money::mul((string) $saleItem['unit_mrp'], (string) $baseQtyToReturn), 2,
            );
            $rNumber = self::nextReturnNumber();

            $row = Db::fetchOne(
                "INSERT INTO returns (
                    branch_id, return_number, original_sale_id, sale_item_id,
                    medicine_id, batch_id, qty_returned_units, refund_amount,
                    reason, physical_condition, initiated_by, authorized_by, status
                 ) VALUES (
                    :b, :rn, :osid, :sid,
                    :mid, :bid, :qty, :ref,
                    :reason::\"ReturnReason\", :phys, :init, :auth, 'PENDING_REVIEW'
                 ) RETURNING id",
                [
                    ':b'     => (int) $saleItem['branch_id'],
                    ':rn'    => $rNumber,
                    ':osid'  => (int) $saleItem['sale_id'],
                    ':sid'   => (int) $saleItem['id'],
                    ':mid'   => (int) $saleItem['medicine_id'],
                    ':bid'   => (int) $saleItem['batch_id'],
                    ':qty'   => $baseQtyToReturn,
                    ':ref'   => $refund,
                    ':reason'=> $reason,
                    ':phys'  => $physicalCondition,
                    ':init'  => $cashierId,
                    ':auth'  => $authorizingManagerId,
                ],
            );
            $returnId = (int) $row['id'];

            // qty_before == qty_after — stock is physically gone but the column
            // doesn't change until a manager approves a restock.
            $batchQty = (int) (Db::fetchOne(
                'SELECT current_qty FROM batches WHERE id = :id',
                [':id' => (int) $saleItem['batch_id']],
            )['current_qty'] ?? 0);

            Db::execute(
                "INSERT INTO inventory_movements (
                    branch_id, medicine_id, batch_id, movement_type,
                    qty_delta, qty_before, qty_after,
                    ref_table, ref_id, performed_by
                 ) VALUES (
                    :b, :m, :ba, 'RETURN_QUARANTINE',
                    :delta, :before, :after,
                    'returns', :ref, :u
                 )",
                [
                    ':b'     => (int) $saleItem['branch_id'],
                    ':m'     => (int) $saleItem['medicine_id'],
                    ':ba'    => (int) $saleItem['batch_id'],
                    ':delta' => $baseQtyToReturn,
                    ':before'=> $batchQty,
                    ':after' => $batchQty,
                    ':ref'   => $returnId,
                    ':u'     => $cashierId,
                ],
            );

            Audit::write($cashierId, 'RETURN_INITIATED', 'returns', $returnId, null, array_filter([
                'return_number'         => $rNumber,
                'original_sale_id'      => (int) $saleItem['sale_id'],
                'qty_returned_units'    => $baseQtyToReturn,
                'refund_amount'         => $refund,
                'authorizing_manager'   => $authorizingManagerId,
                'controlled_schedule'   => $saleItem['controlled_schedule'] !== 'NONE' ? $saleItem['controlled_schedule'] : null,
                'narcotic_witness_user' => $narcoticWitnessUserId,
            ], static fn ($v) => $v !== null));

            return $returnId;
        });
    }

    /**
     * Mark an already-adjudicated RETURN_TO_SUPPLIER row as physically shipped
     * back to the supplier.  Closes the supplier-returns queue loop.  Optional
     * reference (e.g. courier waybill, supplier credit-note #) is recorded.
     */
    public static function markSupplierReturnSent(int $returnId, int $adminId, ?string $supplierReference = null): void
    {
        $ret = Db::fetchOne(
            "SELECT id, return_number, status::text AS status, supplier_settled_at
               FROM returns WHERE id = :id",
            [':id' => $returnId],
        );
        if ($ret === null) {
            throw new \RuntimeException('Return not found.');
        }
        if ($ret['status'] !== 'RETURN_TO_SUPPLIER') {
            throw new \RuntimeException("Return {$ret['return_number']} is not flagged for supplier — current status: {$ret['status']}.");
        }
        if ($ret['supplier_settled_at'] !== null) {
            throw new \RuntimeException("Return {$ret['return_number']} was already marked sent.");
        }

        Db::transaction(function () use ($ret, $adminId, $supplierReference) {
            Db::execute(
                "UPDATE returns
                    SET supplier_settled_at = CURRENT_TIMESTAMP,
                        supplier_settled_by = :u,
                        supplier_reference  = :ref,
                        updated_at          = CURRENT_TIMESTAMP
                  WHERE id = :id",
                [':u' => $adminId, ':ref' => $supplierReference, ':id' => (int) $ret['id']],
            );
            Audit::write($adminId, 'SUPPLIER_RETURN_SENT', 'returns', (int) $ret['id'],
                ['supplier_settled_at' => null, 'supplier_settled_by' => null],
                [
                    'return_number'     => (string) $ret['return_number'],
                    'supplier_reference'=> $supplierReference,
                ],
            );
        });
    }

    public static function adjudicate(int $returnId, string $newStatus, int $adminId, ?string $notes = null): void
    {
        if (!in_array($newStatus, self::FINAL_STATUSES, true)) {
            throw new \InvalidArgumentException('Pick APPROVED_RESTOCK, RETURN_TO_SUPPLIER, or WRITE_OFF.');
        }
        $ret = Db::fetchOne(
            "SELECT id, branch_id, return_number, original_sale_id, medicine_id, batch_id,
                    qty_returned_units, status::text AS status
               FROM returns WHERE id = :id",
            [':id' => $returnId],
        );
        if ($ret === null)                          throw new \RuntimeException('Return not found.');
        if ($ret['status'] !== 'PENDING_REVIEW')    throw new \RuntimeException("Return {$ret['return_number']} is already adjudicated.");

        Db::transaction(function () use ($ret, $newStatus, $adminId, $notes) {
            $before = (int) ($ret['status'] === 'PENDING_REVIEW' ? 0 : 0); // unused placeholder
            $beforeRow = ['status' => 'PENDING_REVIEW']; // for audit before-value

            Db::execute(
                "UPDATE returns SET status = :ns::\"ReturnStatus\",
                       adjudicated_by = :a, adjudicated_at = CURRENT_TIMESTAMP,
                       adjudication_notes = :notes, updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id",
                [':ns' => $newStatus, ':a' => $adminId, ':notes' => $notes, ':id' => (int) $ret['id']],
            );

            // Lock the batch once — both RESTOCK and the no-op dispositions
            // need qty_before for the audit-trail inventory_movements row.
            $batch = Db::fetchOne(
                'SELECT current_qty, cost_per_unit::text AS cost_per_unit FROM batches WHERE id = :id FOR UPDATE',
                [':id' => (int) $ret['batch_id']],
            );
            $batchQty = $batch !== null ? (int) $batch['current_qty'] : 0;
            $costPerUnit = (string) ($batch['cost_per_unit'] ?? '0');

            if ($newStatus === 'APPROVED_RESTOCK' && $batch !== null) {
                $after = $batchQty + (int) $ret['qty_returned_units'];
                Db::execute(
                    'UPDATE batches SET current_qty = :q, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                    [':q' => $after, ':id' => (int) $ret['batch_id']],
                );
                Db::execute(
                    "INSERT INTO inventory_movements (
                        branch_id, medicine_id, batch_id, movement_type,
                        qty_delta, qty_before, qty_after,
                        ref_table, ref_id, performed_by
                     ) VALUES (
                        :b, :m, :ba, 'RETURN_RESTOCK',
                        :delta, :before, :after,
                        'returns', :ref, :u
                     )",
                    [
                        ':b'     => (int) $ret['branch_id'],
                        ':m'     => (int) $ret['medicine_id'],
                        ':ba'    => (int) $ret['batch_id'],
                        ':delta' => (int) $ret['qty_returned_units'],
                        ':before'=> $batchQty,
                        ':after' => $after,
                        ':ref'   => (int) $ret['id'],
                        ':u'     => $adminId,
                    ],
                );
            } elseif ($newStatus === 'WRITE_OFF' || $newStatus === 'RETURN_TO_SUPPLIER') {
                // Stock was already physically out at sale-time; we record a
                // zero-delta WRITE_OFF movement so the audit trail explicitly
                // shows where the qty went (supplier vs. destroyed).  The
                // disposition is in the audit_log payload below.
                Db::execute(
                    "INSERT INTO inventory_movements (
                        branch_id, medicine_id, batch_id, movement_type,
                        qty_delta, qty_before, qty_after,
                        ref_table, ref_id, performed_by
                     ) VALUES (
                        :b, :m, :ba, 'WRITE_OFF',
                        0, :q, :q,
                        'returns', :ref, :u
                     )",
                    [
                        ':b'   => (int) $ret['branch_id'],
                        ':m'   => (int) $ret['medicine_id'],
                        ':ba'  => (int) $ret['batch_id'],
                        ':q'   => $batchQty,
                        ':ref' => (int) $ret['id'],
                        ':u'   => $adminId,
                    ],
                );
            }

            self::recomputeSaleStatus((int) $ret['original_sale_id']);

            $costImpact = Money::round(
                Money::mul($costPerUnit, (string) (int) $ret['qty_returned_units']), 2,
            );

            Audit::write($adminId, 'RETURN_ADJUDICATED', 'returns', (int) $ret['id'], $beforeRow, [
                'return_number'      => (string) $ret['return_number'],
                'new_status'         => $newStatus,
                'qty_returned_units' => (int) $ret['qty_returned_units'],
                'cost_impact'        => $costImpact,
                'adjudication_notes' => $notes,
            ]);
        });
    }

    /**
     * Update original sale.status to REFUNDED_PARTIAL or REFUNDED_FULL based on
     * adjudicated returns.  Never touches VOIDED sales.
     */
    public static function recomputeSaleStatus(int $saleId): void
    {
        $sale = Db::fetchOne(
            "SELECT id, status::text AS status FROM sales WHERE id = :id",
            [':id' => $saleId],
        );
        if ($sale === null || $sale['status'] === 'VOIDED') return;

        $soldBase = (int) (Db::fetchOne(
            'SELECT COALESCE(SUM(qty_in_base_units), 0) AS s FROM sale_items WHERE sale_id = :s',
            [':s' => $saleId],
        )['s'] ?? 0);

        $returnedBase = (int) (Db::fetchOne(
            "SELECT COALESCE(SUM(qty_returned_units), 0) AS s
               FROM returns
              WHERE original_sale_id = :s
                AND status IN ('APPROVED_RESTOCK','RETURN_TO_SUPPLIER','WRITE_OFF')",
            [':s' => $saleId],
        )['s'] ?? 0);

        if ($soldBase === 0 || $returnedBase === 0) return;

        $new = $returnedBase >= $soldBase ? 'REFUNDED_FULL' : 'REFUNDED_PARTIAL';
        if ($sale['status'] !== $new) {
            Db::execute(
                "UPDATE sales SET status = :s::\"SaleStatus\" WHERE id = :id",
                [':s' => $new, ':id' => $saleId],
            );
        }
    }

    private static function nextReturnNumber(): string
    {
        $today = (new \DateTimeImmutable('now'))->format('Ymd');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $count = (int) Db::fetchOne(
                "SELECT count(*) AS c FROM returns WHERE created_at >= CURRENT_DATE"
            )['c'];
            $candidate = sprintf('RET-%s-%04d', $today, $count + 1 + $attempt);
            $exists = Db::fetchOne(
                'SELECT 1 AS e FROM returns WHERE return_number = :n LIMIT 1',
                [':n' => $candidate],
            );
            if ($exists === null) return $candidate;
        }
        return sprintf('RET-%s-%s', $today, substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
