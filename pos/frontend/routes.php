<?php
declare(strict_types=1);

/*
 * src/routes.php — single route table.
 *
 * Returns a configured Router.  POST routes get automatic CSRF verification.
 * Anonymous (pre-login) routes: /login, /logout, /unauthorized, /csp-report.
 * Every other route requires Auth::requireLogin() at the top of its handler.
 */

use CPHC\Router;

$r = new Router();

// ── Public (no login) ────────────────────────────────────────────────────
$r->add('GET|POST', '@^/login/?$@',          'login');
$r->add('GET|POST', '@^/logout/?$@',         'logout');
$r->add('GET',      '@^/unauthorized/?$@',   'unauthorized');

// ── Authenticated landing ────────────────────────────────────────────────
$r->add('GET',      '@^/$@',                 'home');
$r->add('GET|POST', '@^/account/change-pin/?$@', 'account/change-pin');

// ── POS ──────────────────────────────────────────────────────────────────
$r->add('GET|POST', '@^/pos/?$@',                 'pos/terminal');
$r->add('GET|POST', '@^/pos/session/?$@',         'pos/session');
$r->add('GET|POST', '@^/pos/history/?$@',         'pos/history');
$r->add('GET|POST', '@^/pos/returns/?$@',         'pos/returns/initiate');
$r->add('GET',      '@^/pos/returns/history/?$@', 'pos/returns/history');

// ── Inventory ────────────────────────────────────────────────────────────
$r->add('GET',      '@^/inventory/?$@',                'inventory/dashboard');
$r->add('GET|POST', '@^/inventory/medicines/?$@',      'inventory/medicines');
$r->add('GET',      '@^/inventory/stock/?$@',          'inventory/stock');
$r->add('GET|POST', '@^/inventory/grn/?$@',            'inventory/grn-list');
$r->add('GET|POST', '@^/inventory/grn/new/?$@',        'inventory/grn-edit');
$r->add('GET|POST', '@^/inventory/grn/(?<id>\d+)/?$@', 'inventory/grn-edit');
$r->add('GET|POST', '@^/inventory/adjustments/?$@',    'inventory/adjustments');

// ── Suppliers ────────────────────────────────────────────────────────────
$r->add('GET|POST', '@^/suppliers/?$@', 'suppliers/index');

// ── Reports ──────────────────────────────────────────────────────────────
$r->add('GET', '@^/reports/?$@',                  'reports/dashboard');
$r->add('GET', '@^/reports/sales/?$@',            'reports/sales');
$r->add('GET', '@^/reports/pl/?$@',               'reports/pl');
$r->add('GET', '@^/reports/compliance/?$@',       'reports/compliance');
$r->add('GET', '@^/reports/expiry/?$@',           'reports/expiry');
$r->add('GET', '@^/reports/low-stock/?$@',        'reports/low-stock');
$r->add('GET', '@^/reports/velocity/?$@',         'reports/velocity');
$r->add('GET', '@^/reports/audit/?$@',            'reports/audit');
$r->add('GET', '@^/reports/narcotic-register/?$@','reports/narcotic-register');

// ── Sessions ─────────────────────────────────────────────────────────────
$r->add('GET', '@^/sessions/(?<id>\d+)/z-report/?$@', 'sessions/z-report');
$r->add('GET', '@^/zreport/verify/(?<id>\d+)/?$@',    'sessions/z-report-verify');

// ── Admin ────────────────────────────────────────────────────────────────
$r->add('GET|POST', '@^/admin/users/?$@',            'admin/users');
$r->add('GET|POST', '@^/admin/returns/?$@',          'admin/returns');
$r->add('GET|POST', '@^/admin/supplier-returns/?$@', 'admin/supplier-returns');
$r->add('GET|POST', '@^/admin/sessions/?$@',         'admin/sessions');
$r->add('GET|POST', '@^/admin/printer/?$@',          'admin/printer');
$r->add('GET|POST', '@^/admin/system/?$@',           'admin/system');
$r->add('GET|POST', '@^/settings/?$@',               'admin/settings');

return $r;
