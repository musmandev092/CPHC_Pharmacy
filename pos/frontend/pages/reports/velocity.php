<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Csv;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);

$rows = Db::fetchAll(
    "SELECT m.id, m.brand_name, m.generic_name, m.base_unit,
            SUM(CASE WHEN s.sold_at >= NOW() - INTERVAL '7 days'  THEN si.qty_in_base_units ELSE 0 END) AS d7,
            SUM(CASE WHEN s.sold_at >= NOW() - INTERVAL '30 days' THEN si.qty_in_base_units ELSE 0 END) AS d30,
            SUM(CASE WHEN s.sold_at >= NOW() - INTERVAL '90 days' THEN si.qty_in_base_units ELSE 0 END) AS d90
       FROM medicines m
       LEFT JOIN sale_items si ON si.medicine_id = m.id
       LEFT JOIN sales s       ON s.id = si.sale_id AND s.status <> 'VOIDED'
      WHERE m.is_active = TRUE AND m.deleted_at IS NULL
      GROUP BY m.id
      ORDER BY d30 DESC NULLS LAST LIMIT 200",
);

if (($_GET['export'] ?? '') === 'csv') {
    Csv::stream('velocity', ['Medicine','7 days','30 days','90 days'],
        array_map(fn ($r) => [$r['brand_name'], $r['d7'], $r['d30'], $r['d90']], $rows));
    return;
}

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Sales velocity</h1>
        <p class="page-header__sub">Units sold per medicine over the last 7, 30, and 90 days — the higher the row, the faster it moves.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/reports">← Reports</a>
        <a class="btn" href="/reports/velocity?export=csv">
            <?= $ic('<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>') ?>
            Download CSV
        </a>
    </div>
</div>

<section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead><tr>
            <th>Medicine</th>
            <th class="right">7 days</th>
            <th class="right">30 days</th>
            <th class="right">90 days</th>
        </tr></thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="4" style="padding:48px; text-align:center;" class="muted">No medicines.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= e($r['brand_name']) ?></strong><br><small class="muted"><?= e($r['generic_name']) ?></small></td>
                <td class="num tabular"><?= e($r['d7']) ?> <small class="muted"><?= e($r['base_unit']) ?></small></td>
                <td class="num tabular" style="font-weight:600;"><?= e($r['d30']) ?> <small class="muted"><?= e($r['base_unit']) ?></small></td>
                <td class="num tabular muted"><?= e($r['d90']) ?> <?= e($r['base_unit']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Velocity', 'body' => $body, 'active' => 'reports',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
