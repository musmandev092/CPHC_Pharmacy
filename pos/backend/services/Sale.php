<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;
use CPHC\Money;

/*
 * Atomic sale commit.  Port of backend/app/Services/Pos/SaleService.php.
 *
 * Steps inside the transaction:
 *   1. SET TRANSACTION ISOLATION LEVEL SERIALIZABLE.
 *   2. DRAP narcotic gate: refuse if a NARCOTIC-scheduled medicine is in the
 *      cart unless prescriber_license + witness ≠ cashier + witness role
 *      ∈ {MANAGER, ADMIN} are all supplied.  The Postgres trigger phase5_drap
 *      enforces this again at write-time — defence in depth.
 *   3. Generate receipt_number INV-YYYYMMDD-NNNN (next-available, locked).
 *   4. Per medicine, SELECT … FOR UPDATE on candidate batches sorted by
 *      expiry_date ASC, then Fefo::allocate() to spread the requested qty
 *      across batches starting from soonest-to-expire.
 *   5. Write sales row → sale_items rows (one per consumed batch) →
 *      inventory_movements rows → decrement batches.current_qty.
 *   6. Bump cashier_sessions totals if a session is open.
 *   7. Write SALE_COMPLETED audit row (+ NARCOTIC_DISPENSED + DISCOUNT_AUTHORIZED
 *      rows when applicable).
 *
 * On Postgres serialization_failure (40001) the whole transaction is retried
 * ONCE before bubbling.
 */
