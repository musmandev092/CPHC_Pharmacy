<?php
declare(strict_types=1);

/**
 * /admin/users — ADMIN-only user CRUD.
 *
 *   Create:  full_name + username + PIN + role (CASHIER/MANAGER/ADMIN) + active
 *   Edit:    PIN optional (leave blank to keep current)
 *   Reset:   sets must_rotate_pin=TRUE → user gates to /account/change-pin
 *            on next login
 *   Toggle:  flip is_active
 *
 * PIN policy + history + bcrypt-cost-12 hashing all routed through PinPolicy.
 */

use CPHC\Audit;
use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\PinPolicy;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;

Auth::requireRole(Auth::ROLE_ADMIN);
$adminId = (int) Session::userId();
$pageError = null;
$ROLES = [Auth::ROLE_CASHIER, Auth::ROLE_MANAGER, Auth::ROLE_ADMIN];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $id        = (int) ($_POST['id'] ?? 0);
            $fullName  = trim((string) $_POST['full_name']);
            $username  = trim((string) $_POST['username']);
            $pin       = (string) ($_POST['pin'] ?? '');
            $role      = (string) $_POST['role'];
            $isActive  = !empty($_POST['is_active']);

            if ($fullName === '' || $username === '') throw new \InvalidArgumentException('Name and username required.');
            if (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) throw new \InvalidArgumentException('Username may only contain letters, digits, . _ -');
            if (!in_array($role, $ROLES, true)) throw new \InvalidArgumentException('Invalid role.');

            if ($id === 0) {
                if ($pin === '') throw new \InvalidArgumentException('PIN required for a new user.');
                $err = PinPolicy::validate($pin);
                if ($err !== null) throw new \InvalidArgumentException($err);

                $hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
                $row = Db::fetchOne(
                    "INSERT INTO users (full_name, username, pin_hash, role, is_active, must_rotate_pin)
                       VALUES (:n, :u, :h, :r::\"UserRole\", :a, FALSE) RETURNING id",
                    [':n' => $fullName, ':u' => $username, ':h' => $hash, ':r' => $role, ':a' => $isActive ? 'true' : 'false'],
                );
                $newId = (int) $row['id'];
                Db::execute(
                    'INSERT INTO pin_history (user_id, pin_hash, changed_at) VALUES (:u, :h, CURRENT_TIMESTAMP)',
                    [':u' => $newId, ':h' => $hash],
                );
                Audit::write($adminId, 'USER_CREATED', 'users', $newId, null,
                    ['username' => $username, 'role' => $role]);
                Session::flash('success', "User '{$username}' created.");
            } else {
                $cur = Db::fetchOne('SELECT id, pin_hash, role::text AS role, username, is_active FROM users WHERE id = :id', [':id' => $id]);
                if ($cur === null) throw new \RuntimeException('User not found.');

                // Lockout safety: refuse to demote/deactivate the last ADMIN.
                if ($cur['role'] === 'ADMIN' && ($role !== 'ADMIN' || !$isActive)) {
                    $otherAdmins = (int) Db::fetchOne(
                        "SELECT count(*) AS c FROM users
                          WHERE role='ADMIN' AND is_active=TRUE AND deleted_at IS NULL AND id <> :id",
                        [':id' => $id],
                    )['c'];
                    if ($otherAdmins === 0) {
                        throw new \RuntimeException('Cannot demote or deactivate the last active ADMIN. Promote another user first.');
                    }
                }

                Db::execute(
                    "UPDATE users SET full_name=:n, username=:u, role=:r::\"UserRole\", is_active=:a,
                                      updated_at=CURRENT_TIMESTAMP
                       WHERE id=:id",
                    [':n' => $fullName, ':u' => $username, ':r' => $role, ':a' => $isActive ? 'true' : 'false', ':id' => $id],
                );

                if ($pin !== '') {
                    $err = PinPolicy::validate($pin, $id, (string) $cur['pin_hash']);
                    if ($err !== null) throw new \InvalidArgumentException($err);
                    Db::transaction(function () use ($id, $pin) {
                        $hash = PinPolicy::recordChange($id, $pin);
                        Db::execute(
                            "UPDATE users SET pin_hash=:h, pin_changed_at=CURRENT_TIMESTAMP, must_rotate_pin=FALSE,
                                              updated_at=CURRENT_TIMESTAMP
                               WHERE id=:id",
                            [':h' => $hash, ':id' => $id],
                        );
                    });
                    Audit::write($adminId, 'USER_PIN_RESET', 'users', $id, null, ['username' => $username]);
                }
                Audit::write($adminId, 'USER_UPDATED', 'users', $id, $cur, ['full_name' => $fullName, 'role' => $role]);
                Session::flash('success', "User '{$username}' updated.");
            }
            Router::redirect('/admin/users');
        } elseif ($action === 'force_rotate') {
            $id = (int) $_POST['id'];
            Db::execute('UPDATE users SET must_rotate_pin = TRUE WHERE id = :id', [':id' => $id]);
            Audit::write($adminId, 'USER_FORCE_ROTATE', 'users', $id);
            Session::flash('success', 'User will be forced to rotate PIN at next login.');
            Router::redirect('/admin/users');
        } elseif ($action === 'toggle_active') {
            $id = (int) $_POST['id'];
            // Refuse to deactivate the last remaining active ADMIN, or to
            // deactivate yourself when you're the last ADMIN.  Lockout safety.
            $target = Db::fetchOne(
                'SELECT id, role::text AS role, is_active FROM users WHERE id = :id',
                [':id' => $id],
            );
            if ($target === null) throw new \RuntimeException('User not found.');
            if ($target['is_active'] && $target['role'] === 'ADMIN') {
                $otherAdmins = (int) Db::fetchOne(
                    "SELECT count(*) AS c FROM users
                      WHERE role='ADMIN' AND is_active=TRUE AND deleted_at IS NULL AND id <> :id",
                    [':id' => $id],
                )['c'];
                if ($otherAdmins === 0) {
                    throw new \RuntimeException('Cannot deactivate the last active ADMIN — promote another user first.');
                }
            }
            Db::execute('UPDATE users SET is_active = NOT is_active WHERE id = :id', [':id' => $id]);
            Audit::write($adminId, 'USER_TOGGLE_ACTIVE', 'users', $id);
            Router::redirect('/admin/users');
        }
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

