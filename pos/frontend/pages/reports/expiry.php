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

$rows = Db::fetchAll(
    "SELECT b.id, m.brand_name, m.generic_name, b.batch_number,
            b.expiry_date::text AS expiry_date,
            (b.expiry_date - CURRENT_DATE) AS days,
            b.current_qty, m.base_unit,
            b.cost_per_unit::text AS cost_per_unit,
            (b.current_qty * b.cost_per_unit)::text AS at_risk_value
       FROM batches b
       JOIN medicines m ON m.id = b.medicine_id
      WHERE b.current_qty > 0 AND b.is_quarantined = FALSE
      ORDER BY b.expiry_date ASC LIMIT 500",
);

if (($_GET['export'] ?? '') === 'csv') {
    Csv::stream('expiry', ['Medicine','Batch','Expiry','Days','Qty','Cost/unit','At-risk value'],
        array_map(fn ($r) => [$r['brand_name'], $r['batch_number'], $r['expiry_date'],
            $r['days'], $r['current_qty'], $r['cost_per_unit'], $r['at_risk_value']], $rows));
    return;
}

/* Tier counts for the KPI strip */
$tiers = ['blocked' => 0, 'confirm' => 0, 'amber' => 0, 'atRisk' => '0'];
foreach ($rows as $r) {
    $d = (int) $r['days'];
    if ($d <= 0)        $tiers['blocked']++;
    elseif ($d <= 30)   $tiers['confirm']++;
    elseif ($d <= 60)   $tiers['amber']++;
    if ($d <= 60) {
        $tiers['atRisk'] = Money::add($tiers['atRisk'], (string) $r['at_risk_value']);
    }
}

$ic = static fn (string $d, int $size = 20) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Expiry report</h1>
        <p class="page-header__sub">Every active batch with stock, sorted by earliest expiry first.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/reports">← Reports</a>
        <a class="btn" href="/reports/expiry?export=csv"><?= $ic('<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>', 16) ?> Download CSV</a>
    </div>
</div>

<div class="card-grid mb-4">
    <div class="kpi">
        <div>
            <div class="kpi__label">Expired</div>
            <div class="kpi__value tabular" style="color:hsl(var(--destructive));"><?= e($tiers['blocked']) ?></div>
            <div class="kpi__delta">batches blocked from sale</div>
        </div>
        <div class="kpi__icon kpi__icon--danger"><?= $ic('<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.01"/>') ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Confirm (≤30 d)</div>
            <div class="kpi__value tabular" style="color:hsl(var(--warning));"><?= e($tiers['confirm']) ?></div>
            <div class="kpi__delta">flagged at POS</div>
        </div>
        <div class="kpi__icon kpi__icon--warning"><?= $ic('<path d="M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>') ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Amber (≤60 d)</div>
            <div class="kpi__value tabular" style="color:hsl(var(--info));"><?= e($tiers['amber']) ?></div>
            <div class="kpi__delta">watchlist</div>
        </div>
        <div class="kpi__icon kpi__icon--info"><?= $ic('<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>') ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">At-risk value</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt(Money::round($tiers['atRisk'], 2))) ?></div>
            <div class="kpi__delta">sum of ≤60 d cost</div>
        </div>
        <div class="kpi__icon"><?= $ic('<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>') ?></div>
    </div>
</div>

<section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead>
            <tr>
                <th>Medicine</th><th>Batch</th><th>Expiry</th><th>Tier</th>
                <th class="right">Qty</th><th class="right">At-risk (cost)</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="6" style="padding:48px; text-align:center;" class="muted">No batches in stock.</td></tr>
        <?php else: foreach ($rows as $r):
            $d = (int) $r['days'];
            [$label, $cls] = $d <= 0   ? ['BLOCKED',  'danger']
                : ($d <= 30 ? ['CONFIRM',  'warning']
                : ($d <= 60 ? ['AMBER',    'info']
                : ($d <= 90 ? ['INFO',     'neutral']
                : ['OK', 'success'])));
        ?>
            <tr>
                <td><strong><?= e($r['brand_name']) ?></strong><br><small class="muted"><?= e($r['generic_name']) ?></small></td>
                <td><code style="font-size:11.5px;"><?= e($r['batch_number']) ?></code></td>
                <td><?= e($r['expiry_date']) ?><br><small class="muted"><?= e($d) ?> days</small></td>
                <td><span class="badge badge--<?= $cls ?>"><?= e($label) ?></span></td>
                <td class="num tabular"><?= e($r['current_qty']) ?> <?= e($r['base_unit']) ?></td>
                <td class="num tabular"><?php if ($d <= 60): ?>PKR <?= e(Money::fmt($r['at_risk_value'])) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Expiry', 'body' => $body, 'active' => 'reports',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
