<?php
declare(strict_types=1);

/**
 * POS terminal — cashier's main screen.  Full-viewport split layout that
 * matches the reference: left column for search + cart, right column for
 * the sticky checkout sidebar with a big oversized "Complete sale" CTA.
 *
 * Pure server-side: cart lives in $_SESSION['pos_cart'].  Every action is a
 * form POST that mutates state then redirects (PRG pattern).
 *
 * Actions (via the hidden `action` field):
 *   search   — find medicines by name / barcode / GS1
 *   add      — push one row onto the cart
 *   remove   — drop a row by index
 *   inc/dec  — qty +/- on a cart line
 *   clear    — empty the cart
 *   checkout — commit the sale, print receipt, redirect to history
 */

use CPHC\Audit;
use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Money;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\ManagerOverride;
use CPHC\Services\Printer;
use CPHC\Services\QrParser;
use CPHC\Services\Sale;
use CPHC\Services\SaleCalculator;
use CPHC\Services\Sessions;

Auth::requireLogin();
$cashierId   = (int) Session::userId();
$cashierName = Session::fullName() ?? Session::username() ?? 'Cashier';

$cart          = (array) (Session::get('pos_cart') ?? []);
$searchResults = [];
$searchQuery   = '';
$pageError     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'search':
            $searchQuery = trim((string) ($_POST['q'] ?? ''));
            $parsed = QrParser::parse($searchQuery);
            if (in_array($parsed['type'], ['GS1', 'GTIN'], true) && !empty($parsed['gtin'])) {
                // GS1 parser returns 14-digit GTIN (with leading 0 padding from
                // EAN-13).  DB usually stores the 13-digit EAN.  Try both: the
                // exact 14-digit and the 13-digit (leading zero stripped).
                $gtin14 = $parsed['gtin'];
                $gtin13 = ltrim($gtin14, '0');
                if ($gtin13 === '') { $gtin13 = '0'; }
                $searchResults = Db::fetchAll(
                    'SELECT m.id, m.brand_name, m.generic_name, m.strength, m.form::text AS form, m.sku,
                            m.base_unit, m.purchase_unit, m.units_per_purchase,
                            m.controlled_schedule::text AS controlled_schedule,
                            m.prescription_required,
                            (SELECT mrp_per_unit::text FROM batches
                              WHERE medicine_id = m.id AND current_qty > 0
                                AND is_quarantined = FALSE AND is_expired = FALSE
                                AND expiry_date > CURRENT_DATE
                              ORDER BY expiry_date ASC LIMIT 1) AS unit_mrp,
                            COALESCE((SELECT SUM(current_qty) FROM batches
                                       WHERE medicine_id = m.id AND current_qty > 0
                                         AND is_quarantined = FALSE AND is_expired = FALSE
                                         AND expiry_date > CURRENT_DATE), 0) AS in_stock
                       FROM medicines m
                      WHERE m.primary_barcode IN (:gtin14, :gtin13)
                        AND m.is_active = TRUE AND m.deleted_at IS NULL
                      LIMIT 20',
                    [':gtin14' => $gtin14, ':gtin13' => $gtin13],
                );
            } elseif ($searchQuery !== '') {
                $like = '%' . strtolower($searchQuery) . '%';
                $searchResults = Db::fetchAll(
                    'SELECT m.id, m.brand_name, m.generic_name, m.strength, m.form::text AS form, m.sku,
                            m.base_unit, m.purchase_unit, m.units_per_purchase,
                            m.controlled_schedule::text AS controlled_schedule,
                            m.prescription_required,
                            (SELECT mrp_per_unit::text FROM batches
                              WHERE medicine_id = m.id AND current_qty > 0
                                AND is_quarantined = FALSE AND is_expired = FALSE
                                AND expiry_date > CURRENT_DATE
                              ORDER BY expiry_date ASC LIMIT 1) AS unit_mrp,
                            COALESCE((SELECT SUM(current_qty) FROM batches
                                       WHERE medicine_id = m.id AND current_qty > 0
                                         AND is_quarantined = FALSE AND is_expired = FALSE
                                         AND expiry_date > CURRENT_DATE), 0) AS in_stock
                       FROM medicines m
                      WHERE m.is_active = TRUE AND m.deleted_at IS NULL
                        AND (LOWER(m.brand_name) LIKE :q OR LOWER(m.generic_name) LIKE :q OR m.sku ILIKE :q)
                      ORDER BY m.brand_name ASC LIMIT 20',
                    [':q' => $like],
                );
            }
            break;

        case 'add':
            $medId   = (int) ($_POST['medicine_id'] ?? 0);
            $qtyRaw  = (int) ($_POST['qty'] ?? 1);
            if ($qtyRaw < 1) {
                $pageError = 'Quantity must be at least 1.';
                break;
            }
            if ($qtyRaw > 9999) {
                $pageError = "Quantity {$qtyRaw} is unrealistically large — confirm the scan or split into multiple lines.";
                break;
            }
            $qty     = $qtyRaw;
            $unit    = (string) ($_POST['sold_unit'] ?? 'BASE');
            $unitMrp = (string) ($_POST['unit_mrp'] ?? '');
            $med = Db::fetchOne(
                'SELECT id, brand_name, generic_name, base_unit, purchase_unit, units_per_purchase,
                        controlled_schedule::text AS controlled_schedule,
                        prescription_required
                   FROM medicines WHERE id = :id AND is_active = TRUE AND deleted_at IS NULL',
                [':id' => $medId],
            );
            if ($med === null) {
                $pageError = 'Medicine not found.';
            } else {
                $factor = $unit === 'PACK' ? max(1, (int) $med['units_per_purchase']) : 1;
                $label  = $unit === 'PACK' ? (string) $med['purchase_unit'] : (string) $med['base_unit'];
                $cart[] = [
                    'medicine_id'           => (int) $med['id'],
                    'brand_name'            => (string) $med['brand_name'],
                    'generic_name'          => (string) $med['generic_name'],
                    'qty_sold_display'      => $qty,
                    'sold_unit_label'       => $label,
                    'sold_unit_factor'      => $factor,
                    'unit_mrp'              => $unitMrp !== '' ? Money::round($unitMrp, 2) : '0.00',
                    'controlled_schedule'   => (string) $med['controlled_schedule'],
                    'prescription_required' => (bool) $med['prescription_required'],
                    'line_subtotal'         => SaleCalculator::lineSubtotal($qty * $factor, $unitMrp !== '' ? $unitMrp : '0'),
                ];
                Session::set('pos_cart', $cart);
                Router::redirect('/pos');
            }
            break;

        case 'remove':
            $idx = (int) ($_POST['idx'] ?? -1);
            if (isset($cart[$idx])) {
                array_splice($cart, $idx, 1);
                Session::set('pos_cart', $cart);
            }
            Router::redirect('/pos');
            break;

        case 'inc':
            $idx = (int) ($_POST['idx'] ?? -1);
            if (isset($cart[$idx])) {
                $cart[$idx]['qty_sold_display']++;
                $cart[$idx]['line_subtotal'] = SaleCalculator::lineSubtotal(
                    $cart[$idx]['qty_sold_display'] * $cart[$idx]['sold_unit_factor'],
                    $cart[$idx]['unit_mrp'],
                );
                Session::set('pos_cart', $cart);
            }
            Router::redirect('/pos');
            break;

        case 'dec':
            $idx = (int) ($_POST['idx'] ?? -1);
            if (isset($cart[$idx]) && $cart[$idx]['qty_sold_display'] > 1) {
                $cart[$idx]['qty_sold_display']--;
                $cart[$idx]['line_subtotal'] = SaleCalculator::lineSubtotal(
                    $cart[$idx]['qty_sold_display'] * $cart[$idx]['sold_unit_factor'],
                    $cart[$idx]['unit_mrp'],
                );
                Session::set('pos_cart', $cart);
            }
            Router::redirect('/pos');
            break;

        case 'clear':
            Session::forget('pos_cart');
            Router::redirect('/pos');
            break;

        case 'checkout':
            if (count($cart) === 0) {
                $pageError = 'Cart is empty.';
                break;
            }

            $hasNarcotic = false;
            $hasControlled = false;
            foreach ($cart as $c) {
                if (($c['controlled_schedule'] ?? 'NONE') === 'NARCOTIC') $hasNarcotic = true;
                if (($c['controlled_schedule'] ?? 'NONE') !== 'NONE') $hasControlled = true;
            }

            $input = [
                'items'                => array_map(fn ($c) => [
                    'medicine_id'       => $c['medicine_id'],
                    'qty_sold_display'  => $c['qty_sold_display'],
                    'sold_unit_label'   => $c['sold_unit_label'],
                    'sold_unit_factor'  => $c['sold_unit_factor'],
                    'unit_mrp'          => $c['unit_mrp'],
                ], $cart),
                'payment_mode'         => (string) ($_POST['payment_mode'] ?? 'CASH'),
                'customer_name'        => trim((string) ($_POST['customer_name'] ?? '')) ?: null,
                'customer_phone'       => trim((string) ($_POST['customer_phone'] ?? '')) ?: null,
                'discount_total'       => trim((string) ($_POST['discount_total'] ?? '0')) ?: '0',
                'amount_tendered'      => trim((string) ($_POST['amount_tendered'] ?? '')) ?: null,
                'has_controlled_drug'  => $hasControlled,
                'doctor_name'          => trim((string) ($_POST['doctor_name'] ?? '')) ?: null,
                'patient_name'         => trim((string) ($_POST['patient_name'] ?? '')) ?: null,
                'patient_phone'        => trim((string) ($_POST['patient_phone'] ?? '')) ?: null,
                'patient_address'      => trim((string) ($_POST['patient_address'] ?? '')) ?: null,
                'prescriber_license_number' => trim((string) ($_POST['prescriber_license_number'] ?? '')) ?: null,
            ];

            if (Money::cmp($input['discount_total'], '0') > 0) {
                $mp = (string) ($_POST['discount_manager_pin'] ?? '');
                $r  = ManagerOverride::verify($mp, 'discount', $cashierId);
                if (!$r['ok']) { $pageError = 'Discount: ' . ($r['error'] ?? 'manager PIN required'); break; }
                $input['discount_authorized_by'] = $r['user_id'];
            }

            if ($hasNarcotic) {
                $wp = (string) ($_POST['narcotic_witness_pin'] ?? '');
                $r  = ManagerOverride::verify($wp, 'narcotic_witness', $cashierId);
                if (!$r['ok']) { $pageError = 'Narcotic witness: ' . ($r['error'] ?? 'PIN required'); break; }
                $input['narcotic_witness_user_id'] = $r['user_id'];
            }

            try {
                $saleId = Sale::commit($cashierId, $input);
                Session::forget('pos_cart');
                Session::flash('success', 'Sale committed (#' . $saleId . '). Printing receipt…');
                $printRes = (new Printer())->printSale($saleId);
                if (!$printRes['ok']) {
                    Session::flash('error', 'Receipt did not print: ' . ($printRes['error'] ?? 'unknown') . '. Reprint from history.');
                }
                Router::redirect('/pos/history');
            } catch (\Throwable $e) {
                Audit::write($cashierId, 'SALE_FAILED', 'sales', null, null,
                    ['error' => $e->getMessage(), 'items_count' => count($cart)]);
                $pageError = 'Sale failed: ' . $e->getMessage();
            }
            break;
    }
}

