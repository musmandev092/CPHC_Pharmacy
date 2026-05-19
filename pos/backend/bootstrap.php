<?php
declare(strict_types=1);

/*
 * src/bootstrap.php
 *
 * Single entry point for loading all CPHC\* classes.  No Composer.  Each
 * file in src/ that exposes a CPHC\X class is required explicitly.  Order
 * matters only where one class statically uses another at class-load time;
 * none of ours does (everything is lazy).
 *
 * Included exactly once from public/index.php.
 */

require_once __DIR__ . '/e.php';      // global e() and u()

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Csp.php';
require_once __DIR__ . '/Session.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Money.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/RateLimit.php';
require_once __DIR__ . '/PinPolicy.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/View.php';
require_once __DIR__ . '/Auth.php';

// Lazy-load src/services/X.php on first use of CPHC\Services\X,
// and src/exceptions/X.php on first use of CPHC\Exceptions\X.
spl_autoload_register(static function (string $class): void {
    foreach ([
        'CPHC\\Services\\'   => __DIR__ . '/services/',
        'CPHC\\Exceptions\\' => __DIR__ . '/exceptions/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $name = substr($class, strlen($prefix));
            $file = $dir . str_replace('\\', '/', $name) . '.php';
            if (is_file($file)) require_once $file;
            return;
        }
    }
});
