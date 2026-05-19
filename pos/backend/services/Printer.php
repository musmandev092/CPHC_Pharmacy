<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;

/*
 * Receipt-print orchestrator.  Loads the sale + items + cashier + settings,
 * renders ESC/POS bytes, sends to CUPS, audit-logs the result.
 *
 * Print failures are audited (PRINT_FAILED) but do NOT roll back the sale —
 * the receipt can be reprinted from the sale history later.
 */
final class Printer
{
    public function __construct(private Cups $cups = new Cups()) {}

    /**
     * Print receipt for sale by id.
     *
     * @param int  $saleId       The sale to print.
     * @param bool $isReprint    True if this is a reprint from history (different audit verb).
     * @return array{ok:bool,error:?string}
     */
    public function printSale(int $saleId, bool $isReprint = false): array
    {
        $sale = Db::fetchOne(
            'SELECT s.id, s.receipt_number, s.payment_mode::text AS payment_mode,
                    s.subtotal::text AS subtotal,
                    s.discount_total::text AS discount_total,
                    s.grand_total::text AS grand_total,
                    s.amount_tendered::text AS amount_tendered,
                    s.change_returned::text AS change_returned,
                    s.sold_at::text AS sold_at,
                    s.status::text AS status,
                    u.full_name AS cashier_name
               FROM sales s
               JOIN users u ON u.id = s.cashier_id
              WHERE s.id = :id',
            [':id' => $saleId],
        );
        if ($sale === null) {
            // Audit the miss — could indicate enumeration probing.
            Audit::write(
                null,
                $isReprint ? 'RECEIPT_REPRINT_FAILED' : 'PRINT_FAILED',
                'sales',
                $saleId,
                null,
                ['error' => 'Sale not found', 'reprint' => $isReprint],
            );
            return ['ok' => false, 'error' => 'Sale not found'];
        }

        $items = Db::fetchAll(
            'SELECT si.qty_sold_display, si.qty_in_base_units,
                    si.sold_unit_label, si.sold_unit_factor,
                    si.unit_mrp::text AS unit_mrp,
                    si.line_total::text AS line_total,
                    m.brand_name, m.generic_name, m.strength, m.form::text AS form,
                    m.base_unit, m.purchase_unit,
                    b.batch_number, b.expiry_date::text AS expiry_date
               FROM sale_items si
               JOIN medicines m ON m.id = si.medicine_id
               JOIN batches   b ON b.id = si.batch_id
              WHERE si.sale_id = :id
              ORDER BY si.id',
            [':id' => $saleId],
        );

        $settings = Db::fetchAll('SELECT key, value FROM settings');

        $payload = [
            'receiptNumber'  => (string) $sale['receipt_number'],
            'soldAt'         => (string) $sale['sold_at'],
            'cashier'        => ['fullName' => (string) $sale['cashier_name']],
            'paymentMode'    => (string) $sale['payment_mode'],
            'status'         => (string) $sale['status'],
            'isReprint'      => $isReprint,
            'subtotal'       => (string) $sale['subtotal'],
            'discountTotal'  => (string) ($sale['discount_total'] ?? '0'),
            'grandTotal'     => (string) $sale['grand_total'],
            'amountTendered' => (string) ($sale['amount_tendered'] ?? '0'),
            'changeReturned' => (string) ($sale['change_returned'] ?? '0'),
            'items'          => array_map(fn ($it) => [
                'medicine'       => [
                    'brandName'    => (string) $it['brand_name'],
                    'genericName'  => (string) ($it['generic_name'] ?? ''),
                    'strength'     => (string) ($it['strength'] ?? ''),
                    'form'         => (string) ($it['form'] ?? ''),
                    'baseUnit'     => (string) $it['base_unit'],
                    'purchaseUnit' => (string) $it['purchase_unit'],
                ],
                'batch'          => [
                    'batchNumber' => (string) $it['batch_number'],
                    'expiryDate'  => (string) $it['expiry_date'],
                ],
                'qtySoldDisplay' => (int) $it['qty_sold_display'],
                'qtyInBaseUnits' => (int) $it['qty_in_base_units'],
                'soldUnitLabel'  => (string) $it['sold_unit_label'],
                'soldUnitFactor' => (int) $it['sold_unit_factor'],
                'unitMrp'        => (string) $it['unit_mrp'],
                'lineTotal'      => (string) $it['line_total'],
            ], $items),
        ];

        $bytes  = EscPos::receipt($payload, $settings);
        $result = $this->cups->printRaw($bytes, 'sale-' . $sale['receipt_number']);

        if ($isReprint) {
            $action = $result['ok'] ? 'RECEIPT_REPRINTED' : 'RECEIPT_REPRINT_FAILED';
        } else {
            $action = $result['ok'] ? 'PRINT_SUCCESS' : 'PRINT_FAILED';
        }
        Audit::write(
            null,
            $action,
            'sales',
            (int) $sale['id'],
            null,
            [
                'receipt_number' => (string) $sale['receipt_number'],
                'queue'          => $this->cups->queueName(),
                'reprint'        => $isReprint,
                'error'          => $result['error'],
            ],
        );

        return ['ok' => $result['ok'], 'error' => $result['error']];
    }

    public function selfTest(): array
    {
        $bytes  = EscPos::selfTest($this->cups->queueName());
        $result = $this->cups->printRaw($bytes, 'self-test');
        Audit::write(
            null,
            $result['ok'] ? 'PRINTER_SELFTEST_OK' : 'PRINTER_SELFTEST_FAILED',
            'Printer',
            null,
            null,
            ['queue' => $this->cups->queueName(), 'error' => $result['error']],
        );
        return $result;
    }
}