/* ── Totals + state for the view ────────────────────────────────────────── */
$subtotal = SaleCalculator::sum(array_map(fn ($c) => $c['line_subtotal'], $cart));
$shift = Sessions::currentFor($cashierId);
$hasOpenShift = $shift !== null;

$hasNarcotic = false; $hasControlled = false;
foreach ($cart as $c) {
    if (($c['controlled_schedule'] ?? 'NONE') === 'NARCOTIC')      $hasNarcotic = true;
    if (($c['controlled_schedule'] ?? 'NONE') !== 'NONE')          $hasControlled = true;
}

$ic = static function (string $d, int $size = 16): string {
    return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
};
$ICON_CART    = '<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>';
$ICON_SEARCH  = '<circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>';
$ICON_WARN    = '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>';
$ICON_SHIELD  = '<path d="M12 2L4 6v6c0 5 3.4 9.4 8 10 4.6-.6 8-5 8-10V6l-8-4z"/>';
$ICON_PLUS    = '<path d="M12 5v14M5 12h14"/>';
$ICON_MINUS   = '<path d="M5 12h14"/>';
$ICON_X       = '<path d="M18 6L6 18M6 6l12 12"/>';
$ICON_BANKNOTE= '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>';
$ICON_CARD    = '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>';
$ICON_MORE    = '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>';

