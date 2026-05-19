<?php
declare(strict_types=1);

/**
 * Cashier-initiated return.
 *
 * Flow:
 *   1. Cashier types a receipt # (or comes here from /pos/history with ?receipt=…).
 *   2. We list the receipt's sale_items, each with a "Return qty + reason" form.
 *   3. POST requires a manager override PIN (different person from cashier).
 *   4. Returns::initiate → PENDING_REVIEW row + RETURN_QUARANTINE movement.
 *   5. Manager later approves at /admin/returns.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Money;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\ManagerOverride;
use CPHC\Services\Returns;

Auth::requireLogin();
$cashierId = (int) Session::userId();
$pageError = null;

$receipt = trim((string) ($_REQUEST['receipt'] ?? ''));
$sale = null; $items = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'lookup') {
            $receipt = trim((string) $_POST['receipt']);
        } elseif ($action === 'initiate') {
            $saleItemId = (int) $_POST['sale_item_id'];
            $qty        = (int) $_POST['qty_to_return'];
            $reason     = (string) $_POST['reason'];
            $cond       = trim((string) ($_POST['physical_condition'] ?? '')) ?: null;
            $pin        = (string) $_POST['authorizing_manager_pin'];
            $witnessPin = (string) ($_POST['narcotic_witness_pin'] ?? '');

            // Manager authorising the refund (cannot be the cashier).
            $r = ManagerOverride::verify($pin, 'return_initiate', $cashierId);
            if (!$r['ok']) throw new \RuntimeException((string) ($r['error'] ?? 'Manager authorisation denied.'));
            $authManagerId = (int) $r['user_id'];

            // Look up the medicine's schedule on the sale item.  If it's a
            // narcotic line, also require a witness PIN that's neither the
            // cashier nor the authorising manager (defence in depth).
            $witnessUserId = null;
            $isNarcotic = (bool) Db::fetchOne(
                "SELECT 1 AS x FROM sale_items si
                   JOIN medicines m ON m.id = si.medicine_id
                  WHERE si.id = :id AND m.controlled_schedule = 'NARCOTIC'",
                [':id' => $saleItemId],
            );
            if ($isNarcotic) {
                if ($witnessPin === '') {
                    throw new \RuntimeException('Witness PIN required for narcotic return.');
                }
                $w = ManagerOverride::verify($witnessPin, 'narcotic_return_witness', $cashierId);
                if (!$w['ok']) throw new \RuntimeException('Witness: ' . ($w['error'] ?? 'PIN not recognised.'));
                if ((int) $w['user_id'] === $authManagerId) {
                    throw new \RuntimeException('Narcotic witness must be a different person than the authorising manager.');
                }
                $witnessUserId = (int) $w['user_id'];
            }

            $returnId = Returns::initiate($cashierId, $saleItemId, $qty, $reason, $authManagerId, $cond, $witnessUserId);
            Session::flash('success', "Return #{$returnId} initiated. Awaiting manager adjudication.");
            Router::redirect('/pos/returns/history');
        }
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

if ($receipt !== '') {
    $sale = Db::fetchOne(
        "SELECT s.id, s.receipt_number, s.sold_at::text AS sold_at,
                s.payment_mode::text AS payment_mode,
                s.status::text AS status,
                s.grand_total::text AS grand_total,
                u.full_name AS cashier_name
           FROM sales s JOIN users u ON u.id = s.cashier_id
          WHERE s.receipt_number = :r",
        [':r' => $receipt],
    );
    if ($sale !== null) {
        $items = Db::fetchAll(
            "SELECT si.id, si.medicine_id, si.batch_id,
                    si.qty_in_base_units, si.sold_unit_label, si.sold_unit_factor, si.qty_sold_display,
                    si.unit_mrp::text AS unit_mrp, si.line_total::text AS line_total,
                    m.brand_name, m.generic_name, m.base_unit, m.purchase_unit,
                    m.controlled_schedule::text AS controlled_schedule,
                    b.batch_number,
                    (SELECT COALESCE(SUM(qty_returned_units), 0) FROM returns WHERE sale_item_id = si.id) AS already_returned
               FROM sale_items si
               JOIN medicines m ON m.id = si.medicine_id
               JOIN batches b   ON b.id = si.batch_id
              WHERE si.sale_id = :s ORDER BY si.id",
            [':s' => (int) $sale['id']],
        );
    }
}

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Initiate return</h1>
        <p class="page-header__sub">Look up the original receipt, choose a line, set the qty and reason.  A manager PIN authorises the refund.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/pos/returns/history">Return history</a>
        <a class="btn-secondary" href="/pos">← POS</a>
    </div>
</div>

<?php if ($pageError): ?>
    <div class="flash error mb-4"><?= $ic('<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>', 18) ?><div><?= e($pageError) ?></div></div>
<?php endif; ?>

<section class="card mb-4">
    <form method="post" action="/pos/returns" class="card__body" style="padding:18px 22px;">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="lookup">
        <div class="form-grid" style="grid-template-columns: 1fr auto; align-items:end;">
            <label style="margin:0;">
                <span>Receipt #</span>
                <input type="text" name="receipt" value="<?= e($receipt) ?>" autofocus required
                       placeholder="e.g. INV-20260518-0001">
            </label>
            <button type="submit">Look up</button>
        </div>
    </form>
</section>

<?php if ($receipt !== '' && $sale === null): ?>
    <div class="empty-state">
        <svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <h3>No sale found with receipt <code><?= e($receipt) ?></code></h3>
        <p>Double-check the number and try again.</p>
    </div>
<?php elseif ($sale !== null):
    $statusCls = match ($sale['status']) {
        'COMPLETED' => 'success', 'VOIDED' => 'danger',
        'REFUNDED_FULL' => 'danger', 'REFUNDED_PARTIAL' => 'warning',
        default => 'neutral',
    };
?>
    <section class="card mb-4">
        <div class="card__header" style="background:hsl(var(--primary-soft));">
            <h2 class="card__title font-display" style="margin:0;">
                <?= e($sale['receipt_number']) ?>
            </h2>
            <span class="badge badge--<?= $statusCls ?>"><?= e($sale['status']) ?></span>
        </div>
        <div class="card__body" style="padding:14px 22px;">
            <dl style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:8px 24px; margin:0;">
                <div><dt class="muted">Sold</dt><dd style="margin:0;"><?= e(substr((string) $sale['sold_at'], 0, 19)) ?></dd></div>
                <div><dt class="muted">Cashier</dt><dd style="margin:0;"><?= e($sale['cashier_name']) ?></dd></div>
                <div><dt class="muted">Payment</dt><dd style="margin:0;"><?= e($sale['payment_mode']) ?></dd></div>
                <div><dt class="muted">Grand total</dt><dd class="tabular" style="margin:0; font-weight:600;">PKR <?= e(Money::fmt($sale['grand_total'])) ?></dd></div>
            </dl>
        </div>
    </section>

    <section class="card" style="padding:0; overflow:hidden;">
        <table>
            <thead><tr>
                <th>Item</th><th>Batch</th>
                <th class="right">Sold</th><th class="right">Already returned</th>
                <th>Return this line</th>
            </tr></thead>
            <tbody>
            <?php foreach ($items as $it):
                $factor = max(1, (int) $it['sold_unit_factor']);
                $remainingBase = max(0, (int) $it['qty_in_base_units'] - (int) $it['already_returned']);
                // We always return in BASE units so partial-pack returns work
                // (e.g. customer brings back 3 tabs of a 10-tab strip).
                $remainingDisplay = $remainingBase;
                // Try to look up the medicine's base unit for the label.
                $baseUnitLabel = (string) ($it['base_unit'] ?? $it['sold_unit_label'] ?? 'unit');
            ?>
                <?php $isNarc = ($it['controlled_schedule'] ?? 'NONE') === 'NARCOTIC'; ?>
                <tr>
                    <td>
                        <strong><?= e($it['brand_name']) ?></strong>
                        <?php if ($isNarc): ?>
                            <span class="badge badge--danger" style="margin-left:6px;">NARCOTIC</span>
                        <?php elseif (in_array($it['controlled_schedule'] ?? 'NONE', ['SCHEDULE_G', 'SCHEDULE_H'], true)): ?>
                            <span class="badge badge--warning" style="margin-left:6px;"><?= e(str_replace('_', ' ', $it['controlled_schedule'])) ?></span>
                        <?php endif; ?>
                        <br><small class="muted"><?= e($it['generic_name']) ?></small>
                    </td>
                    <td><code style="font-size:11.5px;"><?= e($it['batch_number']) ?></code></td>
                    <td class="num tabular">
                        <?= e($it['qty_sold_display']) ?> <small class="muted"><?= e($it['sold_unit_label']) ?></small>
                        <?php if ($factor > 1): ?>
                            <br><small class="muted">= <?= e($it['qty_in_base_units']) ?> <?= e($baseUnitLabel) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="num tabular"><?= e((int) $it['already_returned']) ?> <small class="muted"><?= e($baseUnitLabel) ?></small></td>
                    <td>
                        <?php if ($remainingDisplay <= 0): ?>
                            <span class="badge badge--neutral">Fully returned</span>
                        <?php else: ?>
                            <form method="post" action="/pos/returns" style="margin:0;">
                                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                                <input type="hidden" name="action" value="initiate">
                                <input type="hidden" name="sale_item_id" value="<?= e($it['id']) ?>">
                                <div class="field-row" style="gap:6px; flex-wrap:wrap; align-items:center;">
                                    <input type="number" name="qty_to_return" min="1" max="<?= e($remainingDisplay) ?>" value="1" style="width:5em;" class="tabular">
                                    <small class="muted"><?= e($baseUnitLabel) ?> (max <?= e($remainingDisplay) ?>)</small>
                                    <select name="reason" required style="width:auto;">
                                        <option value="">— reason —</option>
                                        <?php foreach (Returns::REASONS as $r): ?>
                                            <option value="<?= e($r) ?>"><?= e($r) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="physical_condition" placeholder="condition (optional)" style="width:12em;">
                                    <input type="password" name="authorizing_manager_pin" placeholder="manager PIN" inputmode="numeric" autocomplete="off" required style="width:9em;" class="tabular">
                                    <?php if ($isNarc): ?>
                                        <input type="password" name="narcotic_witness_pin" placeholder="witness PIN *" inputmode="numeric" autocomplete="off" required
                                               style="width:10em; border-color:hsl(var(--destructive));" class="tabular" title="DRAP requires a second-person witness for narcotic returns">
                                    <?php endif; ?>
                                    <button type="submit">Refund</button>
                                </div>
                                <?php if ($isNarc): ?>
                                    <small style="display:block; margin-top:6px; color:hsl(var(--destructive));">DRAP narcotic two-person rule — witness must be a manager/admin other than the cashier and the authorising manager.</small>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Initiate return', 'body' => $body, 'active' => 'returns',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
