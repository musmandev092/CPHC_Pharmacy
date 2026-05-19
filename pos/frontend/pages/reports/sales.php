<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Money;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Csv;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);

$from = $_GET['from'] ?? (new \DateTimeImmutable('-30 days'))->format('Y-m-d');
$to   = $_GET['to']   ?? (new \DateTimeImmutable('now'))->format('Y-m-d');

$rows = Db::fetchAll(
    "SELECT s.id, s.receipt_number, s.sold_at::text AS sold_at,
            s.payment_mode::text AS payment_mode, s.status::text AS status,
            s.subtotal::text AS subtotal,
            s.discount_total::text AS discount_total,
            s.grand_total::text AS grand_total,
            u.full_name AS cashier_name
       FROM sales s JOIN users u ON u.id = s.cashier_id
      WHERE s.sold_at::date BETWEEN :f AND :t
      ORDER BY s.sold_at DESC LIMIT 2000",
    [':f' => $from, ':t' => $to],
);

if (($_GET['export'] ?? '') === 'csv') {
    Csv::stream("sales_{$from}_to_{$to}",
        ['Receipt #', 'When', 'Cashier', 'Mode', 'Status', 'Subtotal', 'Discount', 'Grand total'],
        array_map(fn ($r) => [$r['receipt_number'], substr((string) $r['sold_at'], 0, 19), $r['cashier_name'],
            $r['payment_mode'], $r['status'], $r['subtotal'], $r['discount_total'], $r['grand_total']], $rows));
    return;
}

$totals = ['count' => count($rows), 'gt' => '0', 'cash' => '0', 'card' => '0'];
foreach ($rows as $r) {
    if ($r['status'] === 'VOIDED') continue;
    $totals['gt'] = Money::add($totals['gt'], $r['grand_total']);
    if ($r['payment_mode'] === 'CASH') $totals['cash'] = Money::add($totals['cash'], $r['grand_total']);
    if ($r['payment_mode'] === 'CARD') $totals['card'] = Money::add($totals['card'], $r['grand_total']);
}

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Sales report</h1>
        <p class="page-header__sub">Every transaction in the range, with subtotal, discount, and grand-total breakdown.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/reports">← Reports</a>
        <a class="btn" href="/reports/sales?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv">
            <?= $ic('<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>') ?>
            Download CSV
        </a>
    </div>
</div>

<div class="card-grid mb-4">
    <div class="kpi">
        <div>
            <div class="kpi__label">Sales</div>
            <div class="kpi__value tabular"><?= e($totals['count']) ?></div>
            <div class="kpi__delta">in range</div>
        </div>
        <div class="kpi__icon"><?= $ic('<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>', 20) ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Grand total</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt(Money::round($totals['gt'], 2))) ?></div>
            <div class="kpi__delta">non-voided</div>
        </div>
        <div class="kpi__icon kpi__icon--success"><?= $ic('<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>', 20) ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Cash</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt(Money::round($totals['cash'], 2))) ?></div>
            <div class="kpi__delta">paid in cash</div>
        </div>
        <div class="kpi__icon kpi__icon--success"><?= $ic('<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>', 20) ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Card</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt(Money::round($totals['card'], 2))) ?></div>
            <div class="kpi__delta">via card</div>
        </div>
        <div class="kpi__icon kpi__icon--info"><?= $ic('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>', 20) ?></div>
    </div>
</div>

<section class="card mb-4">
    <form method="get" action="/reports/sales" class="card__body" style="padding:18px 22px;">
        <div style="display:flex; flex-wrap:wrap; gap:12px 20px; align-items:end;">
            <label style="margin:0; min-width:160px;"><span>From</span><input type="date" name="from" value="<?= e($from) ?>"></label>
            <label style="margin:0; min-width:160px;"><span>To</span><input type="date" name="to" value="<?= e($to) ?>"></label>
            <button type="submit">Filter</button>
        </div>
    </form>
</section>

<section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead>
            <tr>
                <th>Receipt</th><th>When</th><th>Cashier</th>
                <th class="right">Subtotal</th><th class="right">Discount</th><th class="right">Total</th>
                <th>Mode</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="8" style="padding:48px; text-align:center;" class="muted">No sales in this range.</td></tr>
        <?php else: foreach ($rows as $r):
            $statusCls = match ($r['status']) {
                'COMPLETED'        => 'success',
                'VOIDED'           => 'danger',
                'REFUNDED_FULL'    => 'danger',
                'REFUNDED_PARTIAL' => 'warning',
                default            => 'neutral',
            };
        ?>
            <tr>
                <td><code style="font-size:12px;"><?= e($r['receipt_number']) ?></code></td>
                <td class="muted"><?= e(substr((string) $r['sold_at'], 0, 19)) ?></td>
                <td><?= e($r['cashier_name']) ?></td>
                <td class="num tabular">PKR <?= e(Money::fmt($r['subtotal'])) ?></td>
                <td class="num tabular">
                    <?= bccomp($r['discount_total'], '0', 2) > 0 ? '−PKR ' . e(Money::fmt($r['discount_total'])) : '<span class="muted">—</span>' ?>
                </td>
                <td class="num tabular" style="font-weight:700;">PKR <?= e(Money::fmt($r['grand_total'])) ?></td>
                <td><span class="badge badge--neutral"><?= e($r['payment_mode']) ?></span></td>
                <td><span class="badge badge--<?= $statusCls ?>"><?= e($r['status']) ?></span></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Sales report', 'body' => $body, 'active' => 'reports',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
