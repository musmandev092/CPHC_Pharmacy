<?php
declare(strict_types=1);

/*
 * CPHC Pharmacy POS — front controller.
 *
 * Every request lands here.  Caddy serves /assets/* directly and only
 * proxies non-asset paths to PHP-FPM, so this file only sees app routes.
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');
error_reporting(E_ALL);

// Hardening response headers.  In production Caddy also sets HSTS; we keep
// the rest in PHP so the dev server matches prod parity for security tests.
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
// HSTS only over HTTPS — sending over HTTP confuses browsers.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
if ($https) {
    header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
}

require_once __DIR__ . '/../backend/bootstrap.php';

use CPHC\Csp;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$path   = parse_url($uri, PHP_URL_PATH) ?: '/';

// ── PHP built-in dev server: serve real static files directly. ──────────
// In production Caddy handles /assets/* — this block is a no-op there
// (the file exists check still resolves correctly, returning false makes
// PHP-FPM emit the file).  Required for `php -S` to find /assets/app.css.
if (PHP_SAPI === 'cli-server') {
    $abs = __DIR__ . $path;
    if (is_file($abs) && $path !== '/index.php') {
        return false; // tell built-in server to serve the file itself
    }
}

// ── No-CSRF, no-session endpoints ────────────────────────────────────────
if ($path === '/healthz') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ok\n";
    exit;
}

if ($path === '/csp-report' && $method === 'POST') {
    // Accept the report, log to stderr, return 204.
    $raw = (string) file_get_contents('php://input');
    if ($raw !== '') {
        error_log('CSP-report: ' . substr($raw, 0, 2000));
    }
    http_response_code(204);
    exit;
}

// ── Session must be started before any route is dispatched, even on
// ── error pages, so flash messages and CSRF tokens work.
Session::start();

// ── Dispatch through the route table.
/** @var Router $router */
$router = require __DIR__ . '/../frontend/routes.php';

try {
    $matched = $router->dispatch($method, $path);
} catch (\Throwable $e) {
    error_log('[dispatch error] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if (!headers_sent()) {
        Csp::emit();
        header('Content-Type: text/html; charset=utf-8');
    }
    $body = '<div class="empty-state" style="max-width:520px; margin:80px auto;">'
          . '<svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>'
          . '<h3 class="font-display">Something went wrong</h3>'
          . '<p>An unexpected error occurred.  The incident has been logged.</p>'
          . '<p style="margin-top:18px;"><a class="btn-secondary" href="/">← Back home</a></p>'
          . '</div>';
    echo View::partial('layout', [
        'title' => 'Error', 'body' => $body, 'active' => null,
        'nonce' => Csp::nonce(),
    ]);
    exit;
}

if (!$matched) {
    http_response_code(404);
    if (!headers_sent()) {
        Csp::emit();
        header('Content-Type: text/html; charset=utf-8');
    }
    $body = '<div class="empty-state" style="max-width:520px; margin:80px auto;">'
          . '<svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>'
          . '<h3 class="font-display">Page not found</h3>'
          . '<p>The page you\'re looking for doesn\'t exist or was moved.</p>'
          . '<p style="margin-top:18px;"><a class="btn-secondary" href="/">← Back home</a></p>'
          . '</div>';
    echo View::partial('layout', [
        'title' => '404 — Not found', 'body' => $body, 'active' => null,
        'nonce' => Csp::nonce(),
    ]);
}
