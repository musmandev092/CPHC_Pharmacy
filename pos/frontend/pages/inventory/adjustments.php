<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\ManagerOverride;
use CPHC\Services\StockAdjuster;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);
$userId = (int) Session::userId();
$pageError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust') {
    try {
        $batchId = (int) $_POST['batch_id'];
        $delta   = (int) $_POST['qty_delta'];
        $reason  = (string) $_POST['reason'];
        $notes   = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $pin     = (string) ($_POST['manager_pin'] ?? '');

        // Stock adjustments must be PIN-confirmed even when the user is already
        // logged in as a manager.  Prevents an unattended workstation from
        // being used to write off stock.  Any active manager/admin PIN is
        // accepted (self-confirmation allowed — this is a re-auth, not a
        // two-person rule).
        $r = ManagerOverride::verify($pin, 'stock_adjustment');
        if (!$r['ok']) {
            throw new \RuntimeException((string) ($r['error'] ?? 'Manager PIN required.'));
        }

        $id = StockAdjuster::adjust((int) $r['user_id'], $batchId, $delta, $reason, $notes);
        Session::flash('success', "Adjustment recorded (id $id).");
        Router::redirect('/inventory/adjustments');
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

$batches = Db::fetchAll(
    "SELECT b.id, m.brand_name, b.batch_number,
            b.expiry_date::text AS expiry_date, b.current_qty
       FROM batches b
       JOIN medicines m ON m.id = b.medicine_id
      WHERE b.current_qty > 0 OR b.is_quarantined = TRUE
      ORDER BY m.brand_name, b.expiry_date LIMIT 500",
);

$recent = Db::fetchAll(
    "SELECT a.id, a.adjustment_number, a.created_at::text AS created_at,
            m.brand_name, b.batch_number, a.qty_delta,
            a.reason::text AS reason, a.cost_impact::text AS cost_impact,
            u.full_name AS performed_by
       FROM stock_adjustments a
       JOIN medicines m ON m.id = a.medicine_id
       JOIN batches b ON b.id = a.batch_id
       JOIN users u ON u.id = a.performed_by
      ORDER BY a.created_at DESC LIMIT 30",
);

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Stock adjustments</h1>
        <p class="page-header__sub">Manual stock corrections — damage, expiry write-offs, count corrections.  Each move is audit-logged with a cost impact.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/inventory">← Inventory</a>
    </div>
</div>

<?php if ($pageError): ?><div class="flash error mb-4"><?= e($pageError) ?></div><?php endif; ?>

<details open style="background:white; border:1px solid #e6e8ec; border-radius:8px; padding:1em 1.4em; margin-bottom:1.5em;">
    <summary><strong>New adjustment</strong></summary>
    <form method="post" action="/inventory/adjustments">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="adjust">
        <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:0.5em 1em;">
            <label><span>Batch</span>
                <select name="batch_id" required>
                    <option value="">— pick —</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= e($b['id']) ?>">
                            <?= e($b['brand_name']) ?> — <?= e($b['batch_number']) ?> (exp <?= e($b['expiry_date']) ?>, qty <?= e($b['current_qty']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><span>Quantity delta (signed)</span>
                <input type="number" name="qty_delta" required step="1">
            </label>
            <label><span>Reason</span>
                <select name="reason" required>
                    <?php foreach (StockAdjuster::REASONS as $r): ?>
                        <option value="<?= e($r) ?>"><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="grid-column:1/-1;"><span>Notes</span><textarea name="notes" rows="2"></textarea></label>
            <label style="grid-column:1/-1;">
                <span>Manager PIN <span class="muted" style="font-weight:400;">(re-confirm)</span></span>
                <input type="password" name="manager_pin" inputmode="numeric" autocomplete="off"
                       required style="max-width:240px; letter-spacing:0.3em; text-align:center;">
            </label>
        </div>
        <p style="color:#666; font-size:0.9em;">Negative removes stock; positive adds. Cannot go below zero. Cost impact captured at the batch's current cost. PIN re-confirmation required for every adjustment.</p>
        <button type="submit">Record adjustment</button>
    </form>
</details>

<h2>Recent adjustments</h2>
<?php if (count($recent) === 0): ?>
    <p style="color:#666;">None yet.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Adj #</th><th>When</th><th>Medicine</th><th>Batch</th><th>Delta</th><th>Reason</th><th>Cost impact</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td><?= e($r['adjustment_number']) ?></td>
                <td><?= e(substr((string) $r['created_at'], 0, 19)) ?></td>
                <td><?= e($r['brand_name']) ?></td>
                <td><?= e($r['batch_number']) ?></td>
                <td style="color:<?= (int) $r['qty_delta'] >= 0 ? '#1e6a32' : '#c0392b' ?>;"><?= e($r['qty_delta']) ?></td>
                <td><?= e($r['reason']) ?></td>
                <td>PKR <?= e($r['cost_impact'] ?? '0.00') ?></td>
                <td><?= e($r['performed_by']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'=>'Adjustments', 'body'=>$body,
    'flash_success'=>Session::flash('success'), 'flash_error'=>Session::flash('error'),
    'nonce'=>Csp::nonce(),
]);