final class Sale
{
    /**
     * @param array{
     *   items: list<array{medicine_id:int, qty_sold_display:int, sold_unit_label:string, sold_unit_factor:int, unit_mrp:string}>,
     *   payment_mode: string,
     *   customer_name?: ?string, customer_phone?: ?string,
     *   discount_total?: string, discount_authorized_by?: ?int,
     *   amount_tendered?: ?string,
     *   has_controlled_drug?: bool,
     *   doctor_name?: ?string, patient_name?: ?string, patient_phone?: ?string, patient_address?: ?string,
     *   prescriber_license_number?: ?string,
     *   narcotic_witness_user_id?: ?int,
     *   cashier_session_id?: ?int,
     *   branch_id?: int,
     * } $input
     */
    public static function commit(int $cashierId, array $input): int
    {
        $attempts = 0;
        retry:
        try {
            return Db::transaction(function () use ($cashierId, $input) {
                $pdo = Db::pdo();
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');

                $branchId = (int) ($input['branch_id'] ?? 1);
                $items    = $input['items'] ?? [];
                if (count($items) === 0) {
                    throw new \InvalidArgumentException('Sale must contain at least one item');
                }

                // ── DRAP narcotic gate ────────────────────────────────────
                $medicineIds = array_unique(array_map(fn ($r) => (int) $r['medicine_id'], $items));
                $placeholders = implode(',', array_fill(0, count($medicineIds), '?'));
                $st = $pdo->prepare("SELECT EXISTS (
                    SELECT 1 FROM medicines
                     WHERE id IN ($placeholders) AND controlled_schedule = 'NARCOTIC'
                ) AS has_narcotic");
                $st->execute(array_values($medicineIds));
                $hasNarcotic = (bool) $st->fetch()['has_narcotic'];

                $witnessId = isset($input['narcotic_witness_user_id']) ? (int) $input['narcotic_witness_user_id'] : null;
                $witnessAt = null;

                if ($hasNarcotic) {
                    $license = trim((string) ($input['prescriber_license_number'] ?? ''));
                    if ($license === '') {
                        throw new \InvalidArgumentException('Narcotic dispense requires the prescriber\'s DRAP license number.');
                    }
                    if (!$witnessId) {
                        throw new \InvalidArgumentException('Narcotic dispense requires a manager/admin witness PIN — two-person rule.');
                    }
                    if ($witnessId === $cashierId) {
                        throw new \InvalidArgumentException('Witness cannot be the cashier (two-person rule).');
                    }
                    $witness = Db::fetchOne(
                        "SELECT id FROM users
                          WHERE id = :id AND is_active = TRUE AND deleted_at IS NULL
                            AND role IN ('MANAGER', 'ADMIN')",
                        [':id' => $witnessId],
                    );
                    if ($witness === null) {
                        throw new \InvalidArgumentException('Witness account not found or not authorised.');
                    }
                    $witnessAt = (new \DateTimeImmutable('now'))->format(\DateTimeImmutable::ATOM);
                }

                // ── Receipt number ────────────────────────────────────────
                $receiptNumber = self::nextReceiptNumber();

                // ── Compute totals (server-side; never trust client) ──────
                if (count($items) === 0) {
                    throw new \InvalidArgumentException('Cart is empty.');
                }
                $subtotalSum = Money::zero(Money::SCALE_WORK);
                $itemLines = [];
                foreach ($items as $row) {
                    $qtyDisplay = (int) $row['qty_sold_display'];
                    if ($qtyDisplay < 1) {
                        throw new \InvalidArgumentException('Every cart line must have qty ≥ 1.');
                    }
                    if ($qtyDisplay > 9999) {
                        throw new \InvalidArgumentException("Quantity {$qtyDisplay} is unrealistically large — confirm the scan.");
                    }
                    $unitMrp = (string) $row['unit_mrp'];
                    if (Money::cmp($unitMrp, '0') < 0) {
                        throw new \InvalidArgumentException('Unit MRP cannot be negative.');
                    }
                    $qtyBaseUnits = $qtyDisplay * max(1, (int) $row['sold_unit_factor']);
                    $lineSub = SaleCalculator::lineSubtotal($qtyBaseUnits, $unitMrp);
                    $subtotalSum = Money::add($subtotalSum, $lineSub);
                    $itemLines[] = ['input' => $row, 'qty_base_units' => $qtyBaseUnits, 'line_subtotal' => $lineSub];
                }
                $subtotal     = Money::round($subtotalSum, 2);
                $discount     = Money::round((string) ($input['discount_total'] ?? '0'), 2);

                // Discount must be in [0, subtotal].  Negative would inflate the
                // grand total ("surcharge by typo"); >subtotal would mint a
                // negative grand total.  Both block before the row is written.
                if (Money::cmp($discount, '0') < 0) {
                    throw new \InvalidArgumentException('Discount cannot be negative.');
                }
                if (Money::cmp($discount, $subtotal) > 0) {
                    throw new \InvalidArgumentException("Discount (PKR " . Money::fmt($discount) . ") cannot exceed subtotal (PKR " . Money::fmt($subtotal) . ").");
                }

                $grandTotal   = Money::round(Money::sub($subtotal, $discount), 2);
                if (Money::isNegative($grandTotal)) {
                    throw new \InvalidArgumentException('Grand total cannot be negative');
                }

                $paymentMode = (string) ($input['payment_mode'] ?? 'CASH');
                if (!in_array($paymentMode, ['CASH', 'CARD', 'OTHER'], true)) {
                    throw new \InvalidArgumentException('Invalid payment_mode');
                }

                $tendered = isset($input['amount_tendered']) && $input['amount_tendered'] !== null && $input['amount_tendered'] !== ''
                    ? Money::round((string) $input['amount_tendered'], 2)
                    : null;
                if ($tendered !== null && Money::cmp($tendered, '0') < 0) {
                    throw new \InvalidArgumentException('Amount tendered cannot be negative.');
                }
                if ($paymentMode === 'CASH') {
                    if ($tendered === null || Money::cmp($tendered, $grandTotal) < 0) {
                        throw new \InvalidArgumentException('Cash sale requires amount tendered ≥ grand total.');
                    }
                }
                $change = $tendered !== null ? SaleCalculator::change($tendered, $grandTotal) : null;

                // ── Open cashier session (if any) ────────────────────────
                $sessionId = $input['cashier_session_id'] ?? null;
                if ($sessionId === null) {
                    $sess = Db::fetchOne(
                        "SELECT id FROM cashier_sessions
                          WHERE cashier_id = :c AND status = 'OPEN'
                          ORDER BY opened_at DESC LIMIT 1",
                        [':c' => $cashierId],
                    );
                    $sessionId = $sess['id'] ?? null;
                }

                // ── Insert sales header ──────────────────────────────────
                $st = $pdo->prepare(
                    "INSERT INTO sales (
                        branch_id, receipt_number, cashier_id, cashier_session_id,
                        customer_name, customer_phone, has_controlled_drug, doctor_name,
                        prescriber_license_number, narcotic_witness_user_id, narcotic_witness_at,
                        patient_name, patient_phone, patient_address,
                        subtotal, tax_total, discount_total, discount_authorized_by,
                        pos_service_fee, grand_total, amount_tendered, change_returned,
                        payment_mode, status, sold_at
                     ) VALUES (
                        :branch, :receipt, :cashier, :session,
                        :cname, :cphone, :hcd, :doctor,
                        :license, :witness, :witness_at,
                        :pname, :pphone, :paddr,
                        :sub, '0', :disc, :disc_by,
                        '0', :grand, :tendered, :change,
                        :pmode, 'COMPLETED', CURRENT_TIMESTAMP
                     ) RETURNING id"
                );
                $st->execute([
                    ':branch'     => $branchId,
                    ':receipt'    => $receiptNumber,
                    ':cashier'    => $cashierId,
                    ':session'    => $sessionId,
                    ':cname'      => $input['customer_name']  ?? null,
                    ':cphone'     => $input['customer_phone'] ?? null,
                    ':hcd'        => !empty($input['has_controlled_drug']) ? 'true' : 'false',
                    ':doctor'     => $input['doctor_name'] ?? null,
                    ':license'    => $hasNarcotic ? trim((string) $input['prescriber_license_number']) : ($input['prescriber_license_number'] ?? null),
                    ':witness'    => $witnessId,
                    ':witness_at' => $witnessAt,
                    ':pname'      => $input['patient_name']    ?? null,
                    ':pphone'     => $input['patient_phone']   ?? null,
                    ':paddr'      => $input['patient_address'] ?? null,
                    ':sub'        => $subtotal,
                    ':disc'       => $discount,
                    ':disc_by'    => $input['discount_authorized_by'] ?? null,
                    ':grand'      => $grandTotal,
                    ':tendered'   => $tendered,
                    ':change'     => $change,
                    ':pmode'      => $paymentMode,
                ]);
                $saleId = (int) $st->fetch()['id'];

                // ── Per-item FEFO + writes ───────────────────────────────
                foreach ($itemLines as $line) {
                    $row          = $line['input'];
                    $medicineId   = (int) $row['medicine_id'];
                    $qtyNeeded    = (int) $line['qty_base_units'];
                    $unitLabel    = (string) $row['sold_unit_label'];
                    $unitFactor   = max(1, (int) $row['sold_unit_factor']);

                    // Lock candidate batches with FOR UPDATE.
                    $batchesSt = $pdo->prepare(
                        "SELECT id, current_qty, cost_per_unit::text AS cost_per_unit,
                                mrp_per_unit::text AS mrp_per_unit, expiry_date::text AS expiry_date,
                                is_quarantined, is_expired
                           FROM batches
                          WHERE medicine_id = :m
                            AND current_qty > 0
                            AND is_quarantined = FALSE
                            AND is_expired = FALSE
                            AND expiry_date > CURRENT_DATE
                          ORDER BY expiry_date ASC, id ASC
                          FOR UPDATE"
                    );
                    $batchesSt->execute([':m' => $medicineId]);
                    $batches = $batchesSt->fetchAll();

                    Fefo::assertSortedByExpiry($batches);
                    $allocations = Fefo::allocate($batches, $qtyNeeded, $medicineId);

                    foreach ($allocations as $alloc) {
                        $batch     = $alloc['batch'];
                        $deduction = (int) $alloc['deduction'];
                        $qtyBefore = (int) $batch['current_qty'];
                        $qtyAfter  = $qtyBefore - $deduction;

                        // Decrement batch (no race because we hold FOR UPDATE).
                        Db::execute(
                            'UPDATE batches SET current_qty = :q, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
                            [':q' => $qtyAfter, ':id' => (int) $batch['id']],
                        );

                        $unitMrp    = (string) $batch['mrp_per_unit'];
                        $unitCost   = (string) $batch['cost_per_unit'];
                        $lineSub    = SaleCalculator::lineSubtotal($deduction, $unitMrp);
                        $qtyDisplay = (int) ceil($deduction / $unitFactor);

                        Db::execute(
                            "INSERT INTO sale_items (
                                sale_id, medicine_id, batch_id,
                                qty_in_base_units, sold_unit_label, sold_unit_factor, qty_sold_display,
                                unit_cost, unit_mrp, line_subtotal, line_tax, line_discount, line_total
                             ) VALUES (
                                :s, :m, :b,
                                :qb, :label, :factor, :qd,
                                :cost, :mrp, :sub, '0', '0', :total
                             )",
                            [
                                ':s'      => $saleId,
                                ':m'      => $medicineId,
                                ':b'      => (int) $batch['id'],
                                ':qb'     => $deduction,
                                ':label'  => $unitLabel,
                                ':factor' => $unitFactor,
                                ':qd'     => $qtyDisplay,
                                ':cost'   => $unitCost,
                                ':mrp'    => $unitMrp,
                                ':sub'    => $lineSub,
                                ':total'  => $lineSub,
                            ],
                        );

                        Db::execute(
                            "INSERT INTO inventory_movements (
                                branch_id, medicine_id, batch_id, movement_type,
                                qty_delta, qty_before, qty_after,
                                ref_table, ref_id, performed_by
                             ) VALUES (
                                :branch, :m, :b, 'SALE',
                                :delta, :before, :after,
                                'sales', :ref, :user
                             )",
                            [
                                ':branch' => $branchId,
                                ':m'      => $medicineId,
                                ':b'      => (int) $batch['id'],
                                ':delta'  => -$deduction,
                                ':before' => $qtyBefore,
                                ':after'  => $qtyAfter,
                                ':ref'    => $saleId,
                                ':user'   => $cashierId,
                            ],
                        );
                    }
                }

                // ── Bump session totals ──────────────────────────────────
                if ($sessionId) {
                    Db::execute(
                        "UPDATE cashier_sessions
                            SET total_sales_count = total_sales_count + 1,
                                total_cash_sales  = CASE WHEN :pmode = 'CASH'
                                                         THEN total_cash_sales + :total
                                                         ELSE total_cash_sales END,
                                updated_at = CURRENT_TIMESTAMP
                          WHERE id = :id",
                        [':pmode' => $paymentMode, ':total' => $grandTotal, ':id' => $sessionId],
                    );
                }

                // ── Audit trail ──────────────────────────────────────────
                Audit::write(
                    $cashierId, 'SALE_COMPLETED', 'sales', $saleId, null,
                    [
                        'receipt_number' => $receiptNumber,
                        'grand_total'    => $grandTotal,
                        'payment_mode'   => $paymentMode,
                        'items_count'    => count($items),
                    ],
                );
                if ($hasNarcotic) {
                    Audit::write(
                        $cashierId, 'NARCOTIC_DISPENSED', 'sales', $saleId, null,
                        [
                            'receipt_number'            => $receiptNumber,
                            'cashier_id'                => $cashierId,
                            'witness_id'                => $witnessId,
                            'prescriber_license_number' => trim((string) $input['prescriber_license_number']),
                            'medicine_ids'              => $medicineIds,
                        ],
                    );
                }
                if (!empty($input['discount_authorized_by']) && Money::cmp($discount, '0') > 0) {
                    Audit::write(
                        (int) $input['discount_authorized_by'],
                        'DISCOUNT_AUTHORIZED', 'sales', $saleId, null,
                        [
                            'receipt_number' => $receiptNumber,
                            'discount_total' => $discount,
                            'grand_total'    => $grandTotal,
                            'authorized_by'  => (int) $input['discount_authorized_by'],
                            'cashier_id'     => $cashierId,
                        ],
                    );
                }

                return $saleId;
            });
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '40001' && $attempts === 0) {
                $attempts++;
                usleep(50_000);
                goto retry;
            }
            throw $e;
        }
    }

    /**
     * Generate INV-YYYYMMDD-NNNN.  Caller must be inside the transaction.
     *
     * Concurrency: two cashiers committing within the same millisecond on the
     * same day previously could both read the same count(*) and generate
     * identical receipt numbers.  We take a per-day advisory lock that's held
     * for the rest of the current transaction — anything else trying to mint
     * a receipt for today blocks until we commit.  Cheap (no row locks) and
     * scoped (auto-released on commit/rollback).
     */
    private static function nextReceiptNumber(): string
    {
        $today    = (new \DateTimeImmutable('now'))->format('Ymd');
        $lockKey  = (int) hexdec(substr(sha1('receipt_seq:' . $today), 0, 15));
        Db::execute('SELECT pg_advisory_xact_lock(:k)', [':k' => $lockKey]);

        // Now safe to read-then-write — no other tx can interleave on today's
        // counter.  We also keep the unique-violation fallback in case of any
        // schema-level race we haven't accounted for.
        $count = (int) Db::fetchOne(
            "SELECT count(*) AS c FROM sales WHERE sold_at >= CURRENT_DATE"
        )['c'];
        $candidate = sprintf('INV-%s-%04d', $today, $count + 1);
        $exists = Db::fetchOne(
            'SELECT 1 AS e FROM sales WHERE receipt_number = :r LIMIT 1',
            [':r' => $candidate],
        );
        if ($exists === null) return $candidate;

        // Defensive fallback — if a manual INSERT bypassed this lock, mint
        // a non-monotonic suffix rather than fail the sale entirely.
        return sprintf('INV-%s-%s', $today, substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