ob_start();
?>
<style>
/* ── Full-viewport POS layout (matches the reference's 8/4 split) ───── */
.pos-screen {
    display: flex; flex-direction: column;
    min-height: calc(100vh - 64px);   /* viewport minus topbar */
    background: hsl(var(--background));
}
.pos-stripes { flex-shrink: 0; }
.pos-stripes .strip {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 24px;
    font-size: 13.5px; font-weight: 600;
    border-bottom: 1px solid transparent;
}
.pos-stripes .strip--warn {
    background: hsl(var(--warning) / 0.10);
    color: hsl(var(--warning));
    border-bottom-color: hsl(var(--warning) / 0.25);
}
.pos-stripes .strip--err {
    background: hsl(var(--destructive) / 0.10);
    color: hsl(var(--destructive));
    border-bottom-color: hsl(var(--destructive) / 0.25);
}
.pos-stripes .strip a {
    margin-left: auto;
    color: inherit;
    text-decoration: underline;
    font-weight: 700;
}

.pos-body {
    flex: 1;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    min-height: 0;
}
@media (min-width: 1024px) {
    .pos-body { grid-template-columns: minmax(0, 1fr) 460px; }
}
@media (min-width: 1440px) {
    .pos-body { grid-template-columns: minmax(0, 1fr) 520px; }
}

