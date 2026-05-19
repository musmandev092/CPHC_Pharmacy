<?php
declare(strict_types=1);

/**
 * Public Z-report verification.
 *
 * Anyone with the URL can hit this page; we never reveal cash/variance to
 * the unauthenticated viewer.  We just say OK or TAMPERED and show the
 * archive id so the auditor can cross-check.
 *
 * If a logged-in MANAGER or ADMIN visits, we also show the full payload.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\ZReport;

$sid = (int) ($params['id'] ?? 0);
$z = ZReport::loadForSession($sid);

$showFull = Session::userId() !== null
    && in_array(Session::role(), [Auth::ROLE_MANAGER, Auth::ROLE_ADMIN], true);

ob_start();
?>
<div class="error-card" style="text-align:left;">
    <h1 style="text-align:center;">
        Z-Report verification —
        <?php if ($z['archive_id'] === null): ?>
            <span style="color:#aaa;">NO ARCHIVE</span>
        <?php elseif ($z['signature'] === ''): ?>
            <span style="color:#aaa;">UNSIGNED</span>
        <?php elseif ($z['verified']): ?>
            <span style="color:#1e6a32;">✓ OK</span>
        <?php else: ?>
            <span style="color:#c0392b;">⚠ TAMPERED</span>
        <?php endif; ?>
    </h1>

    <p style="text-align:center; color:#666;">
        Session <?= e($sid) ?>, archive #<?= e($z['archive_id'] ?? '—') ?>.
    </p>

    <?php if ($z['archive_id'] !== null && $z['signature'] !== ''): ?>
        <p style="font-family:monospace; word-break:break-all; font-size:0.83em; background:#f6f7f9; padding:0.6em; border-radius:4px;">
            <strong>Signature:</strong> <?= e($z['signature']) ?>
        </p>
    <?php endif; ?>

    <?php if ($showFull && $z['archive_id'] !== null): ?>
        <h3>Archived payload</h3>
        <pre style="background:#f6f7f9; padding:0.7em; border-radius:4px; overflow:auto; font-size:0.85em;"><?= e(json_encode($z['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    <?php elseif ($showFull): ?>
        <p style="color:#666;">No archive row exists for this session yet.</p>
    <?php endif; ?>

    <p style="text-align:center; margin-top:1.5em;">
        <a href="/">Home</a>
    </p>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Z-Report Verify',
    'body'          => $body,
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
