<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Grn;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);
$userId = (int) Session::userId();
$pageError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'new_draft') {
            $supplier = (int) ($_POST['supplier_id'] ?? 0);
            if ($supplier <= 0) throw new \InvalidArgumentException('Pick a supplier.');
            $id = Grn::createDraft($userId, $supplier);
            Router::redirect('/inventory/grn/' . $id);
        } elseif ($action === 'cancel') {
            Grn::cancelDraft((int) $_POST['grn_id'], $userId);
            Session::flash('success', 'GRN cancelled.');
            Router::redirect('/inventory/grn');
        }
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

$suppliers = Db::fetchAll("SELECT id, name FROM suppliers WHERE is_active = TRUE ORDER BY name");
$rows = Db::fetchAll(
    "SELECT g.id, g.grn_number, g.status::text AS status,
            g.created_at::text AS created_at, g.posted_at::text AS posted_at,
            g.invoice_number, g.grand_total::text AS grand_total,
            s.name AS supplier_name
       FROM grn_documents g
       JOIN suppliers s ON s.id = g.supplier_id
      ORDER BY g.created_at DESC LIMIT 100",
);

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Goods-received notes</h1>
        <p class="page-header__sub">Every GRN with status, supplier, invoice #, and total.  Drafts can be cancelled; posted GRNs are immutable.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/inventory">← Inventory</a>
        <a class="btn-secondary" href="/suppliers">Manage suppliers</a>
    </div>
</div>

<?php if ($pageError): ?><div class="flash error mb-4"><?= e($pageError) ?></div><?php endif; ?>

<details style="background:white; border:1px solid #e6e8ec; border-radius:8px; padding:1em 1.4em; margin-bottom:1.5em;" <?= count($rows) === 0 ? 'open' : '' ?>>
    <summary><strong>＋ New GRN draft</strong></summary>
    <form method="post" action="/inventory/grn" style="margin-top:0.5em;">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="new_draft">
        <label><span>Supplier</span>
            <select name="supplier_id" required>
                <option value="">— pick —</option>
                <?php foreach ($suppliers as $s): ?>
                    <option value="<?= e($s['id']) ?>"><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Create draft</button>
        <a class="btn secondary" href="/suppliers">+ Manage suppliers</a>
    </form>
</details>

<table>
    <thead><tr><th>GRN #</th><th>Supplier</th><th>Invoice #</th><th>Created</th><th>Status</th><th>Total</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $g): ?>
        <tr>
            <td><strong><?= e($g['grn_number']) ?></strong></td>
            <td><?= e($g['supplier_name']) ?></td>
            <td><?= e($g['invoice_number'] ?? '—') ?></td>
            <td><?= e(substr((string) $g['created_at'], 0, 19)) ?></td>
            <td>
                <span style="color:<?= $g['status'] === 'POSTED' ? '#1e6a32' : ($g['status'] === 'CANCELLED' ? '#c0392b' : '#b88a00') ?>;">
                    <?= e($g['status']) ?>
                </span>
            </td>
            <td>PKR <?= e($g['grand_total']) ?></td>
            <td>
                <a href="/inventory/grn/<?= e($g['id']) ?>"><?= $g['status'] === 'DRAFT' ? 'Edit' : 'View' ?></a>
                <?php if ($g['status'] === 'DRAFT'): ?>
                    <form method="post" action="/inventory/grn" style="display:inline;">
                        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="grn_id" value="<?= e($g['id']) ?>">
                        <button type="submit" class="link" style="color:#c0392b;">Cancel</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'=>'GRN list', 'body'=>$body,
    'flash_success'=>Session::flash('success'), 'flash_error'=>Session::flash('error'),
    'nonce'=>Csp::nonce(),
]);
