<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Money;
use CPHC\Session;
use CPHC\View;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);

$q = trim((string) ($_GET['q'] ?? ''));
$rows = Db::fetchAll(
    "SELECT b.id, m.brand_name, m.generic_name, m.base_unit,
            b.batch_number, b.expiry_date::text AS expiry_date,
            b.current_qty, b.received_qty, b.foc_qty,
            b.cost_per_unit::text AS cost_per_unit,
            b.mrp_per_unit::text  AS mrp_per_unit,
            b.is_quarantined, b.is_expired,
            (b.expiry_date - CURRENT_DATE) AS days
       FROM batches b
       JOIN medicines m ON m.id = b.medicine_id
      WHERE (:q = '' OR LOWER(m.brand_name) LIKE :like OR LOWER(m.generic_name) LIKE :like OR b.batch_number ILIKE :like)
      ORDER BY b.expiry_date ASC, m.brand_name
      LIMIT 200",
    [':q' => $q, ':like' => '%' . strtolower($q) . '%'],
);

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Stock</h1>
        <p class="page-header__sub">Live batch view across all medicines, sorted by earliest expiry.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/inventory">← Inventory</a>
    </div>
</div>

<section class="card mb-4">
    <form method="get" action="/inventory/stock" class="card__body" style="padding:18px 22px;">
        <label style="margin:0;">
            <span>Search</span>
            <input type="search" name="q" placeholder="Brand, generic, or batch #…" value="<?= e($q) ?>" style="max-width:36em;">
        </label>
    </form>
</section>

<section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead>
            <tr>
                <th>Medicine</th><th>Batch</th><th>Expiry</th>
                <th class="right">Qty</th>
                <th class="right">Cost / unit</th>
                <th class="right">MRP / unit</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="7" style="padding:48px; text-align:center;" class="muted">No batches match.</td></tr>
        <?php else: foreach ($rows as $b):
            $days = (int) $b['days'];
            if ($b['is_expired'] || $days <= 0)    { $label = 'EXPIRED';     $badge = 'danger'; }
            elseif ($b['is_quarantined'])          { $label = 'QUARANTINED'; $badge = 'danger'; }
            elseif ($days <= 30)                   { $label = 'CONFIRM';     $badge = 'warning'; }
            elseif ($days <= 60)                   { $label = 'AMBER';       $badge = 'info'; }
            elseif ($days <= 90)                   { $label = 'INFO';        $badge = 'neutral'; }
            else                                   { $label = 'OK';          $badge = 'success'; }
        ?>
            <tr>
                <td>
                    <strong><?= e($b['brand_name']) ?></strong>
                    <br><small class="muted"><?= e($b['generic_name']) ?></small>
                </td>
                <td><code style="font-size:11.5px;"><?= e($b['batch_number']) ?></code></td>
                <td>
                    <?= e($b['expiry_date']) ?>
                    <br><small class="muted"><?= e($days) ?> days</small>
                </td>
                <td class="num tabular"><?= e($b['current_qty']) ?> <?= e($b['base_unit']) ?></td>
                <td class="num tabular">PKR <?= e(Money::fmt($b['cost_per_unit'])) ?></td>
                <td class="num tabular">PKR <?= e(Money::fmt($b['mrp_per_unit'])) ?></td>
                <td><span class="badge badge--<?= $badge ?>"><?= e($label) ?></span></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Stock',
    'body'          => $body,
    'active'        => 'stock',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
