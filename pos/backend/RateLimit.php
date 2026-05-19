<?php
declare(strict_types=1);

namespace CPHC;

/*
 * DB-backed sliding-window rate limiter with progressive delay.
 * Port of the Laravel RateLimiter (backend/app/Services/Auth/RateLimiter.php),
 * preserving the same window, cap, and delay schedule.
 *
 *   - 5 attempts / 15-minute window per key.
 *   - Progressive delay schedule (ms): [0, 0, 0, 1000, 5000]
 *     i.e. 3rd failed attempt sleeps 1 s, 4th sleeps 5 s, 5th rejects with 429.
 *   - reset($key) clears the counter — call on successful authentication.
 *
 * The rate_limits table is created by db/schema/init.sql:
 *   (key VARCHAR(120) PK, count INT, reset_at TIMESTAMPTZ)
 */
final class RateLimit
{
    public const WINDOW_MINUTES = 15;
    public const MAX_ATTEMPTS   = 5;

    /** Indexed on count BEFORE this attempt. */
    public const PROGRESSIVE_DELAY_MS = [0, 0, 0, 1000, 5000];

    /** @return array{allowed:bool, remaining_ms:int} */
    public static function check(string $key): array
    {
        $pdo = Db::pdo();

        $row = Db::fetchOne(
            'SELECT count, reset_at FROM rate_limits WHERE key = :k',
            [':k' => $key],
        );

        $now = new \DateTimeImmutable('now');

        // No row, or window expired → fresh start.
        if ($row === null || new \DateTimeImmutable((string) $row['reset_at']) <= $now) {
            $resetAt = $now->modify('+' . self::WINDOW_MINUTES . ' minutes');
            Db::execute(
                'INSERT INTO rate_limits (key, count, reset_at)
                 VALUES (:k, 1, :r)
                 ON CONFLICT (key) DO UPDATE SET count = 1, reset_at = EXCLUDED.reset_at',
                [':k' => $key, ':r' => $resetAt->format('Y-m-d H:i:sO')],
            );
            return ['allowed' => true, 'remaining_ms' => 0];
        }

        $count = (int) $row['count'];

        // Hard cap reached.
        if ($count >= self::MAX_ATTEMPTS) {
            $resetAt = new \DateTimeImmutable((string) $row['reset_at']);
            $remaining = max(0, ($resetAt->getTimestamp() - $now->getTimestamp()) * 1000);
            return ['allowed' => false, 'remaining_ms' => $remaining];
        }

        // Progressive delay BEFORE incrementing.
        $delayMs = self::PROGRESSIVE_DELAY_MS[$count] ?? 0;
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        Db::execute(
            'UPDATE rate_limits SET count = count + 1 WHERE key = :k',
            [':k' => $key],
        );

        return ['allowed' => true, 'remaining_ms' => 0];
    }

    public static function reset(string $key): void
    {
        Db::execute('DELETE FROM rate_limits WHERE key = :k', [':k' => $key]);
    }

    public static function loginKey(string $username): string
    {
        return 'login:' . strtolower(trim($username));
    }

    public static function ipKey(string $ip): string
    {
        return 'login-ip:' . $ip;
    }
}
