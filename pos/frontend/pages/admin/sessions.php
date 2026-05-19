<?php
declare(strict_types=1);

/**
 * Manager reconciliation queue.
 *
 * Shows CLOSED sessions awaiting sign-off, plus recently-RECONCILED ones for
 * reference.  POST to reconcile: optionally recount the cash, append a note,
 * and transition CLOSED → RECONCILED.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Reconciler;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);
$managerId = (int) Session::userId();
$pageError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reconcile') {
    try {
        $sid     = (int) ($_POST['session_id'] ?? 0);
        $recount = trim((string) ($_POST['recounted_cash'] ?? '')) ?: null;
        $notes   = trim((string) ($_POST['notes'] ?? '')) ?: null;
        $r = Reconciler::reconcile($sid, $managerId, $recount, $notes);
        Session::flash('success', "Session #{$sid} reconciled. Variance: PKR " . $r['variance'] . '.');
        Router::redirect('/admin/sessions');
    } catch (\Throwable $e) {
        $pageError = $e->getMessage();
    }
}

$closed = Db::fetchAll(
    "SELECT s.id, s.cashier_id, u.full_name AS cashier_name,
            s.opened_at::text AS opened_at, s.closed_at::text AS closed_at,
            s.opening_float::text AS opening_float,
            s.expected_cash_in_drawer::text AS expected_cash,
            s.counted_cash::text AS counted_cash,
            s.cash_variance::text AS variance,
            s.total_sales_count, s.notes
       FROM cashier_sessions s
       JOIN users u ON u.id = s.cashier_id
      WHERE s.status = 'CLOSED'
      ORDER BY s.closed_at DESC",
);

$reconciled = Db::fetchAll(
    "SELECT s.id, u.full_name AS cashier_name,
            s.closed_at::text AS closed_at,
            s.cash_variance::text AS variance,
            s.total_sales_count
       FROM cashier_sessions s
       JOIN users u ON u.id = s.cashier_id
      WHERE s.status = 'RECONCILED'
      ORDER BY s.closed_at DESC
      LIMIT 20",
);

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Reconcile sessions</h1>
        <p class="page-header__sub">Closed shifts awaiting manager sign-off.  Reviewing the variance is your last chance to flag a count error before the day's books close.</p>
    </div>
    <div class="page-header__actions">
        <span class="badge badge--<?= count($closed) > 0 ? 'warning' : 'success' ?>">
            <?= count($closed) ?> pending
        </span>
    </div>
</div>

<?php if ($pageError !== null): ?>
    <div class="flash error mb-4"><?= e($pageError) ?></div>
<?php endif; ?>

<?php if (count($closed) === 0): ?>
    <div class="empty-state">
        <svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
        <h3>Nothing waiting for sign-off</h3>
        <p>You're all caught up.  New shifts will appear here as soon as cashiers close them.</p>
    </div>
<?php else: ?>
    <?php foreach ($closed as $s):
        $isNeg = str_starts_with((string) $s['variance'], '-');
    ?>
        <details open class="card mb-4" style="margin-bottom:16px;">
            <summary style="padding:16px 22px; cursor:pointer; list-style:none; display:flex; align-items:center; gap:12px; flex-wrap:wrap; border-bottom:1px solid hsl(var(--border));">
                <strong>Session #<?= e($s['id']) ?></strong>
                <span class="muted">&middot;</span>
                <span><?= e($s['cashier_name']) ?></span>
                <span class="muted">&middot; closed <?= e(substr((string) $s['closed_at'], 0, 19)) ?></span>
                <span style="margin-left:auto;" class="badge badge--<?= $isNeg ? 'danger' : 'success' ?>">
                    Variance PKR <?= e($s['variance']) ?>
                </span>
            </summary>
            <div style="padding:18px 22px;">
                <dl style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px 24px; margin:0;">
                    <div><dt class="muted">Opening float</dt><dd class="tabular" style="margin:0; font-weight:600;">PKR <?= e($s['opening_float']) ?></dd></div>
                    <div><dt class="muted">Expected cash</dt><dd class="tabular" style="margin:0; font-weight:600;">PKR <?= e($s['expected_cash']) ?></dd></div>
                    <div><dt class="muted">Counted cash</dt><dd class="tabular" style="margin:0; font-weight:600;">PKR <?= e($s['counted_cash']) ?></dd></div>
                    <div><dt class="muted">Sales count</dt><dd class="tabular" style="margin:0; font-weight:600;"><?= e($s['total_sales_count']) ?></dd></div>
                </dl>
                <?php if (!empty($s['notes'])): ?>
                    <p class="muted mt-3" style="font-size:13px;">Notes: <?= e($s['notes']) ?></p>
                <?php endif; ?>

                <form method="post" action="/admin/sessions" class="mt-4" style="border-top:1px solid hsl(var(--border)); padding-top:16px;">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                    <input type="hidden" name="action" value="reconcile">
                    <input type="hidden" name="session_id" value="<?= e($s['id']) ?>">
                    <div class="form-grid">
                        <label><span>Recount (optional)</span>
                            <input type="text" name="recounted_cash" pattern="\d+(\.\d{1,2})?" inputmode="decimal"
                                   placeholder="leave empty to accept the cashier's count" class="tabular">
                        </label>
                        <label><span>Manager notes</span>
                            <input type="text" name="notes" placeholder="why does the variance look the way it does?">
                        </label>
                    </div>
                    <div class="mt-3 flex gap-2">
                        <button type="submit">Reconcile</button>
                        <a class="btn-secondary" href="/sessions/<?= e($s['id']) ?>/z-report">View Z-report</a>
                    </div>
                </form>
            </div>
        </details>
    <?php endforeach; ?>
<?php endif; ?>

<h2 class="font-display mt-5">Recently reconciled</h2>
<?php if (count($reconciled) === 0): ?>
    <div class="empty-state">No reconciled shifts yet.</div>
<?php else: ?>
    <section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead><tr><th>Session</th><th>Cashier</th><th>Closed</th><th class="right">Variance</th><th class="right">Sales</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($reconciled as $s):
            $isNeg = str_starts_with((string) $s['variance'], '-');
        ?>
            <tr>
                <td><strong>#<?= e($s['id']) ?></strong></td>
                <td><?= e($s['cashier_name']) ?></td>
                <td class="muted"><?= e(substr((string) $s['closed_at'], 0, 19)) ?></td>
                <td class="num tabular">
                    <span class="badge badge--<?= $isNeg ? 'danger' : 'success' ?>">PKR <?= e($s['variance']) ?></span>
                </td>
                <td class="num tabular"><?= e($s['total_sales_count']) ?></td>
                <td class="row-actions"><a class="btn-link" href="/sessions/<?= e($s['id']) ?>/z-report">Z-report</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </section>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Sessions',
    'body'          => $body,
    'active'        => 'reconcile',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
