<?php
declare(strict_types=1);

/**
 * System health.  Read-only.  Database connection, audit-chain integrity,
 * runtime configuration.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Session;
use CPHC\View;

Auth::requireRole(Auth::ROLE_ADMIN);

$db = ['ok' => false, 'version' => '?', 'tables' => 0, 'err' => null];
try {
    $v = Db::fetchOne('SELECT version() AS v');
    $db['version'] = preg_match('/PostgreSQL [\d.]+/', (string) $v['v'], $m) ? $m[0] : (string) $v['v'];
    $tc = Db::fetchOne(
        "SELECT count(*) AS c FROM information_schema.tables WHERE table_schema = 'public'"
    );
    $db['tables'] = (int) $tc['c'];
    $db['ok'] = true;
} catch (\Throwable $e) { $db['err'] = $e->getMessage(); }

$chain = ['count' => 0, 'broken' => null];
try {
    $chain['count'] = (int) Db::fetchOne('SELECT count(*) AS c FROM audit_log WHERE row_hmac <> \'\'')['c'];
    $bad = Db::fetchOne(
        "WITH ord AS (
            SELECT id, row_hmac, prev_hmac,
                   LAG(row_hmac) OVER (ORDER BY id) AS expected_prev
              FROM audit_log
             WHERE row_hmac <> ''
             ORDER BY id DESC LIMIT 1000
        )
        SELECT min(id) AS first_break FROM ord
         WHERE expected_prev IS NOT NULL AND expected_prev <> prev_hmac"
    );
    $chain['broken'] = $bad['first_break'] ?? null;
} catch (\Throwable) {}

$config = [
    'APP_ENV'   => getenv('APP_ENV') ?: '?',
    'APP_TZ'    => getenv('APP_TZ')  ?: '?',
    'DB_HOST'   => getenv('DB_HOST') ?: '?',
    'PHP'       => PHP_VERSION,
    // Required: app won't run without these.
    'bcmath'    => extension_loaded('bcmath')      ? 'on' : 'off',
    'pdo_pgsql' => extension_loaded('pdo_pgsql')   ? 'on' : 'off',
    // Performance-only: nice to have, app works without it.
    'opcache'   => extension_loaded('Zend OPcache')? 'on' : 'off',
];

$ic = static fn (string $d, int $size = 20) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">System health</h1>
        <p class="page-header__sub">Database connection, audit-chain integrity, and runtime configuration.</p>
    </div>
</div>

<div class="card-grid mb-6">
    <div class="kpi">
        <div>
            <div class="kpi__label">Database</div>
            <div class="kpi__value tabular" style="font-size:1.25rem;">
                <?php if ($db['ok']): ?>
                    <span style="color:hsl(var(--success));">Connected</span>
                <?php else: ?>
                    <span style="color:hsl(var(--destructive));">Down</span>
                <?php endif; ?>
            </div>
            <div class="kpi__delta"><?= e($db['version']) ?> &middot; <?= e($db['tables']) ?> tables</div>
        </div>
        <div class="kpi__icon <?= $db['ok'] ? 'kpi__icon--success' : 'kpi__icon--danger' ?>">
            <?= $ic('<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0018 0V5M3 12a9 3 0 0018 0"/>') ?>
        </div>
    </div>

    <div class="kpi">
        <div>
            <div class="kpi__label">Audit HMAC chain</div>
            <div class="kpi__value tabular" style="font-size:1.25rem;">
                <?php if ($chain['broken'] === null): ?>
                    <span style="color:hsl(var(--success));">Intact</span>
                <?php else: ?>
                    <span style="color:hsl(var(--destructive));">Tampered</span>
                <?php endif; ?>
            </div>
            <div class="kpi__delta"><?= e($chain['count']) ?> chained rows
                <?= $chain['broken'] === null ? '· last 1 000 verified' : ('· first break at id ' . e($chain['broken'])) ?>
            </div>
        </div>
        <div class="kpi__icon <?= $chain['broken'] === null ? 'kpi__icon--success' : 'kpi__icon--danger' ?>">
            <?= $ic('<path d="M12 2L4 6v6c0 5 3.4 9.4 8 10 4.6-.6 8-5 8-10V6l-8-4z"/><path d="M9 12l2 2 4-4"/>') ?>
        </div>
    </div>

    <div class="kpi">
        <div>
            <div class="kpi__label">PHP &amp; extensions</div>
            <div class="kpi__value tabular" style="font-size:1.25rem;">PHP <?= e(PHP_VERSION) ?></div>
            <div class="kpi__delta">
                <?= ['bcmath' => extension_loaded('bcmath'), 'pdo_pgsql' => extension_loaded('pdo_pgsql')]
                    === ['bcmath' => true, 'pdo_pgsql' => true]
                    ? 'bcmath + pdo_pgsql loaded'
                    : '<span style="color:hsl(var(--destructive));">missing required extensions</span>' ?>
            </div>
        </div>
        <div class="kpi__icon">
            <?= $ic('<path d="M9 3v18M3 9h18M3 15h18M15 3v18"/>') ?>
        </div>
    </div>
</div>

<div style="display:grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap:24px;">
    <section class="card">
        <div class="card__header"><h2 class="card__title">Database</h2></div>
        <div class="card__body">
            <dl style="display:grid; grid-template-columns: 160px 1fr; gap:10px 16px;">
                <dt class="muted">Connection</dt>
                <dd>
                    <?php if ($db['ok']): ?>
                        <span class="badge badge--success">OK</span>
                    <?php else: ?>
                        <span class="badge badge--danger">FAIL</span>
                    <?php endif; ?>
                </dd>
                <dt class="muted">Version</dt>           <dd><?= e($db['version']) ?></dd>
                <dt class="muted">Public tables</dt>     <dd><?= e($db['tables']) ?></dd>
                <?php if ($db['err']): ?>
                    <dt class="muted">Error</dt>
                    <dd style="color:hsl(var(--destructive));"><?= e($db['err']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card__header"><h2 class="card__title">Audit HMAC chain</h2></div>
        <div class="card__body">
            <dl style="display:grid; grid-template-columns: 160px 1fr; gap:10px 16px;">
                <dt class="muted">Chained rows</dt> <dd class="tabular"><?= e($chain['count']) ?></dd>
                <dt class="muted">Integrity</dt>
                <dd>
                    <?php if ($chain['broken'] === null): ?>
                        <span class="badge badge--success">No breaks in last 1 000 rows</span>
                    <?php else: ?>
                        <span class="badge badge--danger">Break at row <?= e($chain['broken']) ?></span>
                    <?php endif; ?>
                </dd>
                <dt class="muted">Verifier CLI</dt>
                <dd><code>docker compose exec app php tools/audit-verify.php</code></dd>
            </dl>
        </div>
    </section>
</div>

<section class="card mt-5">
    <div class="card__header"><h2 class="card__title">Runtime</h2></div>
    <div class="card__body">
        <dl style="display:grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap:10px 24px;">
            <?php foreach ($config as $k => $v):
                $on = $v === 'on';
                $off = $v === 'off';
            ?>
                <div>
                    <div class="muted" style="font-size:11px; font-weight:600; letter-spacing:0.06em; text-transform:uppercase;">
                        <?= e($k) ?>
                    </div>
                    <div style="margin-top:4px;">
                        <?php if ($on): ?>
                            <span class="badge badge--success">on</span>
                        <?php elseif ($off): ?>
                            <span class="badge badge--danger">off</span>
                        <?php else: ?>
                            <code><?= e($v) ?></code>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </dl>
    </div>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'System',
    'body'  => $body,
    'active' => 'system',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
