<?php
declare(strict_types=1);

/**
 * Cashier session — open / close shift.
 * GET  /pos/session   → status + form
 * POST /pos/session   → action = open | close
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Money;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Sessions;

Auth::requireLogin();
$cashierId = (int) Session::userId();
$pageError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'open') {
            $float = (string) ($_POST['opening_float'] ?? '0');
            $id = Sessions::open($cashierId, $float);
            Session::flash('success', "Shift #{$id} opened with PKR " . Money::round($float, 2) . " float.");
            Router::redirect('/pos/session');
        } elseif ($action === 'close') {
            $sid = (int) ($_POST['session_id'] ?? 0);
            $counted = (string) ($_POST['counted_cash'] ?? '0');
            $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
            $r = Sessions::close($sid, $counted, $cashierId, $notes);
            Session::flash('success', "Shift closed. Variance: PKR " . $r['variance'] . ". Z-report generated.");
            Router::redirect('/sessions/' . $sid . '/z-report');
        }
    } catch (\Throwable $e) {
        $pageError = $e->getMessage();
    }
}

$current = Sessions::currentFor($cashierId);

/* Recent shifts for context. */
$recent = Db::fetchAll(
    "SELECT s.id, s.status::text AS status,
            s.opened_at::text AS opened_at, s.closed_at::text AS closed_at,
            s.opening_float::text AS opening_float,
            s.cash_variance::text AS variance,
            s.total_sales_count
       FROM cashier_sessions s
      WHERE s.cashier_id = :c
      ORDER BY s.opened_at DESC LIMIT 5",
    [':c' => $cashierId],
);

$ic = static fn (string $d, int $size = 14) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display"><?= $current ? 'Close current shift' : 'Open a new shift' ?></h1>
        <p class="page-header__sub">
            <?= $current
                ? 'End-of-day cash-up: count the drawer and confirm the variance.  A signed Z-report is generated automatically.'
                : 'Start your day by counting the cash currently in the drawer and entering it as the opening float.' ?>
        </p>
    </div>
</div>

<?php if ($pageError !== null): ?>
    <div class="flash error mb-4"><?= $ic('<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>', 18) ?><div><?= e($pageError) ?></div></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns: minmax(0, 1fr) minmax(0, 480px); gap:24px; align-items:start;">

    <!-- Action card -->
    <?php if ($current === null): ?>
        <section class="card">
            <div class="card__header">
                <h2 class="card__title font-display" style="margin:0;">Opening float</h2>
                <span class="badge badge--neutral">No open shift</span>
            </div>
            <div class="card__body">
                <form method="post" action="/pos/session">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="open">
                    <label>
                        <span>Opening float (PKR)</span>
                        <input type="text" name="opening_float" required pattern="\d+(\.\d{1,2})?" inputmode="decimal" autofocus
                               placeholder="0.00" class="tabular">
                    </label>
                    <p class="muted" style="font-size:12.5px; margin-top:8px;">
                        Count every note &amp; coin in the drawer right now.  This is the baseline against which end-of-day cash is reconciled.
                    </p>
                    <div class="mt-4">
                        <button type="submit" class="btn-lg">Open shift</button>
                    </div>
                </form>
            </div>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="card__header">
                <h2 class="card__title font-display" style="margin:0;">End-of-day cash-up</h2>
                <span class="badge badge--success">Shift #<?= e($current['id']) ?> open</span>
            </div>
            <div class="card__body">
                <dl style="display:grid; grid-template-columns:1fr 1fr; gap:10px 24px; margin:0 0 20px;">
                    <dt class="muted">Opened</dt>
                    <dd style="margin:0;"><?= e(substr((string) $current['opened_at'], 0, 19)) ?></dd>

                    <dt class="muted">Opening float</dt>
                    <dd class="tabular" style="margin:0;">PKR <?= e(Money::fmt($current['opening_float'])) ?></dd>

                    <dt class="muted">Cash sales so far</dt>
                    <dd class="tabular" style="margin:0;">PKR <?= e(Money::fmt($current['total_cash_sales'])) ?></dd>

                    <dt class="muted">Total sales count</dt>
                    <dd class="tabular" style="margin:0;"><?= e($current['total_sales_count']) ?></dd>
                </dl>

                <form method="post" action="/pos/session">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="session_id" value="<?= e($current['id']) ?>">

                    <label>
                        <span>Counted cash (PKR) — count the drawer now</span>
                        <input type="text" name="counted_cash" required autofocus
                               pattern="\d+(\.\d{1,2})?" inputmode="decimal"
                               placeholder="0.00" class="tabular">
                    </label>
                    <label>
                        <span>Notes (optional)</span>
                        <textarea name="notes" rows="2" placeholder="Anything unusual about the cash count…"></textarea>
                    </label>
                    <div class="mt-4 flex gap-2">
                        <button type="submit" class="btn-lg">Close shift &amp; generate Z-report</button>
                    </div>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <!-- Recent shifts -->
    <aside class="card">
        <div class="card__header">
            <h2 class="card__title" style="margin:0; font-size:14px;">Recent shifts</h2>
        </div>
        <?php if (count($recent) === 0): ?>
            <div class="card__body muted" style="text-align:center; padding:32px 22px;">
                No previous shifts yet.
            </div>
        <?php else: ?>
            <table style="border:0; box-shadow:none; border-radius:0;">
                <thead>
                    <tr>
                        <th>#</th><th>Opened</th><th>Status</th><th class="right">Var</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $r):
                    $cls = $r['status'] === 'RECONCILED' ? 'success' : ($r['status'] === 'CLOSED' ? 'info' : 'warning');
                ?>
                    <tr>
                        <td>#<?= e($r['id']) ?></td>
                        <td><?= e(substr((string) $r['opened_at'], 0, 10)) ?></td>
                        <td><span class="badge badge--<?= $cls ?>"><?= e($r['status']) ?></span></td>
                        <td class="num tabular"><?= $r['variance'] !== null ? 'PKR ' . e(Money::fmt($r['variance'])) : '—' ?></td>
                        <td class="row-actions">
                            <?php if ($r['status'] !== 'OPEN'): ?>
                                <a class="btn-link" href="/sessions/<?= e($r['id']) ?>/z-report">Z-report</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </aside>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Day Close',
    'body'          => $body,
    'active'        => 'shift',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
