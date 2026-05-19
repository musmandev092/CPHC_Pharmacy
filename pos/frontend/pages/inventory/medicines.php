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

// Enum values straight from the schema; presented as <option>s in the form.
$FORMS = ['TABLET','CAPSULE','SOFT_GEL_CAPSULE','SYRUP','SUSPENSION',
    'INJECTION','INJECTION_AMPOULE','INJECTION_VIAL',
    'CREAM','OINTMENT','GEL','DROPS','INHALER','PATCH','SUPPOSITORY',
    'SACHET','POWDER','IV_FLUID','OTHER'];
$SCHEDULES = ['NONE','SCHEDULE_G','SCHEDULE_H','NARCOTIC'];
$TAXES     = ['EXEMPT','STANDARD_18','REDUCED','ZERO_RATED'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        $id = (int) ($_POST['id'] ?? 0);
        $sku  = trim((string) $_POST['sku']);
        $bar  = trim((string) ($_POST['primary_barcode'] ?? '')) ?: null;
        $brand = trim((string) $_POST['brand_name']);
        $generic = trim((string) $_POST['generic_name']);
        $strength = trim((string) ($_POST['strength'] ?? '')) ?: null;
        $form = (string) $_POST['form'];
        $purchaseUnit = trim((string) $_POST['purchase_unit']);
        $baseUnit     = trim((string) $_POST['base_unit']);
        $uppRaw       = (int) $_POST['units_per_purchase'];
        $reorderRaw   = (int) ($_POST['reorder_level'] ?? 0);
        $reorderQRaw  = (int) ($_POST['reorder_quantity'] ?? 0);
        $schedule = (string) $_POST['controlled_schedule'];
        $taxCode  = (string) $_POST['tax_code_value'];
        $rxReq    = !empty($_POST['prescription_required']);
        $active   = !empty($_POST['is_active']);

        if ($brand === '' || $generic === '' || $sku === '') {
            throw new \InvalidArgumentException('SKU, brand, and generic name are required.');
        }
        if (mb_strlen($brand)   > 160) throw new \InvalidArgumentException('Brand name is too long (max 160 characters).');
        if (mb_strlen($generic) > 160) throw new \InvalidArgumentException('Generic name is too long (max 160 characters).');
        if (mb_strlen($sku)     > 40)  throw new \InvalidArgumentException('SKU is too long (max 40 characters).');
        if ($purchaseUnit === '' || $baseUnit === '') {
            throw new \InvalidArgumentException('Purchase unit and base unit are required.');
        }
        if ($uppRaw < 1) {
            throw new \InvalidArgumentException('Units per purchase must be at least 1.');
        }
        if ($reorderRaw  < 0) throw new \InvalidArgumentException('Reorder level cannot be negative.');
        if ($reorderQRaw < 0) throw new \InvalidArgumentException('Reorder quantity cannot be negative.');
        if (!in_array($form, $FORMS, true))       throw new \InvalidArgumentException('Invalid form');
        if (!in_array($schedule, $SCHEDULES, true)) throw new \InvalidArgumentException('Invalid schedule');
        if (!in_array($taxCode, $TAXES, true))     throw new \InvalidArgumentException('Invalid tax code');

        // App-level barcode uniqueness check.  DB has a partial-unique index as
        // last-line defence; we check first so the user sees a useful message.
        if ($bar !== null && $bar !== '') {
            $dup = Db::fetchOne(
                'SELECT id, brand_name FROM medicines
                  WHERE primary_barcode = :b AND deleted_at IS NULL'
                  . ($id > 0 ? ' AND id <> :id' : ''),
                $id > 0 ? [':b' => $bar, ':id' => $id] : [':b' => $bar],
            );
            if ($dup !== null) {
                throw new \InvalidArgumentException(
                    "Barcode '{$bar}' is already used by '{$dup['brand_name']}'."
                );
            }
        }

        $upp      = $uppRaw;
        $reorder  = $reorderRaw;
        $reorderQ = $reorderQRaw;

        if ($id > 0) {
            $before = Db::fetchOne('SELECT * FROM medicines WHERE id = :id', [':id' => $id]);
            Db::execute(
                "UPDATE medicines
                    SET sku = :sku, primary_barcode = :bar, brand_name = :brand, generic_name = :generic,
                        strength = :str, form = :form::\"MedicineForm\",
                        purchase_unit = :pu, base_unit = :bu, units_per_purchase = :upp,
                        controlled_schedule = :sched::\"ControlledSchedule\",
                        tax_code_value = :tax::\"TaxCode\",
                        reorder_level = :rl, reorder_quantity = :rq,
                        prescription_required = :rx, is_active = :act,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id",
                [':sku'=>$sku, ':bar'=>$bar, ':brand'=>$brand, ':generic'=>$generic, ':str'=>$strength,
                 ':form'=>$form, ':pu'=>$purchaseUnit, ':bu'=>$baseUnit, ':upp'=>$upp,
                 ':sched'=>$schedule, ':tax'=>$taxCode, ':rl'=>$reorder, ':rq'=>$reorderQ,
                 ':rx'=>$rxReq?'true':'false', ':act'=>$active?'true':'false', ':id'=>$id],
            );
            Audit::write($userId, 'MEDICINE_UPDATED', 'medicines', $id, $before, ['sku' => $sku, 'brand_name' => $brand]);
        } else {
            $row = Db::fetchOne(
                "INSERT INTO medicines
                    (sku, primary_barcode, brand_name, generic_name, strength, form,
                     purchase_unit, base_unit, units_per_purchase,
                     controlled_schedule, tax_code_value, reorder_level, reorder_quantity,
                     prescription_required, is_active)
                  VALUES (
                    :sku, :bar, :brand, :generic, :str, :form::\"MedicineForm\",
                    :pu, :bu, :upp,
                    :sched::\"ControlledSchedule\", :tax::\"TaxCode\", :rl, :rq,
                    :rx, :act
                  ) RETURNING id",
                [':sku'=>$sku, ':bar'=>$bar, ':brand'=>$brand, ':generic'=>$generic, ':str'=>$strength,
                 ':form'=>$form, ':pu'=>$purchaseUnit, ':bu'=>$baseUnit, ':upp'=>$upp,
                 ':sched'=>$schedule, ':tax'=>$taxCode, ':rl'=>$reorder, ':rq'=>$reorderQ,
                 ':rx'=>$rxReq?'true':'false', ':act'=>$active?'true':'false'],
            );
            Audit::write($userId, 'MEDICINE_CREATED', 'medicines', (int) $row['id'], null, ['sku' => $sku, 'brand_name' => $brand]);
        }
        Session::flash('success', $id > 0 ? 'Medicine updated.' : 'Medicine added.');
        Router::redirect('/inventory/medicines');
    } catch (\Throwable $e) {
        $pageError = $e->getMessage();
    }
}

