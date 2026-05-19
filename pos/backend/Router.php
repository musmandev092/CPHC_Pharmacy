<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Tiny array-based router.
 *
 * Routes are declared in src/routes.php as an array of
 *   [method, path-regex, handler-file]  triples.
 *
 * - method is "GET"|"POST"|"GET|POST"|"*"
 * - path-regex is a normal PHP regex anchored against the request URI path.
 *   Use named captures for params: '@^/admin/users/(?<id>\d+)$@'
 * - handler-file is a path inside src/pages/ (without the leading "src/pages/"
 *   and without ".php") that is included with $params already in scope.
 *
 * The router auto-runs Csrf::verify() on every POST.  Pages that need to skip
 * it (CSP report endpoint, healthz) live OUTSIDE the table and are handled
 * in public/index.php directly.
 */
final class Router
{
    /** @var array<int,array{method:string, pattern:string, handler:string, name?:string}> */
    private array $routes = [];

    /** Register a route. */
    public function add(string $method, string $pattern, string $handler, ?string $name = null): void
    {
        $this->routes[] = [
            'method'  => $method,
            'pattern' => $pattern,
            'handler' => $handler,
            'name'    => $name,
        ];
    }

    /**
     * Dispatch.  Sets HTTP status + body and returns true on match, false
     * if no route matched (caller is expected to 404).
     */
    public function dispatch(string $method, string $path): bool
    {
        foreach ($this->routes as $route) {
            if (!self::methodMatches($route['method'], $method)) continue;
            if (!preg_match($route['pattern'], $path, $m)) continue;

            // Pull out named captures only.
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) $params[$k] = $v;
            }

            // CSRF on every state-changing request.
            if ($method === 'POST' || $method === 'PUT' || $method === 'DELETE' || $method === 'PATCH') {
                Csrf::verify();
            }

            // Always set CSP before emitting any output.
            Csp::emit();

            $file = __DIR__ . '/../frontend/pages/' . $route['handler'] . '.php';
            if (!is_file($file)) {
                // Fallback to the "coming soon" stub during the incremental
                // build-out so navigation works without throwing.
                $file = __DIR__ . '/../frontend/pages/_stub.php';
            }
            // Pass handler name to stub so it can show which page is missing.
            $params['_handler'] = $route['handler'];

            // $params is visible inside the include.
            (static function (string $__file, array $params): void {
                include $__file;
            })($file, $params);

            return true;
        }
        return false;
    }

    /** Safe redirect: only relative paths starting with "/" are honoured. */
    public static function redirect(string $path, int $status = 302): never
    {
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            $path = '/';
        }
        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $path);
        }
        exit;
    }

    private static function methodMatches(string $allowed, string $actual): bool
    {
        if ($allowed === '*') return true;
        foreach (explode('|', $allowed) as $m) {
            if ($m === $actual) return true;
        }
        return false;
    }
}
