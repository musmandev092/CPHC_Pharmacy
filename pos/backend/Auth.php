<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Authentication facade.  Wraps:
 *   - login flow (rate limit → PIN verify → session bind → audit)
 *   - logout
 *   - role gate (require()/check())
 *   - 90-day PIN rotation redirect
 *
 * The PIN itself is bcrypt-hashed (cost 12) in users.pin_hash.  A separate
 * password_hash column exists for future use (web admin password); we ignore
 * it here, the cashier kiosk uses PIN only.
 *
 * Roles (UserRole enum in Postgres):  ADMIN, MANAGER, CASHIER.
 */
final class Auth
{
    public const ROLE_ADMIN   = 'ADMIN';
    public const ROLE_MANAGER = 'MANAGER';
    public const ROLE_CASHIER = 'CASHIER';

    /** @return array{ok:bool, error?:string, user?:array<string,mixed>} */
    public static function attemptLogin(string $username, string $pin): array
    {
        $username = trim($username);
        $ip = Audit::clientIp() ?? '0.0.0.0';

        $userKey = RateLimit::loginKey($username);
        $ipKey   = RateLimit::ipKey($ip);

        foreach ([$userKey, $ipKey] as $k) {
            $c = RateLimit::check($k);
            if (!$c['allowed']) {
                Audit::write(null, 'LOGIN_RATE_LIMITED', 'User', null, null,
                    ['key' => $k, 'username' => $username]);
                return ['ok' => false, 'error' => 'Too many attempts. Please try again later.'];
            }
        }

        $user = Db::fetchOne(
            'SELECT id, full_name, username, pin_hash, role, is_active,
                    pin_changed_at, must_rotate_pin, branch_id
             FROM users
             WHERE username = :u
               AND is_active = TRUE
               AND deleted_at IS NULL
             LIMIT 1',
            [':u' => $username],
        );

        if ($user === null || !password_verify($pin, (string) $user['pin_hash'])) {
            Audit::write(null, 'LOGIN_FAILED', 'User', null, null,
                ['username' => $username]);
            return ['ok' => false, 'error' => 'Invalid username or PIN.'];
        }

        // Success path.
        RateLimit::reset($userKey);
        RateLimit::reset($ipKey);

        Session::login($user);

        Db::execute(
            'UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id',
            [':id' => $user['id']],
        );

        Audit::write((int) $user['id'], 'LOGIN_SUCCESS', 'User', (int) $user['id'], null,
            ['username' => $username]);

        return ['ok' => true, 'user' => $user];
    }

    public static function logout(): void
    {
        $uid = Session::userId();
        if ($uid !== null) {
            Audit::write($uid, 'LOGOUT', 'User', $uid);
        }
        Session::logout();
    }

    public static function check(): bool
    {
        return Session::userId() !== null;
    }

    /** Require the user is logged in.  Redirects to /login otherwise. */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            Router::redirect('/login');
        }
        self::enforceRotation();
    }

    /** Require one of the given roles.  Redirects to /unauthorized otherwise. */
    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        $role = Session::role();
        if ($role === null || !in_array($role, $roles, true)) {
            http_response_code(403);
            Router::redirect('/unauthorized');
        }
    }

    /** Push a user toward /account/change-pin if their PIN is older than 90 days. */
    private static function enforceRotation(): void
    {
        $uid = Session::userId();
        if ($uid === null) return;

        // Cached per session — checked at most once per minute.
        $last = (int) (Session::get('_pin_rotation_checked_at') ?? 0);
        if ((time() - $last) < 60) return;
        Session::set('_pin_rotation_checked_at', time());

        $row = Db::fetchOne(
            'SELECT pin_changed_at, must_rotate_pin FROM users WHERE id = :id',
            [':id' => $uid],
        );
        if ($row === null) return;

        $changedAt = new \DateTimeImmutable((string) $row['pin_changed_at']);
        $daysSince = (new \DateTimeImmutable('now'))->diff($changedAt)->days;

        $needsRotation = (bool) $row['must_rotate_pin']
            || $daysSince >= PinPolicy::ROTATION_DAYS;

        if ($needsRotation) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            if ($path !== '/account/change-pin' && $path !== '/logout') {
                Router::redirect('/account/change-pin');
            }
        }
    }
}
