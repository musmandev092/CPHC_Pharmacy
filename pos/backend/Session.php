<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Wrapper around native PHP sessions with strict cookie attrs + fingerprint.
 *
 * The cookie flags are already set globally in /usr/local/etc/php/conf.d/security.ini
 * inside the container.  This class adds:
 *   - Session::start()       opens the session, validates fingerprint, idle timeout
 *   - Session::login($u)     regenerates ID and stores the bound user
 *   - Session::logout()      destroys cookie + server-side data
 *   - Session::user()        the currently authenticated user (or null)
 *   - Session::flash(...)    one-shot messages survived across one redirect
 *
 * Fingerprint = HMAC(User-Agent || /24-prefix(IP), $SESSION_SECRET).
 * Cheap defence: an attacker who steals only a cookie still needs the same
 * coarse network bucket + UA to ride it.
 *
 * Idle timeout: 30 minutes since last activity.  Absolute: 12 hours.
 */
final class Session
{
    public const IDLE_SECONDS     = 1800;   // 30 min
    public const ABSOLUTE_SECONDS = 43200;  // 12 h

    private static ?string $secret = null;
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            // Belt-and-braces: also set the cookie params here so HttpOnly and
            // SameSite apply even if the underlying SAPI (php -S, FPM pool ini
            // drift, …) hasn't carried them.  Secure auto-enables on HTTPS.
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            // Name the cookie explicitly so it's obvious and doesn't clash
            // with anything else, and force strict-mode so the server rejects
            // session IDs it didn't issue (defence against session fixation).
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_name('cphc_sid');
            session_start();
        }
        self::$started = true;

        // Bootstrap a fresh anonymous session.
        if (!isset($_SESSION['created_at'])) {
            $_SESSION['created_at'] = time();
            $_SESSION['last_seen']  = time();
            $_SESSION['fingerprint'] = self::fingerprint();
            return;
        }

        $now = time();

        // Absolute timeout — even an active session expires after 12h.
        if (($now - (int) $_SESSION['created_at']) > self::ABSOLUTE_SECONDS) {
            self::logout();
            self::start();
            return;
        }

        // Idle timeout — 30 min since last request.
        if (($now - (int) $_SESSION['last_seen']) > self::IDLE_SECONDS) {
            self::logout();
            self::start();
            return;
        }

        // Fingerprint check — if the UA or /24 changes mid-session, kill it.
        if (($_SESSION['fingerprint'] ?? '') !== self::fingerprint()) {
            self::logout();
            self::start();
            return;
        }

        $_SESSION['last_seen'] = $now;
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user): void
    {
        self::start();
        // Regenerate to defeat session-fixation.
        session_regenerate_id(true);
        $_SESSION['user_id']     = (int) $user['id'];
        $_SESSION['username']    = (string) $user['username'];
        $_SESSION['role']        = (string) $user['role'];
        $_SESSION['full_name']   = (string) ($user['full_name'] ?? '');
        $_SESSION['created_at']  = time();
        $_SESSION['last_seen']   = time();
        $_SESSION['fingerprint'] = self::fingerprint();
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'] ?? '/',
                    'domain'   => $params['domain'] ?? '',
                    'secure'   => $params['secure'] ?? true,
                    'httponly' => $params['httponly'] ?? true,
                    'samesite' => $params['samesite'] ?? 'Strict',
                ],
            );
        }
        session_destroy();
        self::$started = false;
    }

    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function role(): ?string
    {
        self::start();
        return $_SESSION['role'] ?? null;
    }

    public static function username(): ?string
    {
        self::start();
        return $_SESSION['username'] ?? null;
    }

    public static function fullName(): ?string
    {
        self::start();
        return $_SESSION['full_name'] ?? null;
    }

    public static function flash(string $key, ?string $message = null): ?string
    {
        self::start();
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;
            return null;
        }
        $val = $_SESSION['_flash'][$key] ?? null;
        if (isset($_SESSION['_flash'][$key])) {
            unset($_SESSION['_flash'][$key]);
        }
        return $val;
    }

    /** Bind data into the session that survives until logout. */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    private static function fingerprint(): string
    {
        if (self::$secret === null) {
            $file = getenv('SESSION_SECRET_FILE') ?: '';
            if ($file === '' || !is_readable($file)) {
                throw new \RuntimeException('SESSION_SECRET_FILE is unset or unreadable');
            }
            self::$secret = trim((string) file_get_contents($file));
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        // /24 prefix for IPv4; full address for IPv6 (less coarse, but the
        // common case for LAN POS is IPv4 anyway).
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $ip = $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
        }
        return hash_hmac('sha256', $ua . '|' . $ip, self::$secret);
    }
}
