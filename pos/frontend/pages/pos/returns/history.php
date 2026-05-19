<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Money;
use CPHC\Session;
use CPHC\View;

Auth::requireLogin();
$cashierId = (int) Session::userId();
$isManager = in_array(Session::role(), [Auth::ROLE_MANAGER, Auth::ROLE_ADMIN], true);

$rows = Db::fetchAll(
    "SELECT r.id, r.return_number, r.created_at::text AS created_at,
            r.status::text AS status, r.reason::text AS reason,
            r.qty_returned_units, r.refund_amount::text AS refund_amount,
            s.receipt_number, m.brand_name,
            u.full_name AS initiated_by
       FROM returns r
       JOIN sales s    ON s.id = r.original_sale_id
       JOIN medicines m ON m.id = r.medicine_id
       JOIN users u    ON u.id = r.initiated_by
      WHERE " . ($isManager ? '1=1' : 'r.initiated_by = :c') . "
      ORDER BY r.created_at DESC LIMIT 100",
    $isManager ? [] : [':c' => $cashierId],
);

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Return history</h1>
        <p class="page-header__sub"><?= $isManager ? 'All cashiers' : 'Returns you initiated' ?> — last 100 records.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn" href="/pos/returns"><?= $ic('<path d="M12 5v14M5 12h14"/>') ?> New return</a>
        <a class="btn-secondary" href="/pos">← POS</a>
    </div>
</div>

<?php if (count($rows) === 0): ?>
    <div class="empty-state">
        <svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3"/></svg>
        <h3>No returns recorded yet</h3>
        <p>When a customer brings something back, look up the receipt at <a href="/pos/returns">/pos/returns</a>.</p>
    </div>
<?php else: ?>
    <section class="card" style="padding:0; overflow:hidden;">
        <table>
            <thead><tr>
                <th>Return #</th><th>When</th><th>Sale</th><th>Item</th>
                <th class="right">Qty</th><th class="right">Refund</th>
                <th>Reason</th><th>Status</th><th>By</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $cls = match ($r['status']) {
                    'PENDING_REVIEW'     => 'warning',
                    'APPROVED_RESTOCK'   => 'success',
                    'RETURN_TO_SUPPLIER' => 'info',
                    'WRITE_OFF'          => 'danger',
                    default              => 'neutral',
                };
            ?>
                <tr>
                    <td><strong><?= e($r['return_number']) ?></strong></td>
                    <td class="muted nowrap"><?= e(substr((string) $r['created_at'], 0, 19)) ?></td>
                    <td><code style="font-size:11.5px;"><?= e($r['receipt_number']) ?></code></td>
                    <td><?= e($r['brand_name']) ?></td>
                    <td class="num tabular"><?= e($r['qty_returned_units']) ?></td>
                    <td class="num tabular">PKR <?= e(Money::fmt($r['refund_amount'])) ?></td>
                    <td><span class="badge badge--neutral"><?= e($r['reason']) ?></span></td>
                    <td><span class="badge badge--<?= $cls ?>"><?= e($r['status']) ?></span></td>
                    <td><?= e($r['initiated_by']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Returns', 'body' => $body, 'active' => 'returns',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
