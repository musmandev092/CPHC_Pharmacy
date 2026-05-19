<?php
declare(strict_types=1);

use CPHC\Audit;
use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);
$userId = (int) Session::userId();
$pageError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        $id    = (int) ($_POST['id'] ?? 0);
        $name  = trim((string) $_POST['name']);
        if ($name === '') throw new \InvalidArgumentException('Name is required.');
        if (mb_strlen($name) > 160) throw new \InvalidArgumentException('Name is too long (max 160 characters).');
        $phone = trim((string) ($_POST['contact_phone'] ?? '')) ?: null;
        $ntn   = trim((string) ($_POST['ntn'] ?? '')) ?: null;
        $addr  = trim((string) ($_POST['address'] ?? '')) ?: null;
        $book  = trim((string) ($_POST['booker_name'] ?? '')) ?: null;
        $sales = trim((string) ($_POST['salesman_name'] ?? '')) ?: null;
        $terms = trim((string) ($_POST['payment_terms'] ?? 'CASH'));
        $active = !empty($_POST['is_active']);

        // App-level uniqueness on name — the schema doesn't enforce it, but
        // duplicates corrupt the supplier-returns workflow (which one ships
        // back to?).  Same-name distinct branches must disambiguate by suffix.
        $dup = Db::fetchOne(
            'SELECT id FROM suppliers WHERE LOWER(name) = LOWER(:n) AND deleted_at IS NULL'
            . ($id > 0 ? ' AND id <> :id' : ''),
            $id > 0 ? [':n' => $name, ':id' => $id] : [':n' => $name],
        );
        if ($dup !== null) {
            throw new \InvalidArgumentException("Supplier name '{$name}' already exists. Add a suffix to distinguish (e.g. '— branch 2').");
        }

        if ($id > 0) {
            Db::execute(
                "UPDATE suppliers SET name=:n, contact_phone=:p, ntn=:nt, address=:a,
                       booker_name=:b, salesman_name=:s, payment_terms=:t, is_active=:act,
                       updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id",
                [':n'=>$name,':p'=>$phone,':nt'=>$ntn,':a'=>$addr,':b'=>$book,':s'=>$sales,':t'=>$terms,':act'=>$active?'true':'false',':id'=>$id]);
            Audit::write($userId, 'SUPPLIER_UPDATED', 'suppliers', $id, null, ['name' => $name]);
        } else {
            $row = Db::fetchOne(
                "INSERT INTO suppliers (name, contact_phone, ntn, address, booker_name, salesman_name, payment_terms, is_active)
                  VALUES (:n,:p,:nt,:a,:b,:s,:t,:act) RETURNING id",
                [':n'=>$name,':p'=>$phone,':nt'=>$ntn,':a'=>$addr,':b'=>$book,':s'=>$sales,':t'=>$terms,':act'=>$active?'true':'false']);
            Audit::write($userId, 'SUPPLIER_CREATED', 'suppliers', (int) $row['id'], null, ['name' => $name]);
        }
        Session::flash('success', $id > 0 ? 'Supplier updated.' : 'Supplier added.');
        Router::redirect('/suppliers');
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? Db::fetchOne('SELECT * FROM suppliers WHERE id = :id', [':id' => $editId]) : null;
$rows = Db::fetchAll('SELECT id, name, contact_phone, ntn, payment_terms, is_active FROM suppliers ORDER BY name LIMIT 200');

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Suppliers</h1>
        <p class="page-header__sub">Vendor master — name, contact, NTN, payment terms.  Referenced by every GRN.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/inventory">← Inventory</a>
        <a class="btn" href="/suppliers?edit=new">＋ Add supplier</a>
    </div>
</div>

<?php if ($pageError): ?><div class="flash error mb-4"><?= e($pageError) ?></div><?php endif; ?>

<?php if ($editing !== null || ($_GET['edit'] ?? '') === 'new'): ?>
<details open style="background:white; border:1px solid #e6e8ec; border-radius:8px; padding:1em 1.4em; margin-bottom:1.5em;">
    <summary><strong><?= $editing ? 'Edit supplier' : 'New supplier' ?></strong></summary>
    <form method="post" action="/suppliers">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5em 1em;">
            <label><span>Name</span><input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>"></label>
            <label><span>Phone</span><input type="text" name="contact_phone" value="<?= e($editing['contact_phone'] ?? '') ?>"></label>
            <label><span>NTN</span><input type="text" name="ntn" value="<?= e($editing['ntn'] ?? '') ?>"></label>
            <label><span>Payment terms</span><input type="text" name="payment_terms" value="<?= e($editing['payment_terms'] ?? 'CASH') ?>"></label>
            <label><span>Booker name</span><input type="text" name="booker_name" value="<?= e($editing['booker_name'] ?? '') ?>"></label>
            <label><span>Salesman name</span><input type="text" name="salesman_name" value="<?= e($editing['salesman_name'] ?? '') ?>"></label>
            <label style="grid-column:1/-1;"><span>Address</span><textarea name="address" rows="2"><?= e($editing['address'] ?? '') ?></textarea></label>
            <label><input type="checkbox" name="is_active" <?= !$editing || !empty($editing['is_active']) ? 'checked' : '' ?>> Active</label>
        </div>
        <div style="margin-top:1em;">
            <button type="submit"><?= $editing ? 'Save' : 'Add' ?></button>
            <a class="btn secondary" href="/suppliers">Cancel</a>
        </div>
    </form>
</details>
<?php endif; ?>

<table>
    <thead><tr><th>Name</th><th>Phone</th><th>NTN</th><th>Terms</th><th>Active</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $s): ?>
        <tr>
            <td><strong><?= e($s['name']) ?></strong></td>
            <td><?= e($s['contact_phone'] ?? '—') ?></td>
            <td><?= e($s['ntn'] ?? '—') ?></td>
            <td><?= e($s['payment_terms']) ?></td>
            <td><?= $s['is_active'] ? 'Yes' : 'No' ?></td>
            <td><a href="/suppliers?edit=<?= e($s['id']) ?>">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'=>'Suppliers', 'body'=>$body,
    'flash_success'=>Session::flash('success'), 'flash_error'=>Session::flash('error'),
    'nonce'=>Csp::nonce(),
]);