$q  = trim((string) ($_GET['q'] ?? ''));
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? Db::fetchOne('SELECT * FROM users WHERE id = :id', [':id' => $editId]) : null;
$showForm = $editing !== null || ($_GET['edit'] ?? '') === 'new';

$users = Db::fetchAll(
    "SELECT id, full_name, username, role::text AS role, is_active, must_rotate_pin,
            last_login_at::text AS last_login_at, pin_changed_at::text AS pin_changed_at
       FROM users WHERE deleted_at IS NULL
        AND (:q = '' OR LOWER(full_name) LIKE :like OR username ILIKE :like)
      ORDER BY full_name LIMIT 200",
    [':q' => $q, ':like' => '%' . strtolower($q) . '%'],
);

$total  = count($users);
$counts = ['ADMIN' => 0, 'MANAGER' => 0, 'CASHIER' => 0];
foreach ($users as $u) {
    if (isset($counts[$u['role']])) $counts[$u['role']]++;
}

$ic = static fn (string $d, int $size = 14) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Users</h1>
        <p class="page-header__sub">
            Manage cashiers, managers, and admins.  Role gates affect everything they can do at the counter.
        </p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/admin/users">All</a>
        <a class="btn" href="/admin/users?edit=new"><?= $ic('<path d="M12 5v14M5 12h14"/>') ?> Add user</a>
    </div>
</div>

<?php if ($pageError): ?>
    <div class="flash error mb-4"><?= $ic('<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>', 18) ?><div><?= e($pageError) ?></div></div>
<?php endif; ?>

<!-- KPI strip -->
<div class="card-grid mb-4">
    <div class="kpi">
        <div>
            <div class="kpi__label">Active users</div>
            <div class="kpi__value tabular"><?= e($total) ?></div>
            <div class="kpi__delta">in this view</div>
        </div>
        <div class="kpi__icon"><?= $ic('<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8z"/>', 20) ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Admins</div>
            <div class="kpi__value tabular"><?= e($counts['ADMIN']) ?></div>
            <div class="kpi__delta">full access</div>
        </div>
        <div class="kpi__icon kpi__icon--info"><?= $ic('<path d="M12 2L4 6v6c0 5 3.4 9.4 8 10 4.6-.6 8-5 8-10V6l-8-4z"/>', 20) ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Managers</div>
            <div class="kpi__value tabular"><?= e($counts['MANAGER']) ?></div>
            <div class="kpi__delta">approve discounts &amp; returns</div>
        </div>
        <div class="kpi__icon kpi__icon--success"><?= $ic('<path d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>', 20) ?></div>
    </div>
    <div class="kpi">
        <div>
            <div class="kpi__label">Cashiers</div>
            <div class="kpi__value tabular"><?= e($counts['CASHIER']) ?></div>
            <div class="kpi__delta">POS counter only</div>
        </div>
        <div class="kpi__icon kpi__icon--warning"><?= $ic('<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>', 20) ?></div>
    </div>
