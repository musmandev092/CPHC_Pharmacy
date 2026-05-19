<?php
declare(strict_types=1);

/**
 * GET  /login   — render split-screen sign-in
 * POST /login   — validate, log in, redirect to /
 *
 * Layout has no sidebar/topbar (layout.php emits just the body for
 * unauthenticated users).  This page renders its own full-viewport shell.
 */

use CPHC\Auth;
use CPHC\Csrf;
use CPHC\Csp;
use CPHC\Session;
use CPHC\Router;
use CPHC\View;

if (Auth::check()) { Router::redirect('/'); }

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $pin      = (string) ($_POST['pin'] ?? '');
    if ($username === '' || $pin === '') {
        $error = 'Username and PIN are required.';
    } else {
        $r = Auth::attemptLogin($username, $pin);
        if ($r['ok']) Router::redirect('/');
        $error = $r['error'] ?? 'Login failed.';
    }
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$year = date('Y');

ob_start();
?>
<div class="auth-shell">

    <aside class="auth-shell__brand">
        <div class="auth-shell__brand-inner">
            <div class="auth-shell__brand-top">
                <span class="logo"><img src="/assets/logo.jpeg" alt="Care Point logo"></span>
                <span class="font-display" style="font-size:1.125rem;">Care Point Health Clinic</span>
            </div>

            <div>
                <h2 class="auth-shell__brand-headline">
                    Pharmacy operations,<br>
                    professionally managed.
                </h2>
                <p class="auth-shell__brand-blurb">
                    Cashier POS, GRN intake, FEFO batch dispensing, returns adjudication,
                    DRAP-compliant narcotic register, and a tamper-evident HMAC audit trail —
                    fully offline, single deployment.
                </p>
            </div>

            <div class="auth-shell__brand-foot">
                &copy; <?= e($year) ?> Care Point Health Clinic. All rights reserved.
            </div>
        </div>
    </aside>

    <div class="auth-shell__form">
        <div class="auth-shell__form-inner">

            <div class="auth-shell__mobile-brand">
                <span class="logo"><img src="/assets/logo.jpeg" alt="Care Point logo"></span>
                <div style="text-align:center;">
                    <h1 style="margin:0; font-size:1.5rem;" class="font-display">Care Point</h1>
                    <p class="muted" style="margin:4px 0 0; font-size:13px;">Pharmacy POS</p>
                </div>
            </div>

            <div class="card auth-card">
                <h2 class="font-display">Sign in</h2>
                <p class="auth-card__sub">Enter your username and PIN to access the system.</p>

                <?php if ($error !== null): ?>
                    <div class="flash error" style="margin-top:20px;">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>
                        </svg>
                        <div><?= e($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="post" action="/login" autocomplete="off" class="field-stack" style="margin-top:28px;">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">

                    <div>
                        <label for="username" style="margin:0;">
                            <span>Username</span>
                            <input type="text" id="username" name="username" autofocus required
                                   value="<?= e($username) ?>"
                                   maxlength="60" pattern="[A-Za-z0-9._\-]+"
                                   placeholder="e.g. cashier1"
                                   autocapitalize="none" autocorrect="off" spellcheck="false"
                                   autocomplete="username">
                        </label>
                    </div>

                    <div>
                        <div style="display:flex; align-items:baseline; justify-content:space-between; margin-bottom:6px;">
                            <span style="font-size:13px; font-weight:500;">PIN</span>
                            <span style="font-size:12px;" class="muted">6–8 digits</span>
                        </div>
                        <input type="password" id="pin" name="pin" required
                               inputmode="numeric" pattern="\d{6,8}"
                               minlength="6" maxlength="8"
                               placeholder="• • • • • •"
                               autocomplete="current-password"
                               class="auth-card__pin-input">
                    </div>

                    <button type="submit" class="btn-lg" style="width:100%; margin-top:8px;">Sign in</button>
                </form>

                <div class="auth-help">
                    Forgot your PIN? Ask your administrator to reset it.
                </div>
            </div>

            <p class="muted center mt-6" style="font-size:12px;">
                Protected with HTTPS. Sessions auto-expire after 30 min of inactivity (12 h absolute).
            </p>

        </div>
    </div>

</div>
<?php
$body = (string) ob_get_clean();

echo View::partial('layout', [
    'title'         => 'Sign in',
    'body'          => $body,
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
