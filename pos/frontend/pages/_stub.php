<?php
declare(strict_types=1);

/**
 * Stub for routes whose real handler hasn't been written yet.
 * Used during the incremental build-out so navigation stays functional.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Session;
use CPHC\View;

Auth::requireLogin();

$handler = (string) ($params['_handler'] ?? 'unknown');

ob_start();
?>
<div class="error-card">
    <h1>Coming soon</h1>
    <p>This page (<code><?= e($handler) ?></code>) is part of the incremental build-out and hasn&rsquo;t been written yet.</p>
    <p><a href="/">Back to home</a></p>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Coming soon',
    'body'          => $body,
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => Csp::nonce(),
]);
