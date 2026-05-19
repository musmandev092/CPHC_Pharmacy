<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Money;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Sessions;

Auth::requireLogin();

$role  = Session::role() ?? '';
$name  = Session::fullName() ?? Session::username() ?? '—';
$first = explode(' ', (string) $name)[0] ?? $name;
$cashierId = (int) Session::userId();
$isMgr = in_array($role, [Auth::ROLE_MANAGER, Auth::ROLE_ADMIN], true);
$isAdmin = $role === Auth::ROLE_ADMIN;

/* Live data for the home screen. */
$today = Db::fetchOne(
    "SELECT count(*) AS n,
            COALESCE(SUM(grand_total), 0)::text AS total,
            COALESCE(SUM(CASE WHEN payment_mode='CASH' THEN grand_total END), 0)::text AS cash,
            COALESCE(SUM(CASE WHEN payment_mode='CARD' THEN grand_total END), 0)::text AS card
       FROM sales WHERE sold_at >= CURRENT_DATE AND status <> 'VOIDED'",
);
$openShift = Sessions::currentFor($cashierId);

$mgr = ['low' => 0, 'pending' => 0, 'expiring' => 0];
if ($isMgr) {
    $mgr['low'] = (int) (Db::fetchOne(
        "SELECT count(*) AS c FROM (
            SELECT m.id
              FROM medicines m
              LEFT JOIN batches b ON b.medicine_id = m.id AND b.current_qty > 0
                                AND b.is_quarantined = FALSE AND b.is_expired = FALSE
             WHERE m.is_active = TRUE AND m.deleted_at IS NULL AND m.reorder_level > 0
             GROUP BY m.id, m.reorder_level
            HAVING COALESCE(SUM(b.current_qty), 0) < m.reorder_level
         ) x"
    )['c'] ?? 0);
    $mgr['pending'] = (int) (Db::fetchOne(
        "SELECT count(*) AS c FROM returns WHERE status = 'PENDING_REVIEW'"
    )['c'] ?? 0);
    $mgr['expiring'] = (int) (Db::fetchOne(
        "SELECT count(*) AS c FROM batches
          WHERE current_qty > 0 AND is_quarantined = FALSE AND is_expired = FALSE
            AND expiry_date <= CURRENT_DATE + INTERVAL '60 days'"
    )['c'] ?? 0);
}

$today_iso = (new \DateTimeImmutable('now'))->format('l, j F Y');

/* SVG icon helper (consistent stroke style). */
$ic = static function (string $d): string {
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
};

ob_start();
?>

<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Welcome back, <?= e($first) ?></h1>
        <p class="page-header__sub">
            <?= e($today_iso) ?>
            · <strong><?= e($role) ?></strong> at Care Point Health Clinic
        </p>
    </div>
    <?php if ($openShift !== null): ?>
        <div class="page-header__actions">
            <span class="badge badge--success">Shift #<?= e($openShift['id']) ?> open</span>
            <a class="btn-secondary" href="/pos/session">Close shift</a>
        </div>
    <?php else: ?>
        <div class="page-header__actions">
            <span class="badge badge--neutral">No open shift</span>
            <a class="btn" href="/pos/session">Open shift</a>
        </div>
    <?php endif; ?>
</div>