.pos-left {
    display: flex; flex-direction: column;
    background: hsl(var(--background));
    border-right: 1px solid hsl(var(--border));
    overflow: hidden;
    min-height: 0;
}
.pos-search-section {
    padding: 18px 24px;
    background: hsl(var(--card));
    border-bottom: 1px solid hsl(var(--border));
    flex-shrink: 0;
    position: relative;
    z-index: 10;
}
.pos-search-bar { position: relative; }
.pos-search-bar .input-icon {
    position: absolute; left: 18px; top: 50%; transform: translateY(-50%);
    color: hsl(var(--primary)); pointer-events: none;
}
.pos-search-bar input {
    height: 56px;
    padding: 0 18px 0 52px;
    width: 100%;
    font-size: 17px; font-weight: 500;
    border: 2px solid hsl(var(--primary) / 0.20);
    border-radius: var(--radius-lg);
    background: hsl(var(--background));
    box-shadow: inset 0 1px 2px 0 hsl(var(--foreground) / 0.04);
}
.pos-search-bar input::placeholder { color: hsl(var(--muted-foreground)); font-weight: 500; }
.pos-search-bar input:focus {
    border-color: hsl(var(--primary));
    box-shadow: 0 0 0 4px hsl(var(--ring) / 0.18);
    outline: none;
}

