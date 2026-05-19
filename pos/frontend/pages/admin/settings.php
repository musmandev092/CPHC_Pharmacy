<?php
declare(strict_types=1);

/**
 * Pharmacy-wide settings.  Backed by the `settings` key/value table.
 * Editable: pharmacy_name, pharmacy_address, pharmacy_phone,
 *           receipt_footer_message, return_policy_text.
 */

use CPHC\Audit;
use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Db;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;

Auth::requireRole(Auth::ROLE_ADMIN);
$adminId = (int) Session::userId();
$pageError = null;

$EDITABLE = [
    'pharmacy_name'          => ['Pharmacy name',          'Printed on every receipt and used in the page header.'],
    'pharmacy_address'       => ['Pharmacy address',       'Multi-line address.  Printed on receipts.'],
    'pharmacy_phone'         => ['Phone',                  'Customer contact number on receipts.'],
    'return_policy_text'     => ['Return policy text',     'Printed on receipts so customers know the policy upfront.'],
    'receipt_footer_message' => ['Receipt footer message', 'A short tagline at the bottom of every printed receipt.'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    try {
        $REQUIRED = ['pharmacy_name'];
        $LIMITS   = [
            'pharmacy_name'      => 160,
            'pharmacy_address'   => 500,
            'pharmacy_phone'     => 60,
            'return_policy_text' => 2000,
            'receipt_footer_message' => 200,
        ];
        $values = [];
        foreach ($EDITABLE as $key => $_) {
            if (!array_key_exists($key, $_POST)) continue;
            $val = trim((string) $_POST[$key]);
            if (in_array($key, $REQUIRED, true) && $val === '') {
                throw new \InvalidArgumentException(ucwords(str_replace('_', ' ', $key)) . ' cannot be empty.');
            }
            if (isset($LIMITS[$key]) && mb_strlen($val) > $LIMITS[$key]) {
                throw new \InvalidArgumentException(ucwords(str_replace('_', ' ', $key)) . " is too long (max {$LIMITS[$key]} characters).");
            }
            $values[$key] = $val;
        }
        foreach ($values as $key => $val) {
            Db::execute(
                "INSERT INTO settings (key, value) VALUES (:k, :v)
                   ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value",
                [':k' => $key, ':v' => $val],
            );
        }
        Audit::write($adminId, 'SETTINGS_UPDATED', 'settings', null, null, array_keys($EDITABLE));
        Session::flash('success', 'Settings saved.');
        Router::redirect('/settings');
    } catch (\Throwable $e) { $pageError = $e->getMessage(); }
}

$current = [];
foreach (Db::fetchAll('SELECT key, value FROM settings') as $r) {
    $current[$r['key']] = (string) $r['value'];
}

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Pharmacy settings</h1>
        <p class="page-header__sub">Branding, address, and receipt-footer copy.  Changes apply to the next printed receipt.</p>
    </div>
</div>

<?php if ($pageError): ?>
    <div class="flash error mb-4"><?= e($pageError) ?></div>
<?php endif; ?>

<section class="card" style="max-width:760px;">
    <div class="card__header">
        <h2 class="card__title">Branding &amp; receipt copy</h2>
    </div>
    <form method="post" action="/settings" class="card__body">
        <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
        <input type="hidden" name="action" value="save">

        <?php foreach ($EDITABLE as $key => [$label, $hint]): ?>
            <label>
                <span><?= e($label) ?></span>
                <?php if ($key === 'pharmacy_address' || $key === 'return_policy_text'): ?>
                    <textarea name="<?= e($key) ?>" rows="3" placeholder="<?= e($label) ?>…"><?= e($current[$key] ?? '') ?></textarea>
                <?php else: ?>
                    <input type="text" name="<?= e($key) ?>" value="<?= e($current[$key] ?? '') ?>" placeholder="<?= e($label) ?>…">
                <?php endif; ?>
                <small class="muted" style="display:block; margin-top:4px;"><?= e($hint) ?></small>
            </label>
        <?php endforeach; ?>

        <div class="mt-4 flex gap-2">
            <button type="submit">Save settings</button>
            <a class="btn-secondary" href="/">Cancel</a>
        </div>
    </form>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Settings',
    'body'  => $body,
    'active' => 'settings',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
