<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;
use CPHC\Money;

/*
 * Goods-received note (GRN) — draft, post, cancel.
 * Port of backend/app/Services/Grn/GrnPoster.php.
 *
 *   createDraft($userId, $supplierId, $branchId): int
 *   post($grnId, $userId, $lines, $invoiceNumber=null, $invoiceDate=null, $notes=null)
 *   cancelDraft($grnId, $userId)
 *
 * post() runs in SERIALIZABLE.  For each line:
 *   1. Compute blended_cost / mrp_per_base / line_total (CostBlender).
 *   2. Find-or-create a batch keyed on (medicine_id, batch_number, expiry_date).
 *   3. Bump received_qty / foc_qty / current_qty; refresh cost/MRP.
 *   4. Emit a GRN_RECEIPT inventory_movement.
 * Header → status=POSTED, subtotal/grand_total recomputed.  Audit row written.
 */
final class Grn
{
    public static function createDraft(int $userId, int $supplierId, int $branchId = 1): int
    {
        return (int) Db::transaction(function () use ($userId, $supplierId, $branchId) {
            Db::pdo()->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            $grnNumber = self::nextGrnNumber();
            $row = Db::fetchOne(
                "INSERT INTO grn_documents
                    (branch_id, grn_number, supplier_id, received_by, status,
                     subtotal, tax_total, discount_total, grand_total)
                  VALUES
                    (:b, :n, :s, :u, 'DRAFT', '0', '0', '0', '0')
                  RETURNING id",
                [':b' => $branchId, ':n' => $grnNumber, ':s' => $supplierId, ':u' => $userId],
            );
            $id = (int) $row['id'];
            Audit::write($userId, 'GRN_DRAFT_CREATED', 'grn_documents', $id, null,
                ['grn_number' => $grnNumber, 'supplier_id' => $supplierId]);
            return $id;
        });
    }

