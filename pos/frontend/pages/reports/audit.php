<?php
declare(strict_types=1);

/**
 * Audit-log viewer.  ADMIN only.  Paginated 200/page, descending by id.
 * Filter by action_type, entity_type.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Csv;

Auth::requireRole(Auth::ROLE_ADMIN);

$page     = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 200;
$action   = trim((string) ($_GET['action'] ?? ''));
$entity   = trim((string) ($_GET['entity'] ?? ''));

$where  = ['1=1'];
$params = [];
if ($action !== '') { $where[] = 'action_type ILIKE :a'; $params[':a'] = '%' . $action . '%'; }
if ($entity !== '') { $where[] = 'entity_type ILIKE :e'; $params[':e'] = '%' . $entity . '%'; }
$whereSql = implode(' AND ', $where);

$rows = Db::fetchAll(
    "SELECT a.id, a.timestamp::text AS ts, a.user_id, a.action_type, a.entity_type, a.entity_id,
            a.reason, a.ip_address, a.user_agent,
            substring(a.before_value::text, 1, 200) AS before_short,
            substring(a.after_value::text, 1, 200) AS after_short,
            length(a.row_hmac) = 64 AS hmac_ok,
            u.full_name AS user_name
       FROM audit_log a
  LEFT JOIN users u ON u.id = a.user_id
      WHERE $whereSql
      ORDER BY a.id DESC
      LIMIT $pageSize OFFSET " . ($page - 1) * $pageSize,
    $params,
);

if (($_GET['export'] ?? '') === 'csv') {
    Csv::stream('audit_log',
        ['Id','When','User','Action','Entity','Entity id','IP','HMAC ok','Before','After'],
        array_map(fn ($r) => [$r['id'], substr((string) $r['ts'], 0, 19), $r['user_name'] ?? '',
            $r['action_type'], $r['entity_type'], $r['entity_id'],
            $r['ip_address'], $r['hmac_ok'] ? '1' : '0',
            $r['before_short'], $r['after_short']], $rows));
    return;
}

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Audit log</h1>
        <p class="page-header__sub">Every write recorded with an HMAC-chained signature — tamper-evident.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/reports">← Reports</a>
        <a class="btn" href="/reports/audit?action=<?= e($action) ?>&entity=<?= e($entity) ?>&export=csv">
            <?= $ic('<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>') ?>
            Download CSV
        </a>
    </div>
</div>

<section class="card mb-4">
    <form method="get" action="/reports/audit" class="card__body" style="padding:18px 22px;">
        <div style="display:flex; flex-wrap:wrap; gap:12px 20px; align-items:end;">
            <label style="margin:0; flex:1; min-width:200px;"><span>Action</span>
                <input type="text" name="action" value="<?= e($action) ?>" placeholder="e.g. SALE_COMPLETED">
            </label>
            <label style="margin:0; flex:1; min-width:200px;"><span>Entity</span>
                <input type="text" name="entity" value="<?= e($entity) ?>" placeholder="e.g. sales">
            </label>
            <button type="submit">Filter</button>
        </div>
    </form>
</section>

<section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead><tr>
            <th>Id</th><th>When</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>HMAC</th>
        </tr></thead>
        <tbody>
        <?php if (count($rows) === 0): ?>
            <tr><td colspan="7" style="padding:48px; text-align:center;" class="muted">No matching audit rows.</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr>
                <td class="tabular">#<?= e($r['id']) ?></td>
                <td class="muted nowrap"><?= e(substr((string) $r['ts'], 0, 19)) ?></td>
                <td><?php if (!empty($r['user_name'])): ?><?= e($r['user_name']) ?><?php else: ?><span class="muted">(system)</span><?php endif; ?></td>
                <td><code style="font-size:11.5px;"><?= e($r['action_type']) ?></code></td>
                <td><?= e($r['entity_type']) ?><?php if ($r['entity_id']): ?> <code style="font-size:11px;">#<?= e($r['entity_id']) ?></code><?php endif; ?></td>
                <td class="muted"><?= e($r['ip_address'] ?? '—') ?></td>
                <td><span class="badge badge--<?= $r['hmac_ok'] ? 'success' : 'danger' ?>"><?= $r['hmac_ok'] ? '✓ OK' : '⚠ FAIL' ?></span></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

<div class="mt-4 flex gap-2" style="justify-content:center;">
    <?php if ($page > 1): ?>
        <a class="btn-secondary" href="/reports/audit?page=<?= e($page-1) ?>&action=<?= e($action) ?>&entity=<?= e($entity) ?>">← Previous</a>
    <?php endif; ?>
    <span class="muted" style="align-self:center;">Page <?= e($page) ?></span>
    <?php if (count($rows) === $pageSize): ?>
        <a class="btn-secondary" href="/reports/audit?page=<?= e($page+1) ?>&action=<?= e($action) ?>&entity=<?= e($entity) ?>">Next →</a>
    <?php endif; ?>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Audit log', 'body' => $body, 'active' => 'reports',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
