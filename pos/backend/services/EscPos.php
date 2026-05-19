<?php
declare(strict_types=1);

namespace CPHC\Services;

/*
 * ESC/POS receipt byte stream builder for the Black Copper BC-88AC (80 mm).
 * Direct port of backend/app/Services/Printing/EscPosRenderer.php — same 48-col
 * width, same command bytes, same wrap behaviour.  Carbon dropped in favour
 * of \DateTimeImmutable.
 *
 * @phpstan-type SalePayload array{
 *   receiptNumber: string,
 *   soldAt: string,
 *   cashier: array{fullName: string},
 *   paymentMode: string,
 *   subtotal: string,
 *   discountTotal: string,
 *   grandTotal: string,
 *   amountTendered?: string,
 *   changeReturned?: string,
 *   items: list<array{
 *     medicine: array{brandName: string, genericName?: string},
 *     batch: array{batchNumber: string, expiryDate: string},
 *     qtySoldDisplay: int,
 *     unitMrp: string,
 *     lineTotal: string,
 *   }>,
 * }
 * @phpstan-type Setting array{key: string, value: string}
 */
final class EscPos
{
    public const WIDTH = 48;

    private const CMD = [
        'init'    => "\x1B\x40",
        'center'  => "\x1B\x61\x01",
        'left'    => "\x1B\x61\x00",
        'boldOn'  => "\x1B\x45\x01",
        'boldOff' => "\x1B\x45\x00",
        'dblHOn'  => "\x1B\x21\x10",
        'dblHOff' => "\x1B\x21\x00",
        'cut'     => "\x1D\x56\x41\x03",
        'feed3'   => "\n\n\n",
    ];

