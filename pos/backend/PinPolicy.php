<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Centralised PIN strength + reuse policy.  Port of
 * backend/app/Services/Auth/PinPolicy.php.
 *
 * Rules (unchanged from Laravel version):
 *   - Length: 6-8 digits.
 *   - All digits, validated by regex.
 *   - Blocklist of obvious sequences (1234, 0000, 123456, …).
 *   - No straight ascending or descending run (1234, 2345, …, 9876, …).
 *   - No repeat of the last 5 PINs for this user (DB lookup against pin_history).
 *   - Different from the current PIN.
 *
 * The DB trigger phase4_pin_policy enforces this independently at INSERT time;
 * this class gives the user a friendly error before the trigger fires.
 */
final class PinPolicy
{
    public const MIN_LEN       = 6;
    public const MAX_LEN       = 8;
    public const HISTORY_KEEP  = 5;
    public const ROTATION_DAYS = 90;

    private const BLOCKLIST = [
        '000000', '111111', '222222', '333333', '444444', '555555',
        '666666', '777777', '888888', '999999', '123456', '654321',
        '12345', '54321', '12345678', '87654321',
    ];

    /** @return string|null  null = OK, string = error message */
    public static function validate(string $newPin, ?int $forUserId = null, ?string $currentHash = null): ?string
    {
        $pin = trim($newPin);

        if (!preg_match('/^\d{' . self::MIN_LEN . ',' . self::MAX_LEN . '}$/', $pin)) {
            return 'PIN must be ' . self::MIN_LEN . '-' . self::MAX_LEN . ' digits.';
        }

        if (in_array($pin, self::BLOCKLIST, true)) {
            return 'PIN is too predictable. Pick something else.';
        }

        if (self::isStraightRun($pin)) {
            return 'PIN must not be a sequence like 1234 or 9876.';
        }

        if ($forUserId !== null) {
            $rows = Db::fetchAll(
                'SELECT pin_hash FROM pin_history
                 WHERE user_id = :u
                 ORDER BY changed_at DESC
                 LIMIT ' . (int) self::HISTORY_KEEP,
                [':u' => $forUserId],
            );
            foreach ($rows as $r) {
                if (password_verify($pin, (string) $r['pin_hash'])) {
                    return 'PIN was used recently. Pick one you haven\'t used in your last ' .
                        self::HISTORY_KEEP . ' rotations.';
                }
            }
        }

        if ($currentHash !== null && password_verify($pin, $currentHash)) {
            return 'New PIN must be different from the current one.';
        }

        return null;
    }

    /** Hash + push onto pin_history, trim to HISTORY_KEEP.
     *  Caller MUST wrap this and the user-table update in a transaction. */
    public static function recordChange(int $userId, string $newPin): string
    {
        $hash = password_hash($newPin, PASSWORD_BCRYPT, ['cost' => 12]);

        Db::execute(
            'INSERT INTO pin_history (user_id, pin_hash, changed_at)
             VALUES (:u, :h, CURRENT_TIMESTAMP)',
            [':u' => $userId, ':h' => $hash],
        );

        // Trim history to HISTORY_KEEP newest rows.
        Db::execute(
            'DELETE FROM pin_history
             WHERE user_id = :u
               AND id NOT IN (
                   SELECT id FROM pin_history
                   WHERE user_id = :u2
                   ORDER BY changed_at DESC
                   LIMIT ' . (int) self::HISTORY_KEEP . '
               )',
            [':u' => $userId, ':u2' => $userId],
        );

        return $hash;
    }

    private static function isStraightRun(string $pin): bool
    {
        $up = true;
        $down = true;
        for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
            $d = (int) $pin[$i] - (int) $pin[$i - 1];
            if ($d !== 1)  $up = false;
            if ($d !== -1) $down = false;
        }
        return $up || $down;
    }
}
