<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Money;
use CPHC\Session;
use CPHC\View;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);

$totals = Db::fetchOne(
    "SELECT
       (SELECT count(*) FROM medicines WHERE is_active = TRUE AND deleted_at IS NULL) AS medicines_count,
       (SELECT count(*) FROM batches WHERE current_qty > 0 AND is_quarantined = FALSE AND is_expired = FALSE) AS active_batches,
       (SELECT COALESCE(SUM(current_qty * cost_per_unit), 0) FROM batches WHERE current_qty > 0 AND is_quarantined = FALSE)::text AS stock_value_cost,
       (SELECT COALESCE(SUM(current_qty * mrp_per_unit), 0) FROM batches WHERE current_qty > 0 AND is_quarantined = FALSE)::text AS stock_value_mrp",
);

$expiringSoon = Db::fetchAll(
    "SELECT b.id, m.brand_name, b.batch_number, b.expiry_date::text AS expiry_date,
            b.current_qty, m.base_unit,
            (b.expiry_date - CURRENT_DATE) AS days
       FROM batches b
       JOIN medicines m ON m.id = b.medicine_id
      WHERE b.current_qty > 0 AND b.is_quarantined = FALSE AND b.is_expired = FALSE
        AND b.expiry_date <= CURRENT_DATE + INTERVAL '60 days'
      ORDER BY b.expiry_date ASC LIMIT 10",
);

$lowStock = Db::fetchAll(
    "SELECT m.id, m.brand_name, m.generic_name, m.base_unit, m.reorder_level,
            COALESCE(SUM(b.current_qty), 0) AS on_hand
       FROM medicines m
       LEFT JOIN batches b ON b.medicine_id = m.id
                          AND b.current_qty > 0 AND b.is_quarantined = FALSE AND b.is_expired = FALSE
      WHERE m.is_active = TRUE AND m.deleted_at IS NULL AND m.reorder_level > 0
      GROUP BY m.id
     HAVING COALESCE(SUM(b.current_qty), 0) < m.reorder_level
      ORDER BY (m.reorder_level - COALESCE(SUM(b.current_qty), 0)) DESC LIMIT 10",
);

$ic = static fn (string $d, int $size = 20) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Inventory</h1>
        <p class="page-header__sub">Stock value, expiring lots, and reorder alerts at a glance.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/inventory/medicines">Medicines</a>
        <a class="btn-secondary" href="/inventory/stock">Stock</a>
        <a class="btn-secondary" href="/inventory/adjustments">Adjust</a>
        <a class="btn" href="/inventory/grn">+ New GRN</a>
    </div>
</div>

<div class="card-grid mb-6">
    <div class="kpi">
        <div>
            <div class="kpi__label">Active medicines</div>
            <div class="kpi__value tabular"><?= e($totals['medicines_count']) ?></div>
            <div class="kpi__delta">in catalog</div>
        </div>
        <div class="kpi__icon"><?= $ic('<path d="M10 3l4 4-7 7H3v-4l7-7zM14 7l5-5 3 3-5 5"/>') ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Active batches</div>
            <div class="kpi__value tabular"><?= e($totals['active_batches']) ?></div>
            <div class="kpi__delta">in stock</div>
        </div>
        <div class="kpi__icon kpi__icon--info"><?= $ic('<path d="M3 7l9-4 9 4-9 4-9-4zm0 0v10l9 4 9-4V7"/>') ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Stock at cost</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt($totals['stock_value_cost'] ?? '0')) ?></div>
            <div class="kpi__delta">inventory carry value</div>
        </div>
        <div class="kpi__icon kpi__icon--success"><?= $ic('<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/>') ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Stock at MRP</div>
            <div class="kpi__value tabular">PKR <?= e(Money::fmt($totals['stock_value_mrp'] ?? '0')) ?></div>
            <div class="kpi__delta">potential revenue</div>
        </div>
        <div class="kpi__icon kpi__icon--info"><?= $ic('<path d="M4 19V8m6 11V4m6 15v-8m6 8v-5"/>') ?></div>
    </div>
</div>

<div style="display:grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap:24px;">
    <section class="card">
        <div class="card__header">
            <h2 class="card__title">Expiring in 60 days</h2>
            <a class="btn-link" href="/reports/expiry">View all →</a>
        </div>
        <?php if (count($expiringSoon) === 0): ?>
            <div class="card__body muted center">Nothing expiring soon. </div>
        <?php else: ?>
            <table style="border:0; border-radius:0; box-shadow:none;">
                <thead><tr><th>Medicine</th><th>Batch</th><th>Expiry</th><th class="right">Qty</th></tr></thead>
                <tbody>
                <?php foreach ($expiringSoon as $b):
                    $d = (int) $b['days'];
                    $cls = $d <= 0 ? 'danger' : ($d <= 30 ? 'warning' : 'info');
                ?>
                    <tr>
                        <td><strong><?= e($b['brand_name']) ?></strong></td>
                        <td class="muted"><?= e($b['batch_number']) ?></td>
                        <td>
                            <span class="badge badge--<?= $cls ?>"><?= e($b['expiry_date']) ?></span>
                            <br><small class="muted"><?= e($d) ?> days</small>
                        </td>
                        <td class="num tabular"><?= e($b['current_qty']) ?> <?= e($b['base_unit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <div class="card__header">
            <h2 class="card__title">Low stock</h2>
            <a class="btn-link" href="/reports/low-stock">View all →</a>
        </div>
        <?php if (count($lowStock) === 0): ?>
            <div class="card__body muted center">All medicines above reorder level. </div>
        <?php else: ?>
            <table style="border:0; border-radius:0; box-shadow:none;">
                <thead><tr><th>Medicine</th><th class="right">On hand</th><th class="right">Reorder at</th></tr></thead>
                <tbody>
                <?php foreach ($lowStock as $m): ?>
                    <tr>
                        <td>
                            <strong><?= e($m['brand_name']) ?></strong>
                            <br><small class="muted"><?= e($m['generic_name']) ?></small>
                        </td>
                        <td class="num tabular" style="color:hsl(var(--destructive));"><?= e($m['on_hand']) ?> <?= e($m['base_unit']) ?></td>
                        <td class="num tabular"><?= e($m['reorder_level']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Inventory',
    'body'          => $body,
    'active'        => 'inventory',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
