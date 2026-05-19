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
    "SELECT m.brand_name,
            SUM(si.qty_in_base_units) AS units,
            SUM(si.line_total)::text AS revenue,
            SUM(si.unit_cost * si.qty_in_base_units)::text AS cogs,
            (SUM(si.line_total) - SUM(si.unit_cost * si.qty_in_base_units))::text AS margin
       FROM sale_items si
       JOIN sales s    ON s.id = si.sale_id
       JOIN medicines m ON m.id = si.medicine_id
      WHERE s.sold_at::date BETWEEN :f AND :t
        AND s.status <> 'VOIDED'
      GROUP BY m.brand_name
      ORDER BY margin DESC NULLS LAST LIMIT 500",
    [':f' => $from, ':t' => $to],
);

if (($_GET['export'] ?? '') === 'csv') {
    Csv::stream("pl_{$from}_to_{$to}",
        ['Medicine', 'Units sold', 'Revenue (PKR)', 'COGS (PKR)', 'Gross margin (PKR)'],
        array_map(fn ($r) => [$r['brand_name'], $r['units'], $r['revenue'], $r['cogs'], $r['margin']], $rows));
    return;
}

$revenue = Money::zero(); $cogs = Money::zero(); $margin = Money::zero();
foreach ($rows as $r) {
    $revenue = Money::add($revenue, $r['revenue']);
    $cogs    = Money::add($cogs,    $r['cogs']);
    $margin  = Money::add($margin,  $r['margin']);
}
$marginPct = '0';
if (Money::cmp($revenue, '0') > 0) {
    $marginPct = Money::round(Money::mul(Money::div($margin, $revenue), '100'), 1);
}

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Profit &amp; Loss</h1>
        <p class="page-header__sub">Gross margin per medicine (revenue − COGS) for the selected period.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/reports">← Reports</a>
        <a class="btn" href="/reports/pl?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv">
            <?= $ic('<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>') ?>
            Download CSV
        </a>
    </div>
</div>

<div class="card-grid mb-4">
    <div class="kpi">
        <div>
            <div class="kpi__label">Revenue</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt(Money::round($revenue, 2))) ?></div>
            <div class="kpi__delta">non-voided sales</div>
        </div>
        <div class="kpi__icon kpi__icon--success"><?= $ic('<path d="M4 19V8m6 11V4m6 15v-8m6 8v-5"/>', 20) ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">COGS</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt(Money::round($cogs, 2))) ?></div>
            <div class="kpi__delta">cost of goods sold</div>
        </div>
        <div class="kpi__icon kpi__icon--warning"><?= $ic('<path d="M3 7l9-4 9 4-9 4-9-4zm0 0v10l9 4 9-4V7"/>', 20) ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Gross margin</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt(Money::round($margin, 2))) ?></div>
            <div class="kpi__delta"><?= e($marginPct) ?>% margin</div>
        </div>
        <div class="kpi__icon"><?= $ic('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>', 20) ?></div>
    </div>
</div>

<section class="card mb-4">
    <form method="get" action="/reports/pl" class="card__body" style="padding:18px 22px;">
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
                <th>Medicine</th><th class="right">Units</th>
                <th class="right">Revenue</th><th class="right">COGS</th><th class="right">Margin</th><th class="right">Margin %</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="6" style="padding:48px; text-align:center;" class="muted">No sales for this period.</td></tr>
        <?php else: foreach ($rows as $r):
            $rRev = (float) $r['revenue'];
            $pct = $rRev > 0 ? round(((float) $r['margin'] / $rRev) * 100, 1) : 0;
        ?>
            <tr>
                <td><strong><?= e($r['brand_name']) ?></strong></td>
                <td class="num tabular"><?= e($r['units']) ?></td>
                <td class="num tabular">PKR <?= e(Money::fmt($r['revenue'])) ?></td>
                <td class="num tabular muted">PKR <?= e(Money::fmt($r['cogs'])) ?></td>
                <td class="num tabular" style="font-weight:700;">PKR <?= e(Money::fmt($r['margin'])) ?></td>
                <td class="num tabular">
                    <span class="badge badge--<?= $pct >= 30 ? 'success' : ($pct >= 10 ? 'info' : 'warning') ?>"><?= e($pct) ?>%</span>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'P&L', 'body' => $body, 'active' => 'reports',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