$q  = trim((string) ($_GET['q'] ?? ''));
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? Db::fetchOne('SELECT * FROM medicines WHERE id = :id', [':id' => $editId]) : null;

$rows = Db::fetchAll(
    "SELECT m.id, m.sku, m.brand_name, m.generic_name, m.strength, m.form::text AS form,
            m.controlled_schedule::text AS controlled_schedule, m.is_active,
            m.base_unit, m.purchase_unit, m.units_per_purchase,
            COALESCE((SELECT SUM(current_qty) FROM batches WHERE medicine_id=m.id AND current_qty>0
                       AND is_quarantined=FALSE AND is_expired=FALSE), 0) AS on_hand
       FROM medicines m
      WHERE m.deleted_at IS NULL
        AND (:q = '' OR LOWER(m.brand_name) LIKE :like OR LOWER(m.generic_name) LIKE :like OR m.sku ILIKE :like)
      ORDER BY m.brand_name LIMIT 100",
    [':q' => $q, ':like' => '%' . strtolower($q) . '%'],
);

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Medicines</h1>
        <p class="page-header__sub">Catalog of every medicine you sell — SKU, brand, generic, units, controlled-schedule, reorder rules.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/inventory">← Inventory</a>
        <a class="btn" href="/inventory/medicines?edit=new">＋ Add medicine</a>
    </div>
</div>

<?php if ($pageError !== null): ?><div class="flash error mb-4"><?= e($pageError) ?></div><?php endif; ?>

<section class="card mb-4">
    <form method="get" action="/inventory/medicines" class="card__body" style="padding:18px 22px;">
        <label style="margin:0;">
            <span>Search</span>
            <input type="search" name="q" placeholder="Brand, generic, or SKU…" value="<?= e($q) ?>" style="max-width:32em;">
        </label>
    </form>
</section>