.pos-results {
    margin-top: 12px;
    border-radius: var(--radius-lg);
    background: hsl(var(--card));
    border: 1px solid hsl(var(--border));
    overflow: hidden;
    box-shadow: 0 8px 20px -6px hsl(var(--foreground) / 0.10);
    max-height: 480px;
    overflow-y: auto;
}
.pos-result-row {
    display: flex; align-items: center; gap: 16px;
    padding: 14px 18px;
    border-bottom: 1px solid hsl(var(--border));
    transition: background-color 100ms;
}
.pos-result-row:last-child { border-bottom: 0; }
.pos-result-row:hover { background: hsl(var(--primary) / 0.04); }
.pos-result-row form { margin: 0; }
.pos-result-info { flex: 1; min-width: 0; }
.pos-result-brand { font-weight: 700; font-size: 16px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pos-result-generic { color: hsl(var(--muted-foreground)); font-size: 13px; margin-top: 2px; }
.pos-result-stock { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 4px; }
.pos-result-stock--ok  { color: hsl(var(--muted-foreground)); }
.pos-result-stock--low { color: hsl(var(--warning)); }
.pos-result-stock--out { color: hsl(var(--destructive)); }
.pos-result-add { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; }
.pos-result-add input[type="number"] { width: 64px; height: 36px; text-align: center; padding: 6px 8px; }
.pos-result-add select { width: 110px; height: 36px; padding: 6px 28px 6px 10px; font-size: 12px; }

.pos-cart-section {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
}

.pos-empty {
    height: 100%;
    display: flex; align-items: center; justify-content: center;
    text-align: center;
    padding: 60px 24px;
}
.pos-empty svg { color: hsl(var(--foreground) / 0.12); margin: 0 auto 20px; }
.pos-empty h3 { font-size: 20px; font-weight: 800; letter-spacing: -0.02em; margin: 0 0 4px; }
.pos-empty p { color: hsl(var(--muted-foreground)); }

.cart-table { width: 100%; border-collapse: collapse; }
.cart-table thead { position: sticky; top: 0; z-index: 5; }
.cart-table thead th {
    background: hsl(var(--background));
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em;
    color: hsl(var(--muted-foreground));
    padding: 14px 24px;
    text-align: left;
    border-bottom: 1px solid hsl(var(--border));
}
.cart-table tbody td {
    padding: 16px 24px;
    border-bottom: 1px solid hsl(var(--border));
    vertical-align: middle;
}
.cart-table tbody tr:last-child td { border-bottom: 0; }
.cart-table tbody tr:hover { background: hsl(var(--muted) / 0.30); }
.cart-item-name { font-weight: 700; font-size: 15px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cart-item-generic { color: hsl(var(--muted-foreground)); font-size: 12.5px; font-style: italic; margin-top: 2px; }
.cart-qty {
    display: inline-flex; align-items: center; gap: 0;
    background: hsl(var(--muted) / 0.6);
    border-radius: var(--radius-md);
    padding: 2px;
}
.cart-qty button {
    height: 32px; width: 32px;
    padding: 0;
    background: transparent;
    color: hsl(var(--muted-foreground));
    border: 0;
}
.cart-qty button:hover { background: hsl(var(--card)); color: hsl(var(--foreground)); }
.cart-qty .qty {
    width: 40px; text-align: center; font-weight: 800;
    font-variant-numeric: tabular-nums; font-size: 16px;
}
.cart-line-total { font-weight: 800; font-size: 15px; color: hsl(var(--primary)); }
.cart-remove-btn {
    background: hsl(var(--muted) / 0.6);
    border: 1px solid hsl(var(--border));
    padding: 6px;
    color: hsl(var(--muted-foreground));
    cursor: pointer;
    border-radius: var(--radius-sm);
    height: 32px; width: 32px;
}
.cart-remove-btn:hover {
    color: hsl(var(--destructive));
    background: hsl(var(--destructive) / 0.10);
    border-color: hsl(var(--destructive) / 0.30);
}

.cart-footer {
    padding: 12px 24px;
    background: hsl(var(--muted) / 0.3);
    border-top: 1px solid hsl(var(--border));
    display: flex; justify-content: space-between; align-items: center;
    font-size: 13px;
    color: hsl(var(--muted-foreground));
}

/* ── Right column: checkout ────────────────────────────────────────── */
.pos-right {
    background: hsl(var(--card));
    display: flex; flex-direction: column;
    box-shadow: -1px 0 0 hsl(var(--border));
    min-height: 0;
}
.pos-checkout-scroll {
    flex: 1; overflow-y: auto;
    padding: 24px;
    display: flex; flex-direction: column; gap: 22px;
}
.pos-checkout-cta {
    padding: 16px 24px;
    border-top: 1px solid hsl(var(--border));
    background: hsl(var(--card));
    flex-shrink: 0;
}

.section-title {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.07em;
    color: hsl(var(--muted-foreground));
    padding-bottom: 8px;
    border-bottom: 1px solid hsl(var(--border));
    display: flex; align-items: center; justify-content: space-between;
    margin: 0 0 12px;
}
.checkout-row {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 14px;
    color: hsl(var(--muted-foreground));
    margin: 10px 0;
}
.checkout-row.grand {
    padding-top: 14px;
    border-top: 1px solid hsl(var(--border));
    align-items: baseline;
    margin-top: 6px;
}
.checkout-row.grand .label {
    font-size: 1.125rem; font-weight: 800;
    letter-spacing: -0.02em;
    color: hsl(var(--foreground));
}
.checkout-row.grand .amount {
    font-size: 2.25rem; font-weight: 900;
    letter-spacing: -0.025em;
    font-variant-numeric: tabular-nums;
    color: hsl(var(--primary));
    line-height: 1;
}

.pay-modes { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.pay-mode {
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    padding: 14px 8px;
    background: hsl(var(--muted) / 0.5);
    border: 2px solid transparent;
    border-radius: var(--radius-md);
    color: hsl(var(--muted-foreground));
    cursor: pointer;
    font-size: 12px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.04em;
    transition: background-color 120ms, border-color 120ms, color 120ms;
    margin: 0;
}
.pay-mode > span { margin: 0; }
.pay-mode:hover { background: hsl(var(--muted)); color: hsl(var(--foreground)); }
.pay-mode--active {
    border-color: hsl(var(--primary));
    background: hsl(var(--primary) / 0.06);
    color: hsl(var(--primary));
}
.pay-mode input { display: none; }

.tendered-input {
    height: 60px !important;
    padding: 12px 18px !important;
    font-size: 1.875rem !important; font-weight: 900;
    font-variant-numeric: tabular-nums;
    border: 2px solid hsl(var(--primary) / 0.20) !important;
    border-radius: var(--radius-md);
    text-align: right;
    width: 100%;
}
.tendered-input:focus { border-color: hsl(var(--primary)) !important; }

.pay-cta {
    width: 100%;
    height: 60px;
    font-size: 16px;
    font-weight: 800;
    letter-spacing: -0.01em;
    border-radius: var(--radius-md);
    box-shadow: 0 4px 12px -2px hsl(var(--primary) / 0.30);
    display: flex; align-items: center; justify-content: center; gap: 10px;
}
.blocked-line {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    color: hsl(var(--warning));
    font-size: 12.5px; font-weight: 600;
    margin-bottom: 10px;
}

.controlled-card {
    border: 2px solid hsl(var(--destructive));
    background: hsl(var(--destructive) / 0.06);
    padding: 14px 16px;
    border-radius: var(--radius-md);
}
.controlled-card h3 {
    margin: 0 0 8px;
    color: hsl(var(--destructive));
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 700;
    display: flex; align-items: center; gap: 6px;
}
.controlled-card p { font-size: 12px; color: hsl(var(--destructive)); margin: 0 0 10px; line-height: 1.5; }
.controlled-card input, .controlled-card textarea { font-size: 13px; }
.controlled-card .field-stack > * + * { margin-top: 8px; }
</style>

<div class="pos-screen">

    <!-- ── Status strips ─────────────────────────────────────────────── -->
    <div class="pos-stripes">
        <?php if (!$hasOpenShift): ?>
            <div class="strip strip--warn">
                <?= $ic($ICON_WARN, 16) ?>
                <span><strong>No open cashier shift.</strong> Sales are blocked until you open one.</span>
                <a href="/pos/session">Open shift →</a>
            </div>
        <?php endif; ?>
        <?php if ($pageError !== null): ?>
            <div class="strip strip--err">
                <?= $ic($ICON_WARN, 16) ?>
                <span><?= e($pageError) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <div class="pos-body">

        <!-- ═══════════════════ LEFT: search + cart ══════════════════════ -->
        <div class="pos-left">

            <!-- search header -->
            <div class="pos-search-section">
                <form method="post" action="/pos" class="pos-search-bar">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="search">
                    <span class="input-icon"><?= $ic($ICON_SEARCH, 22) ?></span>
                    <input type="search" name="q" value="<?= e($searchQuery) ?>" autofocus
                           data-barcode-target autocomplete="off"
                           placeholder="Scan barcode or type brand / generic / SKU → Enter">
                </form>

                <?php if (count($searchResults) > 0): ?>
                    <div class="pos-results">
                        <?php foreach ($searchResults as $m):
                            $qty = (int) $m['in_stock'];
                            $stockCls = $qty === 0 ? 'pos-result-stock--out' : ($qty <= 10 ? 'pos-result-stock--low' : 'pos-result-stock--ok');
                        ?>
                            <div class="pos-result-row">
                                <div class="pos-result-info">
                                    <div class="pos-result-brand">
                                        <span><?= e($m['brand_name']) ?></span>
                                        <?php if (!empty($m['form'])): ?>
                                            <span class="badge badge--neutral"><?= e($m['form']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($m['strength'])): ?>
                                            <span class="muted" style="font-size:13px; font-weight:600;"><?= e($m['strength']) ?></span>
                                        <?php endif; ?>
                                        <?php if (($m['controlled_schedule'] ?? 'NONE') === 'NARCOTIC'): ?>
                                            <span class="badge badge--danger">NARCOTIC</span>
                                        <?php elseif (in_array($m['controlled_schedule'] ?? 'NONE', ['SCHEDULE_G', 'SCHEDULE_H'], true)): ?>
                                            <span class="badge badge--warning"><?= e(str_replace('_', ' ', $m['controlled_schedule'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="pos-result-generic">
                                        <?= e($m['generic_name']) ?>
                                        <?php if (!empty($m['sku'])): ?> · <code style="font-size:11.5px;"><?= e($m['sku']) ?></code><?php endif; ?>
                                    </div>
                                    <div class="pos-result-stock <?= $stockCls ?>">
                                        Stock: <?= e($m['in_stock']) ?> <?= e($m['base_unit']) ?> · PKR <?= e(Money::fmt($m['unit_mrp'] ?? '0')) ?>
                                    </div>
                                </div>

                                <form method="post" action="/pos" class="pos-result-add">
                                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="medicine_id" value="<?= e($m['id']) ?>">
                                    <input type="hidden" name="unit_mrp" value="<?= e($m['unit_mrp'] ?? '0') ?>">
                                    <input type="number" name="qty" value="1" min="1" max="9999">
                                    <select name="sold_unit">
                                        <option value="BASE"><?= e($m['base_unit']) ?></option>
                                        <?php if ((int) $m['units_per_purchase'] > 1): ?>
                                            <option value="PACK"><?= e($m['purchase_unit']) ?> (×<?= e($m['units_per_purchase']) ?>)</option>
                                        <?php endif; ?>
                                    </select>
                                    <button type="submit" <?= $qty < 1 ? 'disabled' : '' ?>>Add</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($searchQuery !== ''): ?>
                    <div class="pos-results">
                        <div style="padding:32px; text-align:center; color:hsl(var(--muted-foreground));">
                            No medicine matches <strong>"<?= e($searchQuery) ?>"</strong>.
                            <br><small>Adjust the search term, or scan a different barcode.</small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- cart -->
            <div class="pos-cart-section">
                <?php if (count($cart) === 0): ?>
                    <div class="pos-empty">
                        <div>
                            <?= $ic($ICON_CART, 84) ?>
                            <h3 class="font-display">Cart is empty</h3>
                            <p>Scan a barcode or type a medicine name to begin a sale.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>Item description</th>
                                <th style="text-align:center; width:160px;">Qty</th>
                                <th style="text-align:right; width:120px;">Unit MRP</th>
                                <th style="text-align:right; width:140px;">Line total</th>
                                <th style="width:60px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cart as $i => $c): ?>
                            <tr>
                                <td>
                                    <div class="cart-item-name">
                                        <span><?= e($c['brand_name']) ?></span>
                                        <?php if (($c['controlled_schedule'] ?? 'NONE') === 'NARCOTIC'): ?>
                                            <span class="badge badge--danger">NARCOTIC</span>
                                        <?php elseif (in_array($c['controlled_schedule'] ?? 'NONE', ['SCHEDULE_G', 'SCHEDULE_H'], true)): ?>
                                            <span class="badge badge--warning"><?= e(str_replace('_', ' ', $c['controlled_schedule'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="cart-item-generic">
                                        <?= e($c['generic_name']) ?>
                                        <?php
                                            $totalBase = (int) $c['qty_sold_display'] * (int) $c['sold_unit_factor'];
                                            if ((int) $c['sold_unit_factor'] > 1):
                                        ?>
                                            · <?= e($c['qty_sold_display']) ?> × <?= e($c['sold_unit_label']) ?>
                                              (<?= e($c['sold_unit_factor']) ?>/pack = <?= e($totalBase) ?> total)
                                        <?php else: ?>
                                            · per <?= e($c['sold_unit_label']) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="cart-qty">
                                        <form method="post" action="/pos" class="inline-form">
                                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="dec">
                                            <input type="hidden" name="idx" value="<?= e($i) ?>">
                                            <button type="submit" aria-label="Decrease"><?= $ic($ICON_MINUS, 14) ?></button>
                                        </form>
                                        <span class="qty"><?= e($c['qty_sold_display']) ?></span>
                                        <form method="post" action="/pos" class="inline-form">
                                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                                            <input type="hidden" name="action" value="inc">
                                            <input type="hidden" name="idx" value="<?= e($i) ?>">
                                            <button type="submit" aria-label="Increase"><?= $ic($ICON_PLUS, 14) ?></button>
                                        </form>
                                    </div>
                                </td>
                                <?php
                                    // When sold as a pack (strip/box/carton), show the effective
                                    // per-pack price — qty × this = line total reads naturally.
                                    $effPrice = (int) $c['sold_unit_factor'] > 1
                                        ? \CPHC\Money::round(
                                            \CPHC\Money::mul((string) $c['unit_mrp'], (string) (int) $c['sold_unit_factor']), 2)
                                        : $c['unit_mrp'];
                                ?>
                                <td style="text-align:right;" class="tabular">PKR <?= e(Money::fmt($effPrice)) ?></td>
                                <td style="text-align:right;" class="cart-line-total tabular">PKR <?= e(Money::fmt($c['line_subtotal'])) ?></td>
                                <td style="text-align:right;">
                                    <form method="post" action="/pos" class="inline-form">
                                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="idx" value="<?= e($i) ?>">
                                        <button type="submit" class="cart-remove-btn" title="Remove"><?= $ic($ICON_X, 18) ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="cart-footer">
                        <span><strong style="color:hsl(var(--foreground));"><?= count($cart) ?></strong> <?= count($cart) === 1 ? 'item' : 'items' ?> in cart</span>
                        <form method="post" action="/pos" class="inline-form">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                            <input type="hidden" name="action" value="clear">
                            <button type="submit" class="btn-link" style="font-size:12px;">Clear cart</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════ RIGHT: checkout sidebar ══════════════════ -->
        <aside class="pos-right">
            <form method="post" action="/pos" style="display:contents;">
                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="checkout">

                <div class="pos-checkout-scroll">

                    <!-- Order summary -->
                    <div>
                        <div class="section-title">Order summary</div>
                        <div class="checkout-row">
                            <span>Subtotal</span>
                            <span class="tabular">PKR <?= e(Money::fmt($subtotal)) ?></span>
                        </div>
                        <div class="checkout-row" style="gap:12px;">
                            <label for="discount" style="margin:0; flex:1;">Discount (PKR)</label>
                            <input type="text" id="discount" name="discount_total" value="0"
                                   pattern="\d+(\.\d{1,2})?" inputmode="decimal"
                                   style="height:36px; width:120px; text-align:right;" class="tabular">
                        </div>
                        <label style="margin:6px 0 0;">
                            <span style="font-size:12px;">Manager PIN (only if discount &gt; 0)</span>
                            <input type="password" name="discount_manager_pin" inputmode="numeric" autocomplete="off">
                        </label>

                        <div class="checkout-row grand">
                            <span class="label">Total</span>
                            <span class="amount">PKR <?= e(Money::fmt($subtotal)) ?></span>
                        </div>
                    </div>

                    <!-- Payment method -->
                    <div>
                        <div class="section-title">Payment method</div>
                        <div class="pay-modes">
                            <label class="pay-mode pay-mode--active">
                                <input type="radio" name="payment_mode" value="CASH" checked>
                                <?= $ic($ICON_BANKNOTE, 22) ?>
                                <span>Cash</span>
                            </label>
                            <label class="pay-mode">
                                <input type="radio" name="payment_mode" value="CARD">
                                <?= $ic($ICON_CARD, 22) ?>
                                <span>Card</span>
                            </label>
                            <label class="pay-mode">
                                <input type="radio" name="payment_mode" value="OTHER">
                                <?= $ic($ICON_MORE, 22) ?>
                                <span>Other</span>
                            </label>
                        </div>
                    </div>

                    <!-- Amount tendered -->
                    <div>
                        <div class="section-title">Amount tendered</div>
                        <input type="text" name="amount_tendered" placeholder="0.00"
                               pattern="\d+(\.\d{1,2})?" inputmode="decimal"
                               class="tendered-input tabular">
                    </div>

                    <!-- Customer (collapsible) -->
                    <details>
                        <summary class="section-title" style="cursor:pointer; padding-bottom:8px; border-bottom:1px solid hsl(var(--border));">
                            Customer
                            <span class="muted" style="font-weight:500; text-transform:none; letter-spacing:0; font-size:11px;">(optional)</span>
                        </summary>
                        <div class="field-stack mt-3">
                            <input type="text" name="customer_name"  placeholder="Name">
                            <input type="tel"  name="customer_phone" placeholder="Phone">
                        </div>
                    </details>

                    <!-- Narcotic two-person rule -->
                    <?php if ($hasNarcotic): ?>
                        <div class="controlled-card">
                            <h3><?= $ic($ICON_SHIELD, 14) ?> Narcotic two-person rule <span style="margin-left:auto; font-size:10px; opacity:0.7;">DRAP</span></h3>
                            <p>Narcotic item in cart — prescriber DRAP license required, plus a second user (manager/admin, not the cashier) must witness.</p>
                            <div class="field-stack">
                                <input type="text" name="doctor_name" placeholder="Prescribing doctor *">
                                <input type="text" name="prescriber_license_number" placeholder="Prescriber DRAP license # *">
                                <input type="text" name="patient_name" placeholder="Patient name *">
                                <input type="tel"  name="patient_phone" placeholder="Patient phone">
                                <textarea name="patient_address" rows="2" placeholder="Patient address (optional)"></textarea>
                                <input type="password" name="narcotic_witness_pin"
                                       inputmode="numeric" autocomplete="off"
                                       placeholder="Witness manager/admin PIN *"
                                       style="text-align:center; letter-spacing:0.4em;">
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- Sticky bottom CTA -->
                <div class="pos-checkout-cta">
                    <?php
                    $blockedReason = null;
                    if (count($cart) === 0)        $blockedReason = 'Cart is empty';
                    elseif (!$hasOpenShift)        $blockedReason = 'No open shift';
                    ?>
                    <?php if ($blockedReason): ?>
                        <div class="blocked-line"><?= $ic($ICON_WARN, 14) ?> <?= e($blockedReason) ?></div>
                    <?php endif; ?>
                    <button type="submit" class="pay-cta" <?= $blockedReason !== null ? 'disabled' : '' ?>>
                        Complete sale &middot; PKR <?= e(Money::fmt($subtotal)) ?>
                    </button>
                </div>
            </form>
        </aside>
    </div>
</div>

<script nonce="<?= e(Csp::nonce()) ?>">
// Payment-mode tile toggle.
document.querySelectorAll('.pay-mode input[type="radio"]').forEach((r) => {
    r.addEventListener('change', () => {
        document.querySelectorAll('.pay-mode').forEach((el) => el.classList.remove('pay-mode--active'));
        r.closest('.pay-mode').classList.add('pay-mode--active');
    });
});
</script>

<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'POS Terminal',
    'body'          => $body,
    'active'        => 'pos',
    'fullbleed'     => true,
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
