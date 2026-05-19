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
            m.reorder_level, m.reorder_quantity, m.reorder_unit,
            COALESCE(SUM(b.current_qty), 0) AS on_hand,
            (m.reorder_level - COALESCE(SUM(b.current_qty), 0)) AS shortfall
       FROM medicines m
       LEFT JOIN batches b ON b.medicine_id = m.id
            AND b.current_qty > 0 AND b.is_quarantined = FALSE AND b.is_expired = FALSE
      WHERE m.is_active = TRUE AND m.deleted_at IS NULL AND m.reorder_level > 0
      GROUP BY m.id
     HAVING COALESCE(SUM(b.current_qty), 0) < m.reorder_level
      ORDER BY shortfall DESC LIMIT 500",
);

if (($_GET['export'] ?? '') === 'csv') {
    Csv::stream('low_stock',
        ['Medicine','Generic','On hand','Reorder at','Reorder qty','Reorder unit','Shortfall'],
        array_map(fn ($r) => [$r['brand_name'], $r['generic_name'], $r['on_hand'],
            $r['reorder_level'], $r['reorder_quantity'], $r['reorder_unit'], $r['shortfall']], $rows));
    return;
}

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Low stock</h1>
        <p class="page-header__sub">Medicines whose total in-stock units are below the configured reorder level.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/reports">← Reports</a>
        <a class="btn" href="/reports/low-stock?export=csv">
            <?= $ic('<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>') ?>
            Download CSV
        </a>
    </div>
</div>

<?php if (count($rows) === 0): ?>
    <div class="empty-state">
        <svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
        <h3>All medicines above reorder level</h3>
        <p>Stock looks healthy.  Low-stock alerts will appear here as soon as a medicine drops below its reorder threshold.</p>
    </div>
<?php else: ?>
    <section class="card" style="padding:0; overflow:hidden;">
        <table>
            <thead><tr>
                <th>Medicine</th>
                <th class="right">On hand</th>
                <th class="right">Reorder at</th>
                <th class="right">Reorder qty</th>
                <th class="right">Shortfall</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['brand_name']) ?></strong><br><small class="muted"><?= e($r['generic_name']) ?></small></td>
                    <td class="num tabular" style="color:hsl(var(--destructive)); font-weight:600;">
                        <?= e($r['on_hand']) ?> <?= e($r['base_unit']) ?>
                    </td>
                    <td class="num tabular muted"><?= e($r['reorder_level']) ?></td>
                    <td class="num tabular"><?= e($r['reorder_quantity']) ?> <small class="muted"><?= e($r['reorder_unit']) ?></small></td>
                    <td class="num tabular">
                        <span class="badge badge--danger">−<?= e($r['shortfall']) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Low stock', 'body' => $body, 'active' => 'reports',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