    /**
     * Post a draft GRN.
     * @param list<array{medicine_id:int, batch_number:string, expiry_date:string,
     *                   paid_qty:int, foc_qty?:int, unit_cost:string,
     *                   mrp_per_purchase_unit:string}> $lines
     */
    public static function post(
        int $grnId,
        int $userId,
        array $lines,
        ?string $invoiceNumber = null,
        ?string $invoiceDate = null,
        ?string $notes = null,
    ): int {
        $grn = Db::fetchOne(
            "SELECT id, branch_id, supplier_id, grn_number, status::text AS status
               FROM grn_documents WHERE id = :id",
            [':id' => $grnId],
        );
        if ($grn === null)            throw new \RuntimeException('GRN not found.');
        if ($grn['status'] !== 'DRAFT') throw new \RuntimeException("GRN {$grn['grn_number']} is not DRAFT — cannot post.");
        if (count($lines) === 0)      throw new \InvalidArgumentException('GRN must have at least one line.');

        return (int) Db::transaction(function () use ($grn, $userId, $lines, $invoiceNumber, $invoiceDate, $notes) {
            $pdo = Db::pdo();
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

            // Wipe any prior draft lines (idempotent re-post safety).
            Db::execute('DELETE FROM grn_lines WHERE grn_id = :g', [':g' => (int) $grn['id']]);

            $subtotal = Money::zero(Money::SCALE_WORK);

            foreach ($lines as $line) {
                $medicine = Db::fetchOne(
                    'SELECT id, brand_name, units_per_purchase, is_active, deleted_at
                       FROM medicines WHERE id = :id',
                    [':id' => (int) $line['medicine_id']],
                );
                if ($medicine === null) {
                    throw new \RuntimeException("Medicine {$line['medicine_id']} not found");
                }
                if (!$medicine['is_active'] || $medicine['deleted_at'] !== null) {
                    throw new \RuntimeException(
                        "Medicine '{$medicine['brand_name']}' is no longer active — cannot receive stock for it."
                    );
                }

                $paidQty          = (int) $line['paid_qty'];
                $focQty           = (int) ($line['foc_qty'] ?? 0);
                $unitsPerPurchase = (int) $medicine['units_per_purchase'];
                $unitCost         = trim((string) ($line['unit_cost'] ?? ''));
                $mrpPerPurchase   = trim((string) ($line['mrp_per_purchase_unit'] ?? ''));
                $batchNum         = trim((string) ($line['batch_number'] ?? ''));

                if ($paidQty < 1) {
                    throw new \InvalidArgumentException('paid_qty must be ≥ 1 on every line.');
                }
                if ($focQty < 0) {
                    throw new \InvalidArgumentException('foc_qty cannot be negative.');
                }
                if ($batchNum === '') {
                    throw new \InvalidArgumentException('Batch number is required on every line.');
                }
                if ($unitCost === '' || Money::cmp($unitCost, '0') <= 0) {
                    throw new \InvalidArgumentException("Unit cost must be > 0 for {$medicine['brand_name']}.");
                }
                if ($mrpPerPurchase === '' || Money::cmp($mrpPerPurchase, '0') <= 0) {
                    throw new \InvalidArgumentException("MRP per purchase unit must be > 0 for {$medicine['brand_name']}.");
                }

                $expiry = (new \DateTimeImmutable((string) $line['expiry_date']))->format('Y-m-d');
                $today  = (new \DateTimeImmutable('today'))->format('Y-m-d');
                if ($expiry < $today) {
                    throw new \InvalidArgumentException(
                        "Cannot receive batch {$batchNum} ({$medicine['brand_name']}) — expiry {$expiry} is in the past.",
                    );
                }

                $totalBase = ($paidQty + $focQty) * $unitsPerPurchase;

                $blendedCost = CostBlender::blendedCostPerBaseUnit($paidQty, $focQty, $unitCost, $unitsPerPurchase);
                $mrpPerBase  = CostBlender::mrpPerBaseUnit($mrpPerPurchase, $unitsPerPurchase);
                $lineTotal   = CostBlender::lineTotal($paidQty, $unitCost);
                $subtotal    = Money::add($subtotal, $lineTotal);

                // Persist the line.
                Db::execute(
                    "INSERT INTO grn_lines (
                        grn_id, medicine_id, batch_number, expiry_date,
                        paid_qty, foc_qty, qty_in_base_units,
                        unit_cost, mrp_per_base_unit, line_total
                     ) VALUES (
                        :g, :m, :bn, :exp,
                        :pq, :fq, :qb,
                        :uc, :mpb, :lt
                     )",
                    [
                        ':g'   => (int) $grn['id'],
                        ':m'   => (int) $medicine['id'],
                        ':bn'  => $batchNum,
                        ':exp' => $expiry,
                        ':pq'  => $paidQty,
                        ':fq'  => $focQty,
                        ':qb'  => $totalBase,
                        ':uc'  => $unitCost,
                        ':mpb' => $mrpPerBase,
                        ':lt'  => $lineTotal,
                    ],
                );

                // Find-or-create batch (locked).
                $batch = Db::fetchOne(
                    "SELECT id, current_qty, foc_qty
                       FROM batches
                      WHERE medicine_id = :m
                        AND batch_number = :bn
                        AND expiry_date = :exp
                      FOR UPDATE",
                    [':m' => (int) $medicine['id'], ':bn' => $batchNum, ':exp' => $expiry],
                );

                $qtyBefore = $batch !== null ? (int) $batch['current_qty'] : 0;

                if ($batch !== null) {
                    Db::execute(
                        "UPDATE batches
                            SET received_qty = current_qty + :delta,
                                foc_qty      = foc_qty + :foc,
                                current_qty  = current_qty + :delta,
                                cost_per_unit = :cost,
                                mrp_per_unit  = :mrp,
                                updated_at = CURRENT_TIMESTAMP
                          WHERE id = :id",
                        [
                            ':delta' => $totalBase,
                            ':foc'   => $focQty * $unitsPerPurchase,
                            ':cost'  => $blendedCost,
                            ':mrp'   => $mrpPerBase,
                            ':id'    => (int) $batch['id'],
                        ],
                    );
                    $batchId = (int) $batch['id'];
                } else {
                    $newBatch = Db::fetchOne(
                        "INSERT INTO batches (
                            branch_id, medicine_id, supplier_id, grn_id,
                            batch_number, expiry_date,
                            received_qty, foc_qty, current_qty,
                            cost_per_unit, mrp_per_unit
                         ) VALUES (
                            :b, :m, :s, :g,
                            :bn, :exp,
                            :rq, :fq, :cq,
                            :cost, :mrp
                         ) RETURNING id",
                        [
                            ':b'    => (int) $grn['branch_id'],
                            ':m'    => (int) $medicine['id'],
                            ':s'    => (int) $grn['supplier_id'],
                            ':g'    => (int) $grn['id'],
                            ':bn'   => (string) $line['batch_number'],
                            ':exp'  => $expiry,
                            ':rq'   => $totalBase,
                            ':fq'   => $focQty * $unitsPerPurchase,
                            ':cq'   => $totalBase,
                            ':cost' => $blendedCost,
                            ':mrp'  => $mrpPerBase,
                        ],
                    );
                    $batchId = (int) $newBatch['id'];
                }

                // GRN_RECEIPT movement.
                Db::execute(
                    "INSERT INTO inventory_movements (
                        branch_id, medicine_id, batch_id, movement_type,
                        qty_delta, qty_before, qty_after,
                        ref_table, ref_id, performed_by
                     ) VALUES (
                        :b, :m, :ba, 'GRN_RECEIPT',
                        :delta, :before, :after,
                        'grn_documents', :ref, :u
                     )",
                    [
                        ':b'     => (int) $grn['branch_id'],
                        ':m'     => (int) $medicine['id'],
                        ':ba'    => $batchId,
                        ':delta' => $totalBase,
                        ':before'=> $qtyBefore,
                        ':after' => $qtyBefore + $totalBase,
                        ':ref'   => (int) $grn['id'],
                        ':u'     => $userId,
                    ],
                );
            }

