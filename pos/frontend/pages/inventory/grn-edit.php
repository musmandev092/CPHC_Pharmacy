<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Money;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Grn;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);
$userId = (int) Session::userId();
$pageError = null;

$grnId = (int) ($params['id'] ?? 0);
if ($grnId === 0) {
    Router::redirect('/inventory/grn');
}

$grn = Db::fetchOne(
    "SELECT g.id, g.grn_number, g.status::text AS status, g.supplier_id, g.invoice_number,
            g.invoice_date::text AS invoice_date, g.notes,
            g.subtotal::text AS subtotal, g.grand_total::text AS grand_total,
            g.posted_at::text AS posted_at, g.created_at::text AS created_at,
            s.name AS supplier_name
       FROM grn_documents g
       JOIN suppliers s ON s.id = g.supplier_id
      WHERE g.id = :id",
    [':id' => $grnId],
);
if ($grn === null) {
    http_response_code(404);
    echo 'Not found';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post') {
    try {
        $medIds  = (array) ($_POST['medicine_id']   ?? []);
        $batchNs = (array) ($_POST['batch_number']  ?? []);
        $expiries= (array) ($_POST['expiry_date']   ?? []);
        $paids   = (array) ($_POST['paid_qty']      ?? []);
        $focs    = (array) ($_POST['foc_qty']       ?? []);
        $costs   = (array) ($_POST['unit_cost']     ?? []);
        $mrps    = (array) ($_POST['mrp_per_purchase_unit'] ?? []);
        $lines = [];
        for ($i = 0, $n = count($medIds); $i < $n; $i++) {
            if ((int) $medIds[$i] <= 0) continue;
            if (trim((string) $batchNs[$i]) === '') continue;
            $lines[] = [
                'medicine_id'           => (int) $medIds[$i],
                'batch_number'          => (string) $batchNs[$i],
                'expiry_date'           => (string) $expiries[$i],
                'paid_qty'              => (int) $paids[$i],
                'foc_qty'               => (int) ($focs[$i] ?? 0),
                'unit_cost'             => (string) $costs[$i],
                'mrp_per_purchase_unit' => (string) $mrps[$i],
            ];
        }
        if (count($lines) === 0) throw new \InvalidArgumentException('At least one line required.');
        Grn::post(
            $grnId, $userId, $lines,
            trim((string) ($_POST['invoice_number'] ?? '')) ?: null,
            trim((string) ($_POST['invoice_date']   ?? '')) ?: null,
            trim((string) ($_POST['notes']          ?? '')) ?: null,
        );
        Session::flash('success', "GRN {$grn['grn_number']} posted: " . count($lines) . ' lines.');
        Router::redirect('/inventory/grn/' . $grnId);
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

$lines = Db::fetchAll(
    "SELECT l.id, l.medicine_id, m.brand_name, m.units_per_purchase,
            l.batch_number, l.expiry_date::text AS expiry_date,
            l.paid_qty, l.foc_qty, l.qty_in_base_units,
            l.unit_cost::text AS unit_cost,
            l.mrp_per_base_unit::text AS mrp_per_base_unit,
            l.line_total::text AS line_total
       FROM grn_lines l
       JOIN medicines m ON m.id = l.medicine_id
      WHERE l.grn_id = :g ORDER BY l.id",
    [':g' => $grnId],
);
$medicines = Db::fetchAll(
    "SELECT id, brand_name, sku, primary_barcode, units_per_purchase FROM medicines
      WHERE is_active = TRUE AND deleted_at IS NULL ORDER BY brand_name LIMIT 500",
);

ob_start();
$readOnly = $grn['status'] !== 'DRAFT';
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">GRN <?= e($grn['grn_number']) ?></h1>
        <p class="page-header__sub">
            Supplier <strong><?= e($grn['supplier_name']) ?></strong>
            · Created <?= e(substr((string) $grn['created_at'], 0, 19)) ?>
            <?php if ($grn['posted_at']): ?> · Posted <?= e(substr((string) $grn['posted_at'], 0, 19)) ?><?php endif; ?>
        </p>
    </div>
    <div class="page-header__actions">
        <span class="badge badge--<?= $grn['status'] === 'POSTED' ? 'success' : ($grn['status'] === 'DRAFT' ? 'warning' : 'neutral') ?>"><?= e($grn['status']) ?></span>
        <a class="btn-secondary" href="/inventory/grn">← GRN list</a>
    </div>
</div>

<?php if ($pageError): ?><div class="flash error" style="border-radius:6px;"><?= e($pageError) ?></div><?php endif; ?>

<?php if (!$readOnly): ?>
<form method="post" action="/inventory/grn/<?= e($grnId) ?>">
    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
    <input type="hidden" name="action" value="post">

    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.5em 1em; margin-bottom:1em;">
        <label><span>Invoice #</span><input type="text" name="invoice_number"></label>
        <label><span>Invoice date</span><input type="date" name="invoice_date"></label>
        <label style="grid-column:1/-1;"><span>Notes</span><textarea name="notes" rows="2"></textarea></label>
    </div>

    <h3>Lines</h3>
    <table id="grn-lines">
        <thead>
            <tr><th>Medicine</th><th>Batch #</th><th>Expiry</th><th>Paid qty</th><th>FOC</th><th>Unit cost</th><th>MRP / pack</th></tr>
        </thead>
        <tbody>
            <?php
                // Compose a medicines lookup so the scanner-friendly input can
                // resolve a barcode/SKU directly to the option without JS.
                // Each option's value attribute is the medicine id; the visible
                // label is "<brand> · <sku>" so typing/scanning either jumps
                // the dropdown.  Setting the <option label="<barcode>"> makes
                // most browsers match the typed barcode against the option too.
            ?>
            <?php for ($i = 0; $i < 8; $i++): ?>
                <tr>
                    <td>
                        <input type="text" name="med_barcode[]" list="grn-meds-list"
                               placeholder="scan or type" autocomplete="off"
                               style="width:14em;" data-grn-barcode>
                        <input type="hidden" name="medicine_id[]" value="">
                    </td>
                    <td><input type="text" name="batch_number[]" style="width:9em;"></td>
                    <td><input type="date" name="expiry_date[]"></td>
                    <td><input type="number" name="paid_qty[]" min="0" style="width:6em;"></td>
                    <td><input type="number" name="foc_qty[]" min="0" value="0" style="width:5em;"></td>
                    <td><input type="text" name="unit_cost[]" pattern="\d+(\.\d{1,4})?" inputmode="decimal" style="width:8em;"></td>
                    <td><input type="text" name="mrp_per_purchase_unit[]" pattern="\d+(\.\d{1,2})?" inputmode="decimal" style="width:8em;"></td>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>

    <datalist id="grn-meds-list">
        <?php foreach ($medicines as $m): ?>
            <option value="<?= e($m['brand_name']) ?>" data-id="<?= e($m['id']) ?>"
                    data-barcode="<?= e($m['primary_barcode'] ?? '') ?>"
                    data-sku="<?= e($m['sku'] ?? '') ?>"
                    data-upp="<?= e($m['units_per_purchase']) ?>">
                <?= e($m['sku']) ?> · <?= e($m['units_per_purchase']) ?>/pack
            </option>
        <?php endforeach; ?>
    </datalist>

    <script nonce="<?= e(Csp::nonce()) ?>">
        // Inline catalog so any field [data-grn-barcode] can resolve barcode/SKU/brand → medicine_id.
        const GRN_MEDS = [
            <?php foreach ($medicines as $m): ?>
            {id:<?= (int)$m['id'] ?>, brand:<?= json_encode((string)$m['brand_name']) ?>,
             sku:<?= json_encode((string)$m['sku']) ?>,
             barcode:<?= json_encode((string)($m['primary_barcode'] ?? '')) ?>},
            <?php endforeach; ?>
        ];
        document.querySelectorAll('[data-grn-barcode]').forEach((input) => {
            input.addEventListener('input', () => {
                const v = input.value.trim().toLowerCase();
                const hidden = input.nextElementSibling;
                if (!v) { hidden.value = ''; return; }
                const m = GRN_MEDS.find(x =>
                    x.barcode === input.value.trim()
                    || x.sku.toLowerCase() === v
                    || x.brand.toLowerCase() === v
                );
                hidden.value = m ? m.id : '';
                if (m && v !== m.brand.toLowerCase()) {
                    input.value = m.brand;   // canonicalize after barcode/SKU scan
                    input.style.background = 'hsl(var(--success) / 0.10)';
                } else if (m) {
                    input.style.background = 'hsl(var(--success) / 0.10)';
                } else {
                    input.style.background = '';
                }
            });
        });
    </script>

    <p style="color:#666; font-size:0.9em; margin-top:0.5em;">
        Empty rows are ignored. <code>blended_cost = (unit_cost × paid_qty) / ((paid_qty + foc_qty) × units_per_purchase)</code>.
    </p>

    <div style="margin-top:1em;">
        <button type="submit">Post GRN</button>
        <a class="btn secondary" href="/inventory/grn">Cancel</a>
    </div>
</form>
<?php else: ?>
    <table>
        <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th>Paid</th><th>FOC</th><th>Base units</th><th>Unit cost</th><th>MRP/base</th><th>Line total</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): ?>
            <tr>
                <td><?= e($l['brand_name']) ?></td>
                <td><?= e($l['batch_number']) ?></td>
                <td><?= e($l['expiry_date']) ?></td>
                <td><?= e($l['paid_qty']) ?></td>
                <td><?= e($l['foc_qty']) ?></td>
                <td><?= e($l['qty_in_base_units']) ?></td>
                <td>PKR <?= e($l['unit_cost']) ?></td>
                <td>PKR <?= e($l['mrp_per_base_unit']) ?></td>
                <td>PKR <?= e($l['line_total']) ?></td>
            </tr>
        <?php endforeach; ?>
            <tr><th colspan="8" style="text-align:right;">Grand total</th><th>PKR <?= e($grn['grand_total']) ?></th></tr>
        </tbody>
    </table>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'=>"GRN {$grn['grn_number']}", 'body'=>$body,
    'flash_success'=>Session::flash('success'), 'flash_error'=>Session::flash('error'),
    'nonce'=>Csp::nonce(),
]);