<!-- ── KPI cards ─────────────────────────────────────────────────────── -->
<div class="card-grid mb-6">
    <div class="kpi">
        <div>
            <div class="kpi__label">Sales today</div>
            <div class="kpi__value"><?= e($today['n']) ?></div>
            <div class="kpi__delta">PKR <?= e(Money::fmt($today['total'])) ?> total</div>
        </div>
        <div class="kpi__icon">
            <?= $ic('<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>') ?>
        </div>
    </div>

    <div class="kpi">
        <div>
            <div class="kpi__label">Cash collected</div>
            <div class="kpi__value">PKR <?= e(Money::fmt($today['cash'])) ?></div>
            <div class="kpi__delta">paid in cash today</div>
        </div>
        <div class="kpi__icon kpi__icon--success">
            <?= $ic('<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/>') ?>
        </div>
    </div>

    <div class="kpi">
        <div>
            <div class="kpi__label">Card collected</div>
            <div class="kpi__value">PKR <?= e(Money::fmt($today['card'])) ?></div>
            <div class="kpi__delta">via card today</div>
        </div>
        <div class="kpi__icon kpi__icon--info">
            <?= $ic('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>') ?>
        </div>
    </div>

    <?php if ($isMgr): ?>
        <div class="kpi">
            <div>
                <div class="kpi__label">Low stock</div>
                <div class="kpi__value" style="color: <?= $mgr['low'] > 0 ? 'hsl(var(--destructive))' : 'inherit' ?>;">
                    <?= e($mgr['low']) ?>
                </div>
                <div class="kpi__delta">medicines below reorder</div>
            </div>
            <div class="kpi__icon kpi__icon--danger">
                <?= $ic('<path d="M12 9v4M12 17h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>') ?>
            </div>
        </div>

        <div class="kpi">
            <div>
                <div class="kpi__label">Expiring ≤ 60 days</div>
                <div class="kpi__value" style="color: <?= $mgr['expiring'] > 0 ? 'hsl(var(--warning))' : 'inherit' ?>;">
                    <?= e($mgr['expiring']) ?>
                </div>
                <div class="kpi__delta">batches</div>
            </div>
            <div class="kpi__icon kpi__icon--warning">
                <?= $ic('<path d="M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>') ?>
            </div>
        </div>

        <div class="kpi">
            <div>
                <div class="kpi__label">Returns to adjudicate</div>
                <div class="kpi__value" style="color: <?= $mgr['pending'] > 0 ? 'hsl(var(--warning))' : 'inherit' ?>;">
                    <?= e($mgr['pending']) ?>
                </div>
                <div class="kpi__delta">awaiting review</div>
            </div>
            <div class="kpi__icon kpi__icon--warning">
                <?= $ic('<path d="M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3"/>') ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ── Quick actions ─────────────────────────────────────────────────── -->
<h2 class="font-display" style="margin-top:32px;">Quick actions</h2>
<div class="card-grid">
    <a class="action-card" href="/pos">
        <div class="action-card__icon"><?= $ic('<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>') ?></div>
        <div>
            <div class="action-card__title">Open POS terminal</div>
            <div class="action-card__desc">Sell, return, reprint receipt</div>
        </div>
    </a>

    <a class="action-card" href="/pos/session">
        <div class="action-card__icon"><?= $ic('<path d="M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>') ?></div>
        <div>
            <div class="action-card__title"><?= $openShift ? 'Close current shift' : 'Open a new shift' ?></div>
            <div class="action-card__desc"><?= $openShift ? 'Variance, Z-report, sign-out' : 'Enter opening float' ?></div>
        </div>
    </a>

    <a class="action-card" href="/pos/returns">
        <div class="action-card__icon"><?= $ic('<path d="M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3"/>') ?></div>
        <div>
            <div class="action-card__title">Initiate a return</div>
            <div class="action-card__desc">Look up receipt, refund a line</div>
        </div>
    </a>

    <?php if ($isMgr): ?>
        <a class="action-card" href="/inventory/grn/new">
            <div class="action-card__icon"><?= $ic('<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 5h6M9 12h6M9 16h6"/>') ?></div>
            <div>
                <div class="action-card__title">New GRN</div>
                <div class="action-card__desc">Record a goods-received note</div>
            </div>
        </a>

        <a class="action-card" href="/inventory">
            <div class="action-card__icon"><?= $ic('<path d="M3 7l9-4 9 4-9 4-9-4zm0 0v10l9 4 9-4V7"/>') ?></div>
            <div>
                <div class="action-card__title">Inventory</div>
                <div class="action-card__desc">Medicines, batches, adjustments</div>
            </div>
        </a>

        <a class="action-card" href="/reports">
            <div class="action-card__icon"><?= $ic('<path d="M4 19V8m6 11V4m6 15v-8m6 8v-5"/>') ?></div>
            <div>
                <div class="action-card__title">Reports</div>
                <div class="action-card__desc">Sales, P&amp;L, expiry, audit log</div>
            </div>
        </a>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
        <a class="action-card" href="/admin/users">
            <div class="action-card__icon"><?= $ic('<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8z"/>') ?></div>
            <div>
                <div class="action-card__title">Users</div>
                <div class="action-card__desc">Cashiers, managers, admin roles</div>
            </div>
        </a>

        <a class="action-card" href="/admin/system">
            <div class="action-card__icon"><?= $ic('<path d="M9 3v18M3 9h18M3 15h18M15 3v18"/>') ?></div>
            <div>
                <div class="action-card__title">System health</div>
                <div class="action-card__desc">DB version, HMAC chain status</div>
            </div>
        </a>
    <?php endif; ?>
</div>

<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Dashboard',
    'body'          => $body,
    'active'        => 'home',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