</div>

<!-- Filter -->
<section class="card mb-4">
    <form method="get" action="/admin/users" class="card__body" style="padding:18px 22px;">
        <label style="margin:0;">
            <span>Search</span>
            <input type="search" name="q" placeholder="Name or username…" value="<?= e($q) ?>" style="max-width:32em;">
        </label>
    </form>
</section>

<?php if ($showForm): ?>
    <section class="card mb-4">
        <div class="card__header">
            <h2 class="card__title font-display" style="margin:0;">
                <?= $editing ? 'Edit user' : 'New user' ?>
            </h2>
            <a class="btn-link" href="/admin/users">Cancel</a>
        </div>
        <form method="post" action="/admin/users" class="card__body">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">

            <div class="form-grid">
                <label><span>Full name</span>
                    <input type="text" name="full_name" required maxlength="120" autofocus
                           value="<?= e($editing['full_name'] ?? '') ?>" placeholder="e.g. Alice Khan">
                </label>
                <label><span>Username</span>
                    <input type="text" name="username" required maxlength="60" pattern="[A-Za-z0-9._\-]+"
                           value="<?= e($editing['username'] ?? '') ?>" placeholder="e.g. alice">
                </label>
                <label><span>Role</span>
                    <select name="role" required>
                        <?php foreach ($ROLES as $r): ?>
                            <option value="<?= e($r) ?>" <?= ($editing['role'] ?? 'CASHIER') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>PIN <?= $editing ? '(blank = keep current)' : '(6–8 digits)' ?></span>
                    <input type="password" name="pin" inputmode="numeric"
                           pattern="\d{6,8}" maxlength="8" autocomplete="off"
                           placeholder="• • • • • •">
                </label>
                <label class="col-2" style="display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" name="is_active" <?= !$editing || !empty($editing['is_active']) ? 'checked' : '' ?>>
                    <span style="margin:0;">Active</span>
                </label>
            </div>
            <div class="mt-4 flex gap-2">
                <button type="submit"><?= $editing ? 'Save changes' : 'Create user' ?></button>
                <a class="btn-secondary" href="/admin/users">Cancel</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<!-- Table -->
<section class="card" style="padding:0; overflow:hidden;">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Status</th>
                <th>Last login</th>
                <th>PIN age</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if ($total === 0): ?>
            <tr><td colspan="7" style="padding:48px; text-align:center;" class="muted">No users match.</td></tr>
        <?php else: foreach ($users as $u):
            $age = $u['pin_changed_at'] ? (int) (new \DateTimeImmutable('now'))->diff(new \DateTimeImmutable((string) $u['pin_changed_at']))->days : null;
            $roleCls = match ($u['role']) {
                'ADMIN'   => 'info',
                'MANAGER' => 'success',
                'CASHIER' => 'warning',
                default   => 'neutral',
            };
        ?>
            <tr>
                <td><strong><?= e($u['full_name']) ?></strong></td>
                <td><code style="font-size:12px;"><?= e($u['username']) ?></code></td>
                <td><span class="badge badge--<?= $roleCls ?>"><?= e($u['role']) ?></span></td>
                <td>
                    <?php if ($u['is_active']): ?>
                        <span class="badge badge--success">Active</span>
                    <?php else: ?>
                        <span class="badge badge--danger">Inactive</span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= e(substr((string) ($u['last_login_at'] ?? ''), 0, 19) ?: '—') ?></td>
                <td>
                    <?php if ($age !== null): ?>
                        <span class="tabular"><?= e($age) ?>d</span>
                        <?php if ($u['must_rotate_pin']): ?>
                            <br><span class="badge badge--warning" style="margin-top:4px;">rotate next</span>
                        <?php endif; ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td class="row-actions">
                    <a class="btn-link" href="/admin/users?edit=<?= e($u['id']) ?>">Edit</a>
                    <form method="post" action="/admin/users" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                        <input type="hidden" name="action" value="force_rotate">
                        <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                        <button type="submit" class="btn-link">Force rotate</button>
                    </form>
                    <form method="post" action="/admin/users" class="inline-form">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="id" value="<?= e($u['id']) ?>">
                        <button type="submit" class="btn-link" style="color:hsl(var(--destructive));">
                            <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Users',
    'body'  => $body,
    'active' => 'users',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