<?php if ($editing !== null || ($_GET['edit'] ?? '') === 'new'): ?>
    <details open style="background:white; border:1px solid #e6e8ec; border-radius:8px; padding:1em 1.4em; margin-bottom:1.5em;">
        <summary><strong><?= $editing ? 'Edit medicine' : 'New medicine' ?></strong></summary>
        <form method="post" action="/inventory/medicines">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= e($editing['id'] ?? 0) ?>">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.6em 1em;">
                <label><span>SKU</span><input type="text" name="sku" required maxlength="40" value="<?= e($editing['sku'] ?? '') ?>"></label>
                <label><span>Primary barcode</span><input type="text" name="primary_barcode" maxlength="60" value="<?= e($editing['primary_barcode'] ?? '') ?>"></label>
                <label><span>Brand name</span><input type="text" name="brand_name" required maxlength="160" value="<?= e($editing['brand_name'] ?? '') ?>"></label>
                <label><span>Generic name</span><input type="text" name="generic_name" required maxlength="160" value="<?= e($editing['generic_name'] ?? '') ?>"></label>
                <label><span>Strength</span><input type="text" name="strength" maxlength="60" value="<?= e($editing['strength'] ?? '') ?>"></label>
                <label><span>Form</span><select name="form"><?php foreach ($FORMS as $f): ?><option value="<?= e($f) ?>" <?= ($editing['form'] ?? 'TABLET') === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?></select></label>
                <?php
                    // Common pharmacy units in Pakistan.  Free-text input
                    // still works (datalist allows arbitrary value) but the
                    // suggestions guide cashiers to consistent labels.
                    $BASE_UNITS = ['TABLET','CAPSULE','AMPOULE','VIAL','BOTTLE','INHALER','SACHET','PATCH','SUPPOSITORY','TUBE','DEVICE','PEN','ML','GRAM','DROP'];
                    $PACK_UNITS = ['BOX','STRIP','BLISTER','CARTON','BOTTLE','TUBE','INHALER','PACK','VIAL','AMPOULE','UNIT'];
                ?>
                <label><span>Purchase unit (carton / box / strip)</span>
                    <input type="text" name="purchase_unit" required maxlength="40" list="purchase-unit-list" value="<?= e($editing['purchase_unit'] ?? 'BOX') ?>">
                    <datalist id="purchase-unit-list"><?php foreach ($PACK_UNITS as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
                </label>
                <label><span>Base unit (single dispense unit)</span>
                    <input type="text" name="base_unit" required maxlength="40" list="base-unit-list" value="<?= e($editing['base_unit'] ?? 'TABLET') ?>">
                    <datalist id="base-unit-list"><?php foreach ($BASE_UNITS as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?></datalist>
                </label>
                <label><span>Base units per purchase unit <span class="muted" style="font-weight:400;">(10 if 10 tablets per strip; 1 for bottle/inhaler)</span></span>
                    <input type="number" name="units_per_purchase" required min="1" value="<?= e($editing['units_per_purchase'] ?? 1) ?>">
                </label>
                <label><span>Controlled schedule</span><select name="controlled_schedule"><?php foreach ($SCHEDULES as $s): ?><option value="<?= e($s) ?>" <?= ($editing['controlled_schedule'] ?? 'NONE') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></label>
                <label><span>Tax code</span><select name="tax_code_value"><?php foreach ($TAXES as $t): ?><option value="<?= e($t) ?>" <?= ($editing['tax_code_value'] ?? 'EXEMPT') === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
                <label><span>Reorder level (base units)</span><input type="number" name="reorder_level" min="0" value="<?= e($editing['reorder_level'] ?? 0) ?>"></label>
                <label><span>Reorder quantity</span><input type="number" name="reorder_quantity" min="0" value="<?= e($editing['reorder_quantity'] ?? 0) ?>"></label>
                <label><input type="checkbox" name="prescription_required" <?= !empty($editing['prescription_required']) ? 'checked' : '' ?>> Prescription required</label>
                <label><input type="checkbox" name="is_active" <?= !$editing || !empty($editing['is_active']) ? 'checked' : '' ?>> Active</label>
            </div>
            <div style="margin-top:1em;">
                <button type="submit"><?= $editing ? 'Save changes' : 'Add medicine' ?></button>
                <a class="btn secondary" href="/inventory/medicines">Cancel</a>
            </div>
        </form>
    </details>
<?php endif; ?>

<table>
    <thead><tr><th>SKU</th><th>Brand / Generic</th><th>Form</th><th>Sched</th><th>Stock</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $m): ?>
        <tr>
            <td><?= e($m['sku']) ?></td>
            <td><strong><?= e($m['brand_name']) ?></strong><br><small><?= e($m['generic_name']) ?> <?= e($m['strength'] ?? '') ?></small></td>
            <td><?= e(str_replace('_', ' ', $m['form'])) ?></td>
            <td><?= e($m['controlled_schedule']) ?></td>
            <td>
                <?= e($m['on_hand']) ?> <?= e($m['base_unit']) ?>
                <?php if ((int) $m['units_per_purchase'] > 1 && (int) $m['on_hand'] > 0):
                    $packs = intdiv((int) $m['on_hand'], (int) $m['units_per_purchase']);
                    $rem   = (int) $m['on_hand'] - ($packs * (int) $m['units_per_purchase']);
                ?>
                    <br><small class="muted">
                        ≈ <?= e($packs) ?> <?= e($m['purchase_unit']) ?><?php if ($rem > 0): ?>
                            + <?= e($rem) ?> <?= e($m['base_unit']) ?><?php endif; ?>
                    </small>
                <?php endif; ?>
            </td>
            <td><a href="/inventory/medicines?edit=<?= e($m['id']) ?>">Edit</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Medicines', 'body' => $body, 'active' => 'medicines',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