            $subtotal2 = Money::round($subtotal, 2);

            Db::execute(
                "UPDATE grn_documents
                    SET status = 'POSTED',
                        posted_by = :u,
                        posted_at = CURRENT_TIMESTAMP,
                        invoice_number = :inv,
                        invoice_date = :idate,
                        notes = :notes,
                        subtotal = :sub,
                        grand_total = :total,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id",
                [
                    ':u'    => $userId,
                    ':inv'  => $invoiceNumber,
                    ':idate'=> $invoiceDate,
                    ':notes'=> $notes,
                    ':sub'  => $subtotal2,
                    ':total'=> $subtotal2,
                    ':id'   => (int) $grn['id'],
                ],
            );

            Audit::write($userId, 'GRN_POSTED', 'grn_documents', (int) $grn['id'], null, [
                'grn_number'  => (string) $grn['grn_number'],
                'supplier_id' => (int) $grn['supplier_id'],
                'subtotal'    => $subtotal2,
                'lines_count' => count($lines),
            ]);

            return (int) $grn['id'];
        });
    }

    public static function cancelDraft(int $grnId, int $userId): void
    {
        $grn = Db::fetchOne(
            "SELECT id, grn_number, status::text AS status FROM grn_documents WHERE id = :id",
            [':id' => $grnId],
        );
        if ($grn === null)               throw new \RuntimeException('GRN not found.');
        if ($grn['status'] !== 'DRAFT')  throw new \RuntimeException('Only DRAFT GRNs can be cancelled.');

        Db::transaction(function () use ($grn, $userId) {
            Db::execute("UPDATE grn_documents SET status = 'CANCELLED' WHERE id = :id",
                [':id' => (int) $grn['id']]);
            Audit::write($userId, 'GRN_CANCELLED', 'grn_documents', (int) $grn['id'], null,
                ['grn_number' => (string) $grn['grn_number']]);
        });
    }

    private static function nextGrnNumber(): string
    {
        $today = (new \DateTimeImmutable('now'))->format('Ymd');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $count = (int) Db::fetchOne(
                "SELECT count(*) AS c FROM grn_documents WHERE created_at >= CURRENT_DATE"
            )['c'];
            $candidate = sprintf('GRN-%s-%04d', $today, $count + 1 + $attempt);
            $exists = Db::fetchOne(
                'SELECT 1 AS e FROM grn_documents WHERE grn_number = :n LIMIT 1',
                [':n' => $candidate],
            );
            if ($exists === null) return $candidate;
        }
        return sprintf('GRN-%s-%s', $today, substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
