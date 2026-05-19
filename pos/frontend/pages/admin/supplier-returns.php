<?php
declare(strict_types=1);

/**
 * Supplier-return queue.  Lists returns that have been adjudicated as
 * RETURN_TO_SUPPLIER, grouped by supplier for easier "pack the box for Acme"
 * workflows.  Includes a "Mark sent" action to close the loop once a parcel
 * physically leaves the pharmacy.
 *
 * Filter: ?show=open (default) or ?show=sent shows the settled rows for audit
 * review.
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
$userId = (int) Session::userId();
$pageError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_sent') {
    try {
        $rid = (int) ($_POST['return_id'] ?? 0);
        $ref = trim((string) ($_POST['supplier_reference'] ?? '')) ?: null;
        Returns::markSupplierReturnSent($rid, $userId, $ref);
        Session::flash('success', 'Marked as sent.  Supplier-returns queue updated.');
        Router::redirect('/admin/supplier-returns');
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

$show = $_GET['show'] ?? 'open';
$where = $show === 'sent'
    ? "r.status = 'RETURN_TO_SUPPLIER' AND r.supplier_settled_at IS NOT NULL"
    : "r.status = 'RETURN_TO_SUPPLIER' AND r.supplier_settled_at IS NULL";

$rows = Db::fetchAll(
    "SELECT r.id, r.return_number, r.qty_returned_units,
            r.refund_amount::text AS refund_amount,
            r.adjudicated_at::text AS adjudicated_at,
            r.adjudication_notes,
            r.supplier_settled_at::text AS supplier_settled_at,
            r.supplier_reference,
            s.receipt_number,
            m.brand_name, m.generic_name,
            b.batch_number, b.expiry_date::text AS expiry_date,
            sup.name AS supplier_name,
            au.full_name AS adjudicated_by,
            su.full_name AS settled_by
       FROM returns r
       JOIN sales s    ON s.id = r.original_sale_id
       JOIN medicines m ON m.id = r.medicine_id
       JOIN batches b   ON b.id = r.batch_id
  LEFT JOIN suppliers sup ON sup.id = b.supplier_id
  LEFT JOIN users au    ON au.id = r.adjudicated_by
  LEFT JOIN users su    ON su.id = r.supplier_settled_by
      WHERE {$where}
      ORDER BY sup.name NULLS LAST, m.brand_name, r.adjudicated_at",
);

$openCount = (int) Db::fetchOne(
    "SELECT count(*) AS c FROM returns WHERE status='RETURN_TO_SUPPLIER' AND supplier_settled_at IS NULL",
)['c'];

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Supplier returns queue</h1>
        <p class="page-header__sub">Returns adjudicated as <code>RETURN_TO_SUPPLIER</code> — group by supplier when shipping back, then mark each row sent once the parcel is out.</p>
    </div>
    <div class="page-header__actions">
        <?php if ($show === 'sent'): ?>
            <a class="btn-secondary" href="/admin/supplier-returns?show=open">Open queue (<?= e($openCount) ?>)</a>
        <?php else: ?>
            <a class="btn-secondary" href="/admin/supplier-returns?show=sent">View sent</a>
        <?php endif; ?>
        <a class="btn-secondary" href="/admin/returns">← Adjudicate</a>
    </div>
</div>

<?php if ($pageError !== null): ?>
    <div class="flash error mb-4"><?= e($pageError) ?></div>
<?php endif; ?>

<?php if (count($rows) === 0): ?>
    <div class="empty-state">
        <svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
        <h3><?= $show === 'sent' ? 'No settled supplier returns yet' : 'Supplier-returns queue is empty' ?></h3>
        <p><?= $show === 'sent' ? 'Mark items sent from the open queue to see them here.' : 'New rows appear when a manager adjudicates a return as RETURN_TO_SUPPLIER.' ?></p>
    </div>
<?php else: ?>
    <section class="card" style="padding:0; overflow:hidden;">
        <table>
            <thead><tr>
                <th>Supplier</th><th>Medicine</th><th>Batch · expiry</th>
                <th class="right">Qty</th><th class="right">Refund</th>
                <th>Return # · From sale</th>
                <th><?= $show === 'sent' ? 'Sent' : 'Adjudicated' ?></th>
                <th><?= $show === 'sent' ? 'Reference' : 'Action' ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['supplier_name'] ?? '— (unknown)') ?></strong></td>
                    <td><?= e($r['brand_name']) ?><br><small class="muted"><?= e($r['generic_name']) ?></small></td>
                    <td><code style="font-size:11.5px;"><?= e($r['batch_number']) ?></code><br><small class="muted"><?= e($r['expiry_date']) ?></small></td>
                    <td class="num tabular"><?= e($r['qty_returned_units']) ?></td>
                    <td class="num tabular">PKR <?= e($r['refund_amount']) ?></td>
                    <td><code style="font-size:11.5px;"><?= e($r['return_number']) ?></code><br><small class="muted"><?= e($r['receipt_number']) ?></small></td>
                    <?php if ($show === 'sent'): ?>
                        <td><?= e(substr((string) $r['supplier_settled_at'], 0, 19)) ?><br><small class="muted"><?= e($r['settled_by'] ?? '') ?></small></td>
                        <td><?= e($r['supplier_reference'] ?? '—') ?></td>
                    <?php else: ?>
                        <td><?= e(substr((string) $r['adjudicated_at'], 0, 19)) ?><br><small class="muted"><?= e($r['adjudicated_by'] ?? '') ?></small></td>
                        <td>
                            <form method="post" action="/admin/supplier-returns" style="margin:0;">
                                <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                                <input type="hidden" name="action" value="mark_sent">
                                <input type="hidden" name="return_id" value="<?= e($r['id']) ?>">
                                <div style="display:flex; gap:6px; align-items:center;">
                                    <input type="text" name="supplier_reference" placeholder="waybill / credit-note #" style="width:11em;">
                                    <button type="submit" class="btn-sm">Mark sent</button>
                                </div>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Supplier returns', 'body' => $body, 'active' => 'sup-returns',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
