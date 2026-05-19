<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Hand-rolled CSRF.  One token per session, embedded in every form, verified
 * at the top of every POST handler.
 *
 * Usage:
 *   <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
 *
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST') Csrf::verify();
 *
 * Verify() rejects with 419 (matching Laravel convention) so the user sees a
 * "session expired, please reload" page rather than a generic 403.
 */
final class Csrf
{
    public static function token(): string
    {
        Session::start();
        $t = Session::get('_csrf');
        if (!is_string($t) || strlen($t) !== 64) {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function verify(): void
    {
        Session::start();
        $stored = (string) (Session::get('_csrf') ?? '');
        $sent   = (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($stored === '' || $sent === '' || !hash_equals($stored, $sent)) {
            http_response_code(419);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html><head><title>Session expired</title></head>';
            echo '<body><h1>Session expired</h1><p>Please reload the page and try again.</p></body></html>';
            exit;
        }
    }

    /** Convenience for templates: hidden input. */
    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' .
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