    /**
     * @param array<string,mixed> $sale     Sale payload (shape above).
     * @param list<array{key:string,value:string}> $settings Settings rows.
     */
    public static function receipt(array $sale, array $settings): string
    {
        $get = function (string $key, string $fallback = '') use ($settings): string {
            foreach ($settings as $s) {
                if (($s['key'] ?? null) === $key) {
                    return (string) ($s['value'] ?? $fallback);
                }
            }
            return $fallback;
        };

        $pharmacyName = $get('pharmacy_name',    'CARE POINT PHARMACY');
        $addr         = $get('pharmacy_address', '');
        $phone        = $get('pharmacy_phone',   '');
        $policy       = $get('return_policy_text',
            'Medicine will be taken back within 72 hours along with original invoice.');
        $footerMsg    = $get('receipt_footer_message',
            'To save one life is like saving humanity.');

        $soldAt  = new \DateTimeImmutable((string) ($sale['soldAt'] ?? 'now'));
        $dateStr = $soldAt->format('d/m/y');
        $timeStr = $soldAt->format('H:i');
        $cashier = (string) ($sale['cashier']['fullName'] ?? 'Staff');

        $out = self::CMD['init'];

        // Header
        $out .= self::CMD['center'] . self::CMD['boldOn'];
        $out .= mb_substr(mb_strtoupper($pharmacyName, 'UTF-8'), 0, self::WIDTH, 'UTF-8') . "\n";
        $out .= self::CMD['boldOff'];
        if ($addr !== '') {
            $wrap = (int) (self::WIDTH * 2 / 3);
            foreach (explode("\n", $addr) as $line) {
                $t = trim($line);
                if ($t === '') continue;
                foreach (self::wordWrap($t, $wrap) as $wr) $out .= $wr . "\n";
            }
        }
        if ($phone !== '') {
            $out .= mb_substr('Ph: ' . $phone, 0, self::WIDTH, 'UTF-8') . "\n";
        }

        $out .= self::CMD['left'] . self::divider('=') . "\n";
        $out .= self::row('Invoice:', (string) ($sale['receiptNumber'] ?? '')) . "\n";
        $out .= self::row('Date:',    $dateStr . ' ' . $timeStr) . "\n";
        $out .= self::row('Cashier:', strtoupper($cashier)) . "\n";
        $out .= self::row('Mode:',    (string) ($sale['paymentMode'] ?? 'CASH')) . "\n";

        // Status banner — critical when reprinting a refunded/voided sale.
        $status     = (string) ($sale['status'] ?? 'COMPLETED');
        $isReprint  = !empty($sale['isReprint']);
        $banner     = null;
        if ($status === 'VOIDED')             $banner = '*** VOIDED — NOT A VALID SALE ***';
        elseif ($status === 'REFUNDED_FULL')  $banner = '*** REFUNDED IN FULL ***';
        elseif ($status === 'REFUNDED_PARTIAL') $banner = '** PARTIALLY REFUNDED — SEE HISTORY **';
        if ($banner !== null) {
            $out .= self::CMD['center'] . self::CMD['boldOn']
                  . mb_substr($banner, 0, self::WIDTH, 'UTF-8') . "\n"
                  . self::CMD['boldOff'] . self::CMD['left'];
        }
        if ($isReprint) {
            $out .= self::CMD['center'] . '-- REPRINT --' . "\n" . self::CMD['left'];
        }
        $out .= self::divider('-') . "\n";

        // Items
        $nameW = 27;
        $out .= str_pad('ITEM',  $nameW)
              . str_pad(' QTY',   4, ' ', STR_PAD_LEFT)
              . str_pad(' PRICE', 8, ' ', STR_PAD_LEFT)
              . str_pad(' TOTAL', 9, ' ', STR_PAD_LEFT) . "\n";
        $out .= self::divider('-') . "\n";

        $items    = $sale['items'] ?? [];
        $totalQty = 0;
        foreach ($items as $item) {
            $med           = $item['medicine'] ?? [];
            $name          = mb_substr(mb_strtoupper((string) ($med['brandName'] ?? ''), 'UTF-8'), 0, $nameW, 'UTF-8');
            $qtyDisplay    = (int) ($item['qtySoldDisplay'] ?? 1);
            $qty           = (string) $qtyDisplay;
            $unitMrp       = (float) ($item['unitMrp']   ?? 0);
            $lineTotal     = (float) ($item['lineTotal'] ?? 0);
            $soldLabel     = trim((string) ($item['soldUnitLabel'] ?? ''));
            $soldFactor    = max(1, (int) ($item['soldUnitFactor'] ?? 1));
            $totalBase     = (int) ($item['qtyInBaseUnits'] ?? ($qtyDisplay * $soldFactor));
            $baseUnit      = (string) ($med['baseUnit'] ?? '');

            // Price column shows per-sold-unit price (per tablet, per strip, per
            // bottle…) so qty × price = line total reads naturally on the
            // receipt.  unit_mrp is stored per BASE unit, so multiply when sold
            // as a pack.
            $effPrice = $unitMrp * $soldFactor;
            $price    = number_format($effPrice, 2, '.', '');
            $tot      = number_format($lineTotal, 2, '.', '');

            $out .= str_pad($name, $nameW)
                  . str_pad($qty,   4, ' ', STR_PAD_LEFT)
                  . str_pad($price, 8, ' ', STR_PAD_LEFT)
                  . str_pad($tot,   9, ' ', STR_PAD_LEFT) . "\n";

            // Unit-of-measure line — critical for customer + audit.  Examples:
            //   "  1 × TABLET"
            //   "  2 × STRIP (10 TABLET each = 20 TABLET)"
            //   "  1 × INHALER"
            if ($soldLabel !== '') {
                if ($soldFactor > 1 && $baseUnit !== '' && strcasecmp($soldLabel, $baseUnit) !== 0) {
                    $out .= '  ' . $qtyDisplay . ' x ' . mb_strtoupper($soldLabel, 'UTF-8')
                          . ' (' . $soldFactor . ' ' . mb_strtoupper($baseUnit, 'UTF-8')
                          . ' each = ' . $totalBase . ' ' . mb_strtoupper($baseUnit, 'UTF-8') . ")\n";
                } else {
                    $out .= '  ' . $qtyDisplay . ' x ' . mb_strtoupper($soldLabel, 'UTF-8') . "\n";
                }
            }

            if (!empty($med['genericName'])) {
                $generic = (string) $med['genericName'];
                if (!empty($med['strength'])) {
                    $generic .= ' ' . $med['strength'];
                }
                $out .= '  ' . mb_substr($generic, 0, self::WIDTH - 2, 'UTF-8') . "\n";
            }
            if (!empty($item['batch'])) {
                $bExp = new \DateTimeImmutable((string) ($item['batch']['expiryDate'] ?? 'now'));
                $out .= '  Batch:' . (string) ($item['batch']['batchNumber'] ?? '')
                      . '  Exp:'  . $bExp->format('m/y') . "\n";
            }
            $totalQty += $qtyDisplay;
        }
        $out .= self::divider('-') . "\n";

        // Totals
        $subtotal   = number_format((float) ($sale['subtotal']      ?? ($sale['grandTotal'] ?? 0)), 2, '.', '');
        $discount   = (float)        ($sale['discountTotal'] ?? 0);
        $grandTotal = number_format((float) ($sale['grandTotal']    ?? 0), 2, '.', '');

        $out .= self::row('Total Qty:', (string) $totalQty) . "\n";
        $out .= self::row('Subtotal:',  'PKR ' . $subtotal) . "\n";
        if ($discount > 0) {
            $out .= self::row('Discount:', '-PKR ' . number_format($discount, 2, '.', '')) . "\n";
        }
        $out .= self::divider('=') . "\n";

        $out .= self::CMD['dblHOn'] . self::CMD['boldOn'];
        $out .= self::row('PAYABLE:', 'PKR ' . $grandTotal) . "\n";
        $out .= self::CMD['dblHOff'] . self::CMD['boldOff'];
        $out .= self::divider('=') . "\n";

        if (($sale['paymentMode'] ?? '') === 'CASH') {
            $tendered = number_format((float) ($sale['amountTendered'] ?? 0), 2, '.', '');
            $change   = number_format((float) ($sale['changeReturned'] ?? 0), 2, '.', '');
            $out .= self::row('Cash Tendered:', 'PKR ' . $tendered) . "\n";
            $out .= self::CMD['boldOn'] . self::row('Change:', 'PKR ' . $change) . "\n" . self::CMD['boldOff'];
        }

        $out .= "\n";
        foreach (self::wordWrap($policy, self::WIDTH) as $line) $out .= $line . "\n";
        $out .= self::divider('-') . "\n";

        if ($footerMsg !== '') {
            $out .= self::CMD['center'];
            foreach (self::wordWrap($footerMsg, self::WIDTH) as $line) $out .= $line . "\n";
            $out .= self::CMD['left'];
        }

        $out .= self::CMD['feed3'] . self::CMD['cut'];
        return $out;
    }

