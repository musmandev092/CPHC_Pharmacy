<?php
/**
 * Standard app layout — sticky topbar with brand, primary nav, dropdown
 * groups, and a user cluster on the right.  Inspired by the original
 * Laravel/Tailwind reference but rebuilt in pure CSS + a tiny vanilla-JS
 * dropdown toggle (see /assets/app.js).
 *
 * Variables provided by callers:
 *   $title         (string)
 *   $body          (string, pre-rendered HTML)
 *   $flash_success (?string)
 *   $flash_error   (?string)
 *   $nonce         (string)
 *   $active        (?string)  slug — see $primary / $groups below
 */

use CPHC\Auth;
use CPHC\Csrf;
use CPHC\Session;

$user = Session::userId() ? [
    'id'   => Session::userId(),
    'name' => Session::fullName() ?? Session::username() ?? '—',
    'role' => Session::role() ?? '',
] : null;
$role    = $user['role'] ?? '';
$isAdmin = $role === Auth::ROLE_ADMIN;
$isMgr   = $role === Auth::ROLE_MANAGER || $isAdmin;
$active  = $active ?? '';

/* SVG icon paths (stroke + currentColor). */
$icon = static function (string $name): string {
    $p = [
        'dashboard'  => '<path d="M3 9l9-7 9 7v11a2 2 0 01-2 2h-5v-7H10v7H5a2 2 0 01-2-2V9z"/>',
        'pos'        => '<path d="M3 6h18l-2 12H5L3 6zM8 10v6m4-6v6m4-6v6"/>',
        'history'    => '<path d="M3 12a9 9 0 109-9 9 9 0 00-6.39 2.61L3 8M3 3v5h5M12 8v4l3 2"/>',
        'returns'    => '<path d="M9 14L4 9l5-5M4 9h11a5 5 0 010 10h-3"/>',
        'shift'      => '<path d="M12 6v6l4 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'inventory'  => '<path d="M3 7l9-4 9 4-9 4-9-4zm0 0v10l9 4 9-4V7"/>',
        'medicines'  => '<path d="M10 3l4 4-7 7H3v-4l7-7zM14 7l5-5 3 3-5 5"/>',
        'grn'        => '<path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 5h6M9 12h6M9 16h6"/>',
        'stock'      => '<path d="M3 7l9-4 9 4M3 7v10l9 4 9-4V7M3 7l9 4 9-4M12 11v10"/>',
        'suppliers'  => '<path d="M3 7h18v10H3zM3 7l3-4h12l3 4M9 17v3m6-3v3"/>',
        'reports'    => '<path d="M4 19V8m6 11V4m6 15v-8m6 8v-5"/>',
        'reconcile'  => '<path d="M3 12h7l2-4 4 8 2-4h3"/>',
        'adjudicate' => '<path d="M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>',
        'narcotic'   => '<path d="M12 2L4 6v6c0 5 3.4 9.4 8 10 4.6-.6 8-5 8-10V6l-8-4z"/>',
        'users'      => '<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8z"/>',
        'settings'   => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.34 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.7 1.7 0 00-1.82-.34 1.7 1.7 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.7 1.7 0 00-1-1.51 1.7 1.7 0 00-1.82.34l-.06.06a2 2 0 11-2.83-2.83l.06-.06A1.7 1.7 0 005 15.4a1.7 1.7 0 00-1.51-1H3a2 2 0 110-4h.09a1.7 1.7 0 001.51-1 1.7 1.7 0 00-.34-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.7 1.7 0 001.82.34h.08a1.7 1.7 0 001-1.51V3a2 2 0 114 0v.09a1.7 1.7 0 001 1.51 1.7 1.7 0 001.82-.34l.06-.06a2 2 0 112.83 2.83l-.06.06a1.7 1.7 0 00-.34 1.82v.08a1.7 1.7 0 001.51 1H21a2 2 0 110 4h-.09a1.7 1.7 0 00-1.51 1z"/>',
        'printer'    => '<path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/>',
        'system'     => '<path d="M9 3v18M3 9h18M3 15h18M15 3v18"/>',
        'logout'     => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
        'chevron'    => '<path d="M6 9l6 6 6-6"/>',
    ];
    $body = $p[$name] ?? '';
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
};

/* Primary nav (always visible for cashier+) */
$primary = [
    'home'    => ['Dashboard', '/',                 'dashboard'],
    'pos'     => ['POS',       '/pos',              'pos'],
    'history' => ['History',   '/pos/history',      'history'],
    'returns' => ['Returns',   '/pos/returns',      'returns'],
    'shift'   => ['Day Close', '/pos/session',      'shift'],
];

/* Dropdown groups (each appears only if user has the role). */
$groupInventory = [
    'inventory'   => ['Inventory',   '/inventory',            'inventory'],
    'medicines'   => ['Medicines',   '/inventory/medicines',  'medicines'],
    'grn'         => ['Goods Received', '/inventory/grn',     'grn'],
    'stock'       => ['Stock',       '/inventory/stock',      'stock'],
    'adjustments' => ['Adjustments', '/inventory/adjustments','grn'],
    'suppliers'   => ['Suppliers',   '/suppliers',            'suppliers'],
];
$groupReports = [
    'reports'      => ['Reports',           '/reports',                   'reports'],
    'adjudicate'   => ['Adjudicate Returns','/admin/returns',             'adjudicate'],
    'sup-returns'  => ['Supplier returns',  '/admin/supplier-returns',    'suppliers'],
    'reconcile'    => ['Reconcile Sessions','/admin/sessions',            'reconcile'],
    'narcotic'     => ['Narcotic Register', '/reports/narcotic-register', 'narcotic'],
];
$groupAdmin = [
    'users'    => ['Users',    '/admin/users',   'users'],
    'settings' => ['Settings', '/settings',      'settings'],
    'printer'  => ['Printer',  '/admin/printer', 'printer'],
    'system'   => ['System',   '/admin/system',  'system'],
];

