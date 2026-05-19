<?php
declare(strict_types=1);

/**
 * Recent sales for the logged-in cashier (manager sees all).
 * Reprint re-fires the ESC/POS bytes to CUPS without re-committing the sale.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Money;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Printer;

Auth::requireLogin();
$cashierId = (int) Session::userId();
$isManager = in_array(Session::role(), [Auth::ROLE_MANAGER, Auth::ROLE_ADMIN], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reprint') {
    $saleId = (int) ($_POST['sale_id'] ?? 0);
    if ($saleId > 0) {
        $res = (new Printer())->printSale($saleId, isReprint: true);
        Session::flash($res['ok'] ? 'success' : 'error',
            $res['ok'] ? "Receipt reprinted." : "Reprint failed: " . ($res['error'] ?? 'unknown'));
    }
    Router::redirect('/pos/history');
}

$q     = trim((string) ($_GET['q'] ?? ''));
$from  = (string) ($_GET['from'] ?? '');
$to    = (string) ($_GET['to']   ?? '');

$where  = [];
$params = [];
if (!$isManager) { $where[] = 's.cashier_id = :c'; $params[':c'] = $cashierId; }
if ($q !== '')   { $where[] = '(s.receipt_number ILIKE :q OR s.customer_name ILIKE :q OR s.customer_phone ILIKE :q)';
                   $params[':q'] = '%' . $q . '%'; }
if ($from !== ''){ $where[] = 's.sold_at::date >= :f'; $params[':f'] = $from; }
if ($to !== '')  { $where[] = 's.sold_at::date <= :t'; $params[':t'] = $to; }
$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sales = Db::fetchAll(
    "SELECT s.id, s.receipt_number, s.sold_at::text AS sold_at,
            s.subtotal::text AS subtotal,
            s.discount_total::text AS discount_total,
            s.grand_total::text AS grand_total,
            s.payment_mode::text AS payment_mode,
            s.status::text AS status,
            s.has_controlled_drug,
            u.full_name AS cashier_name
       FROM sales s JOIN users u ON u.id = s.cashier_id
       $whereSql
      ORDER BY s.sold_at DESC LIMIT 200",
    $params,
);

$ic = static fn (string $d, int $size = 14) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Sale history</h1>
        <p class="page-header__sub">
            <?= $isManager
                ? 'All cashiers — filter by date, receipt #, or customer'
                : 'Your own sales for the selected period.' ?>
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/pos"><?= $ic('<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>', 16) ?> Back to POS</a>
    </div>
</div>

<!-- Filters -->
<section class="card mb-4">
    <form method="get" action="/pos/history" class="card__body" style="padding:18px 22px;">
        <div class="form-grid" style="grid-template-columns:160px 160px 1fr auto; align-items:end;">
            <label style="margin:0;"><span>From</span><input type="date" name="from" value="<?= e($from) ?>"></label>
            <label style="margin:0;"><span>To</span><input type="date" name="to" value="<?= e($to) ?>"></label>
            <label style="margin:0;"><span>Search</span>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Receipt #, customer name or phone…">
            </label>
            <button type="submit">Apply</button>
        </div>
    </form>
</section>

<!-- Results -->
<section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead>
            <tr>
                <th>Receipt</th>
                <th>Sold</th>
                <?php if ($isManager): ?><th>Cashier</th><?php endif; ?>
                <th class="right">Subtotal</th>
                <th class="right">Discount</th>
                <th class="right">Total</th>
                <th>Mode</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($sales) === 0): ?>
                <tr>
                    <td colspan="<?= $isManager ? 9 : 8 ?>" style="padding:48px; text-align:center;">
                        <div class="empty-state__icon"><?= $ic('<path d="M3 12a9 9 0 109-9 9 9 0 00-6.39 2.61L3 8M3 3v5h5M12 8v4l3 2"/>', 40) ?></div>
                        <h3 style="margin:0;">No sales for this period</h3>
                        <p class="muted" style="margin:4px 0 0;">Adjust filters or come back later.</p>
                    </td>
                </tr>
            <?php else: foreach ($sales as $s):
                $statusCls = match ($s['status']) {
                    'COMPLETED'        => 'success',
                    'VOIDED'           => 'destructive',
                    'REFUNDED_FULL'    => 'destructive',
                    'REFUNDED_PARTIAL' => 'warning',
                    default            => 'neutral',
                };
            ?>
                <tr>
                    <td>
                        <code style="font-size:12px;"><?= e($s['receipt_number']) ?></code>
                        <?php if ($s['has_controlled_drug']): ?>
                            <br><span class="badge badge--danger" style="margin-top:4px;">controlled</span>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= e(substr((string) $s['sold_at'], 0, 19)) ?></td>
                    <?php if ($isManager): ?><td><?= e($s['cashier_name']) ?></td><?php endif; ?>
                    <td class="num tabular">PKR <?= e(Money::fmt($s['subtotal'])) ?></td>
                    <td class="num tabular"><?= bccomp($s['discount_total'], '0', 2) > 0 ? '−PKR ' . e(Money::fmt($s['discount_total'])) : '—' ?></td>
                    <td class="num tabular" style="font-weight:700;">PKR <?= e(Money::fmt($s['grand_total'])) ?></td>
                    <td><span class="badge badge--neutral"><?= e($s['payment_mode']) ?></span></td>
                    <td><span class="badge badge--<?= $statusCls ?>"><?= e($s['status']) ?></span></td>
                    <td class="row-actions">
                        <?php if (!in_array($s['status'], ['REFUNDED_FULL', 'VOIDED'], true)): ?>
                            <a class="btn-link" href="/pos/returns?receipt=<?= e($s['receipt_number']) ?>"><?= $ic('<path d="M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3"/>') ?> Return</a>
                        <?php endif; ?>
                        <form method="post" action="/pos/history" class="inline-form">
                            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                            <input type="hidden" name="action" value="reprint">
                            <input type="hidden" name="sale_id" value="<?= e($s['id']) ?>">
                            <button type="submit" class="btn-link"><?= $ic('<path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/>') ?> Reprint</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Sale history',
    'body'          => $body,
    'active'        => 'history',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
