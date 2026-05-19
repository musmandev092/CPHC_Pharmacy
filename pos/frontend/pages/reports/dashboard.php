<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Money;
use CPHC\Session;
use CPHC\View;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);

$today = Db::fetchOne(
    "SELECT
       count(*) AS sales_count,
       COALESCE(SUM(grand_total), 0)::text AS sales_total,
       COALESCE(SUM(CASE WHEN payment_mode='CASH' THEN grand_total END), 0)::text AS cash_total,
       COALESCE(SUM(CASE WHEN payment_mode='CARD' THEN grand_total END), 0)::text AS card_total
     FROM sales WHERE sold_at >= CURRENT_DATE AND status <> 'VOIDED'",
);

$top = Db::fetchAll(
    "SELECT m.brand_name, m.generic_name,
            SUM(si.qty_in_base_units) AS units,
            SUM(si.line_total) AS revenue
       FROM sale_items si
       JOIN sales s    ON s.id = si.sale_id
       JOIN medicines m ON m.id = si.medicine_id
      WHERE s.sold_at >= CURRENT_DATE - INTERVAL '30 days' AND s.status <> 'VOIDED'
      GROUP BY m.brand_name, m.generic_name ORDER BY revenue DESC LIMIT 10",
);

$ic = static fn (string $d, int $size = 20) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

/* Tiles for the report-list grid */
$reports = [
    ['Sales',            '/reports/sales',              'Per-transaction history', '<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>'],
    ['P&L',              '/reports/pl',                 'Margin per medicine',     '<path d="M4 19V8m6 11V4m6 15v-8m6 8v-5"/>'],
    ['Compliance',       '/reports/compliance',         'Controlled-drug sales',   '<path d="M12 2L4 6v6c0 5 3.4 9.4 8 10 4.6-.6 8-5 8-10V6l-8-4z"/>'],
    ['Expiry',           '/reports/expiry',             'Lots near expiry',        '<path d="M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
    ['Low stock',        '/reports/low-stock',          'Below reorder level',     '<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>'],
    ['Velocity',         '/reports/velocity',           'Sales speed 7/30/90 d',   '<path d="M13 2L3 14h9l-1 8 10-12h-9z"/>'],
    ['Narcotic register','/reports/narcotic-register',  'DRAP-format ledger',      '<path d="M12 2L4 6v6c0 5 3.4 9.4 8 10 4.6-.6 8-5 8-10V6l-8-4z"/>'],
];
if (Session::role() === Auth::ROLE_ADMIN) {
    $reports[] = ['Audit log', '/reports/audit', 'Tamper-evident HMAC trail', '<path d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>'];
}

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Reports</h1>
        <p class="page-header__sub">Sales, P&amp;L, compliance, expiry, low-stock, velocity, audit — each exportable as RFC-4180 CSV.</p>
    </div>
</div>

<div class="card-grid mb-6">
    <div class="kpi">
        <div>
            <div class="kpi__label">Sales today</div>
            <div class="kpi__value tabular"><?= e($today['sales_count']) ?></div>
            <div class="kpi__delta">PKR <?= e(Money::fmt($today['sales_total'])) ?> total</div>
        </div>
        <div class="kpi__icon"><?= $ic('<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>') ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Cash today</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt($today['cash_total'])) ?></div>
        </div>
        <div class="kpi__icon kpi__icon--success"><?= $ic('<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>') ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Card today</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt($today['card_total'])) ?></div>
        </div>
        <div class="kpi__icon kpi__icon--info"><?= $ic('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>') ?></div>
    </div>
</div>

<h2 class="font-display">Open a report</h2>
<div class="card-grid mb-6">
    <?php foreach ($reports as [$label, $href, $desc, $path]): ?>
        <a class="action-card" href="<?= e($href) ?>">
            <div class="action-card__icon"><?= $ic($path) ?></div>
            <div>
                <div class="action-card__title"><?= e($label) ?></div>
                <div class="action-card__desc"><?= e($desc) ?></div>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<h2 class="font-display">Top sellers (30 days)</h2>
<?php if (count($top) === 0): ?>
    <div class="empty-state">
        <svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V8m6 11V4m6 15v-8m6 8v-5"/></svg>
        <h3>No sales in the last 30 days</h3>
        <p>Once sales start rolling in, the top sellers will show here.</p>
    </div>
<?php else: ?>
<section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead><tr><th>Medicine</th><th class="right">Units</th><th class="right">Revenue</th></tr></thead>
        <tbody>
        <?php foreach ($top as $t): ?>
            <tr>
                <td><strong><?= e($t['brand_name']) ?></strong><br><small class="muted"><?= e($t['generic_name']) ?></small></td>
                <td class="num tabular"><?= e($t['units']) ?></td>
                <td class="num tabular">PKR <?= e(Money::fmt((string) $t['revenue'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Reports', 'body' => $body, 'active' => 'reports',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