    public static function selfTest(string $queueName, ?string $extra = null): string
    {
        $out = self::CMD['init']
             . self::CMD['center'] . self::CMD['boldOn'] . self::CMD['dblHOn']
             . "CPHC Pharmacy\n"
             . self::CMD['dblHOff'] . "Printer Self-Test\n" . self::CMD['boldOff']
             . "Black Copper BC-88AC\n"
             . self::divider('-') . "\n"
             . self::CMD['left']
             . self::row('Queue',  $queueName) . "\n"
             . self::row('PPD',    'zjiang/zj80.ppd') . "\n"
             . self::row('Time',   date('c')) . "\n"
             . self::row('Status', 'OK') . "\n";
        if ($extra !== null && $extra !== '') {
            $out .= self::divider('-') . "\n";
            foreach (self::wordWrap($extra, self::WIDTH) as $line) $out .= $line . "\n";
        }
        $out .= self::divider('-') . "\n"
              . self::CMD['center']
              . "If you can read this,\n"
              . "the driver + queue work.\n"
              . self::CMD['feed3'] . self::CMD['cut'];
        return $out;
    }

    private static function row(string $left, string $right, int $w = self::WIDTH): string
    {
        $space = max(1, $w - mb_strlen($left, 'UTF-8') - mb_strlen($right, 'UTF-8'));
        return $left . str_repeat(' ', $space) . $right;
    }

    private static function divider(string $ch, int $w = self::WIDTH): string
    {
        return str_repeat($ch, $w);
    }

    /** @return list<string> */
    private static function wordWrap(string $text, int $width): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $next = $cur === '' ? $w : $cur . ' ' . $w;
            if (mb_strlen($next, 'UTF-8') > $width) {
                if ($cur !== '') $lines[] = $cur;
                $cur = mb_substr($w, 0, $width, 'UTF-8');
            } else {
                $cur = $next;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    }
}
