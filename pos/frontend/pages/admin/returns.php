<?php
declare(strict_types=1);

/**
 * Manager adjudication queue.
 * GET  /admin/returns     — list PENDING_REVIEW returns, plus recently adjudicated.
 * POST /admin/returns     — action=adjudicate, sets status to APPROVED_RESTOCK /
 *                           RETURN_TO_SUPPLIER / WRITE_OFF.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Returns;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);
$adminId = (int) Session::userId();
$pageError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjudicate') {
    try {
        $returnId  = (int) $_POST['return_id'];
        $newStatus = (string) $_POST['new_status'];
        $notes     = trim((string) ($_POST['notes'] ?? '')) ?: null;
        Returns::adjudicate($returnId, $newStatus, $adminId, $notes);
        Session::flash('success', "Return adjudicated as $newStatus.");
        Router::redirect('/admin/returns');
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

$pending = Db::fetchAll(
    "SELECT r.id, r.return_number, r.created_at::text AS created_at,
            r.qty_returned_units, r.refund_amount::text AS refund_amount,
            r.reason::text AS reason, r.physical_condition,
            s.receipt_number, m.brand_name, m.generic_name,
            b.batch_number, b.expiry_date::text AS expiry_date,
            u.full_name AS initiated_by,
            au.full_name AS authorized_by
       FROM returns r
       JOIN sales s    ON s.id = r.original_sale_id
       JOIN medicines m ON m.id = r.medicine_id
       JOIN batches b   ON b.id = r.batch_id
       JOIN users u     ON u.id = r.initiated_by
  LEFT JOIN users au    ON au.id = r.authorized_by
      WHERE r.status = 'PENDING_REVIEW'
      ORDER BY r.created_at DESC",
);

$recent = Db::fetchAll(
    "SELECT r.id, r.return_number, r.adjudicated_at::text AS adjudicated_at,
            r.status::text AS status, r.qty_returned_units,
            s.receipt_number, m.brand_name,
            u.full_name AS adjudicated_by
       FROM returns r
       JOIN sales s    ON s.id = r.original_sale_id
       JOIN medicines m ON m.id = r.medicine_id
  LEFT JOIN users u    ON u.id = r.adjudicated_by
      WHERE r.status <> 'PENDING_REVIEW'
      ORDER BY r.adjudicated_at DESC LIMIT 20",
);

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Adjudicate returns</h1>
        <p class="page-header__sub">Customer returns awaiting <code>PENDING_REVIEW</code> sign-off — approve restock, send to supplier, or write off.</p>
    </div>
    <div class="page-header__actions">
        <span class="badge badge--<?= count($pending) > 0 ? 'warning' : 'success' ?>">
            <?= count($pending) ?> pending
        </span>
        <a class="btn-secondary" href="/admin/supplier-returns">Supplier queue</a>
    </div>
</div>

<?php if ($pageError): ?><div class="flash error mb-4"><?= e($pageError) ?></div><?php endif; ?>

<?php if (count($pending) === 0): ?>
    <p style="color:#666;">Nothing pending.</p>
<?php else: ?>
    <?php foreach ($pending as $r): ?>
        <details open style="background:white; border:1px solid #e6e8ec; border-radius:8px; padding:0.9em 1.2em; margin-bottom:1em;">
            <summary>
                <strong><?= e($r['return_number']) ?></strong> ·
                <?= e($r['brand_name']) ?> · qty <?= e($r['qty_returned_units']) ?> ·
                refund <strong>PKR <?= e($r['refund_amount']) ?></strong>
                · reason <?= e($r['reason']) ?>
            </summary>
            <dl style="display:grid; grid-template-columns:1fr 2fr; gap:0.2em 1em; margin-top:0.8em;">
                <dt>Sale</dt>            <dd><?= e($r['receipt_number']) ?></dd>
                <dt>Generic</dt>         <dd><?= e($r['generic_name']) ?></dd>
                <dt>Batch / expiry</dt>  <dd><?= e($r['batch_number']) ?> · <?= e($r['expiry_date']) ?></dd>
                <dt>Physical cond.</dt>  <dd><?= e($r['physical_condition'] ?? '—') ?></dd>
                <dt>Initiated by</dt>    <dd><?= e($r['initiated_by']) ?> at <?= e(substr((string) $r['created_at'], 0, 19)) ?></dd>
                <dt>Authorised by</dt>   <dd><?= e($r['authorized_by'] ?? '—') ?></dd>
            </dl>

            <form method="post" action="/admin/returns" style="margin-top:0.8em;">
                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                <input type="hidden" name="action" value="adjudicate">
                <input type="hidden" name="return_id" value="<?= e($r['id']) ?>">
                <label>
                    <span>Disposition</span>
                    <select name="new_status" required>
                        <option value="">— pick —</option>
                        <option value="APPROVED_RESTOCK">Approve restock (back into stock)</option>
                        <option value="RETURN_TO_SUPPLIER">Return to supplier (stays out)</option>
                        <option value="WRITE_OFF">Write off (damaged, expired)</option>
                    </select>
                </label>
                <label><span>Adjudication notes</span><textarea name="notes" rows="2"></textarea></label>
                <button type="submit">Adjudicate</button>
            </form>
        </details>
    <?php endforeach; ?>
<?php endif; ?>

<h2>Recently adjudicated</h2>
<?php if (count($recent) === 0): ?>
    <p style="color:#666;">None yet.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Return #</th><th>When</th><th>Sale</th><th>Item</th><th>Qty</th><th>Status</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td><?= e($r['return_number']) ?></td>
                <td><?= e(substr((string) $r['adjudicated_at'], 0, 19)) ?></td>
                <td><?= e($r['receipt_number']) ?></td>
                <td><?= e($r['brand_name']) ?></td>
                <td><?= e($r['qty_returned_units']) ?></td>
                <td><?= e($r['status']) ?></td>
                <td><?= e($r['adjudicated_by'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Adjudicate returns', 'body' => $body, 'active' => 'adjudicate',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
