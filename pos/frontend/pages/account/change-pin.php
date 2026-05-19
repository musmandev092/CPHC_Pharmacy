<?php
declare(strict_types=1);

/**
 * Self-service PIN rotation.  Triggered by Auth::enforceRotation() when the
 * current PIN is older than 90 days or must_rotate_pin=TRUE.
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

Auth::requireLogin();

$uid = (int) Session::userId();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string) ($_POST['current_pin'] ?? '');
    $newPin  = (string) ($_POST['new_pin']     ?? '');
    $confirm = (string) ($_POST['confirm_pin'] ?? '');

    $user = Db::fetchOne('SELECT id, pin_hash FROM users WHERE id = :id', [':id' => $uid]);

    if ($user === null) {
        $error = 'Unknown user.';
    } elseif (!password_verify($current, (string) $user['pin_hash'])) {
        $error = 'Current PIN is incorrect.';
        Audit::write($uid, 'PIN_CHANGE_FAILED', 'User', $uid, null, ['reason' => 'wrong_current']);
    } elseif ($newPin !== $confirm) {
        $error = 'New PIN and confirmation do not match.';
    } else {
        $policyError = PinPolicy::validate($newPin, $uid, (string) $user['pin_hash']);
        if ($policyError !== null) {
            $error = $policyError;
        } else {
            try {
                Db::transaction(function () use ($uid, $newPin) {
                    $hash = PinPolicy::recordChange($uid, $newPin);
                    Db::execute(
                        'UPDATE users
                           SET pin_hash = :h,
                               pin_changed_at = CURRENT_TIMESTAMP,
                               must_rotate_pin = FALSE,
                               updated_at = CURRENT_TIMESTAMP
                         WHERE id = :id',
                        [':h' => $hash, ':id' => $uid],
                    );
                });
                Audit::write($uid, 'PIN_CHANGED', 'User', $uid);
                Session::flash('success', 'PIN updated.');
                Router::redirect('/');
            } catch (\Throwable $e) {
                error_log('PIN change failed: ' . $e->getMessage());
                $error = 'Could not update PIN. Please try again.';
            }
        }
    }
}

$ic = static fn (string $d, int $size = 18) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div style="max-width:480px; margin:0 auto;">
    <div class="page-header" style="margin-bottom:16px;">
        <div class="page-header__title">
            <h1 class="font-display">Change your PIN</h1>
            <p class="page-header__sub">
                Your PIN is older than <?= e(\CPHC\PinPolicy::ROTATION_DAYS) ?> days or an administrator has asked you to rotate it.
            </p>
        </div>
    </div>

    <?php if ($error !== null): ?>
        <div class="flash error mb-4"><?= $ic('<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>') ?><div><?= e($error) ?></div></div>
    <?php endif; ?>

    <section class="card">
        <div class="card__header">
            <h2 class="card__title">Rotate PIN</h2>
            <span class="badge badge--warning">Required</span>
        </div>
        <form method="post" action="/account/change-pin" autocomplete="off" class="card__body">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">

            <label>
                <span>Current PIN</span>
                <input type="password" name="current_pin" required
                       inputmode="numeric" pattern="\d{6,8}" minlength="6" maxlength="8"
                       placeholder="• • • • • •"
                       style="text-align:center; letter-spacing:0.6em; font-size:1rem;">
            </label>
            <label>
                <span>New PIN <span class="muted" style="font-weight:400;">(6–8 digits, no obvious sequences)</span></span>
                <input type="password" name="new_pin" required autofocus
                       inputmode="numeric" pattern="\d{6,8}" minlength="6" maxlength="8"
                       placeholder="• • • • • •"
                       style="text-align:center; letter-spacing:0.6em; font-size:1rem;">
            </label>
            <label>
                <span>Confirm new PIN</span>
                <input type="password" name="confirm_pin" required
                       inputmode="numeric" pattern="\d{6,8}" minlength="6" maxlength="8"
                       placeholder="• • • • • •"
                       style="text-align:center; letter-spacing:0.6em; font-size:1rem;">
            </label>

            <div class="mt-4 flex gap-2">
                <button type="submit" class="btn-lg">Update PIN</button>
            </div>
        </form>
    </section>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Change PIN',
    'body'          => $body,
    'active'        => '',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