/* Is any slug in a group the active one? */
$groupActive = static function (array $g) use ($active): bool {
    return array_key_exists($active, $g);
};
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="referrer" content="same-origin">
    <meta name="robots" content="noindex,nofollow">
    <title><?= e($title) ?> · Care Point</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/jpeg" href="/assets/logo.jpeg">
</head>
<body>
<?php if ($user === null): ?>
    <?= $body ?>
<?php else: ?>
<div class="app-shell">

    <header class="header">
        <div class="header__inner">

            <a href="/" class="brand">
                <span class="brand__mark"><img src="/assets/logo.jpeg" alt="Care Point logo"></span>
                <span class="brand__text">
                    <span class="brand__name">Care Point</span>
                    <span class="brand__tagline">Pharmacy POS</span>
                </span>
            </a>

            <nav class="nav">
                <?php foreach ($primary as $slug => [$label, $href, $iconName]): ?>
                    <a href="<?= e($href) ?>" class="nav__link <?= $active === $slug ? 'is-active' : '' ?>">
                        <?= $icon($iconName) ?> <?= e($label) ?>
                    </a>
                <?php endforeach; ?>

                <?php if ($isMgr || $isAdmin): ?>
                    <span class="nav__sep" aria-hidden="true"></span>
                <?php endif; ?>

                <?php if ($isMgr): ?>
                    <div class="nav__group" data-nav-group>
                        <button type="button" class="nav__group-toggle <?= $groupActive($groupInventory) ? 'is-active' : '' ?>" data-nav-toggle>
                            <?= $icon('inventory') ?> Inventory
                            <?= $icon('chevron') ?>
                        </button>
                        <div class="nav__menu" hidden>
                            <?php foreach ($groupInventory as $slug => [$label, $href, $iconName]): ?>
                                <a href="<?= e($href) ?>" class="<?= $active === $slug ? 'is-active' : '' ?>">
                                    <?= $icon($iconName) ?> <?= e($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="nav__group" data-nav-group>
                        <button type="button" class="nav__group-toggle <?= $groupActive($groupReports) ? 'is-active' : '' ?>" data-nav-toggle>
                            <?= $icon('reports') ?> Reports
                            <?= $icon('chevron') ?>
                        </button>
                        <div class="nav__menu" hidden>
                            <?php foreach ($groupReports as $slug => [$label, $href, $iconName]): ?>
                                <a href="<?= e($href) ?>" class="<?= $active === $slug ? 'is-active' : '' ?>">
                                    <?= $icon($iconName) ?> <?= e($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($isAdmin): ?>
                    <div class="nav__group" data-nav-group>
                        <button type="button" class="nav__group-toggle <?= $groupActive($groupAdmin) ? 'is-active' : '' ?>" data-nav-toggle>
                            <?= $icon('settings') ?> Admin
                            <?= $icon('chevron') ?>
                        </button>
                        <div class="nav__menu" hidden>
                            <?php foreach ($groupAdmin as $slug => [$label, $href, $iconName]): ?>
                                <a href="<?= e($href) ?>" class="<?= $active === $slug ? 'is-active' : '' ?>">
                                    <?= $icon($iconName) ?> <?= e($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </nav>

            <div class="userbox">
                <div class="userbox__meta">
                    <span class="userbox__name"><?= e($user['name']) ?></span>
                    <span class="userbox__role"><?= e($user['role']) ?></span>
                </div>
                <form method="post" action="/logout" class="inline-form">
                    <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
                    <button type="submit" class="btn-ghost" title="Sign out">
                        <?= $icon('logout') ?>
                        <span class="hidden-mobile">Logout</span>
                    </button>
                </form>
            </div>

        </div>
    </header>

    <?php
    /* Some pages (POS terminal) need to fill the entire viewport.  Pages
       opt in by passing $fullbleed = true to View::partial('layout', …). */
    $fb = !empty($fullbleed);
    $flashHtml = '';
    if (!empty($flash_success) || !empty($flash_error)) {
        $flashHtml .= '<div class="flash-stack">';
        if (!empty($flash_success)) {
            $flashHtml .= '<div class="flash success">'
                . '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>'
                . '<div>' . e($flash_success) . '</div></div>';
        }
        if (!empty($flash_error)) {
            $flashHtml .= '<div class="flash error">'
                . '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16v.01"/></svg>'
                . '<div>' . e($flash_error) . '</div></div>';
        }
        $flashHtml .= '</div>';
    }
    ?>
    <div class="main <?= $fb ? 'main--fullbleed' : '' ?>">
        <?php if ($fb): ?>
            <?= $flashHtml ?><?= $body ?>
        <?php else: ?>
            <div class="content">
                <?= $flashHtml ?>
                <?= $body ?>
            </div>
        <?php endif; ?>
    </div>

</div>
<?php endif; ?>

<script nonce="<?= e($nonce) ?>" src="/assets/app.js"></script>
</body>
</html>
