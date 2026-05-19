<?php
declare(strict_types=1);

use CPHC\Session;
use CPHC\View;

http_response_code(403);

ob_start();
?>
<div class="empty-state" style="max-width:520px; margin:80px auto;">
    <svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2"/>
        <path d="M7 11V7a5 5 0 0110 0v4"/>
    </svg>
    <h3 class="font-display">Not authorised</h3>
    <p>You don&rsquo;t have permission to view that page. Ask an administrator if you think this is wrong.</p>
    <p style="margin-top:18px;"><a class="btn-secondary" href="/">&larr; Back home</a></p>
</div>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title'         => 'Not authorised',
    'body'          => $body,
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce'         => \CPHC\Csp::nonce(),
]);
