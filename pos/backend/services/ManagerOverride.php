<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;
use CPHC\RateLimit;

/*
 * Manager-PIN override.  Used at the POS for discount-authorisation, void,
 * narcotic-witness, and other operations that require a second MANAGER or
 * ADMIN to confirm.
 *
 * Rate-limited per PIN-attempt to defeat brute force.  Audit row written on
 * both success and failure.  Returns the manager's user id on success, null
 * (with a per-request error message) on failure.
 */
final class ManagerOverride
{
    /**
     * @param string $action  Short label like 'discount', 'void', 'narcotic_witness'.
     * @return array{ok: bool, user_id?: int, full_name?: string, error?: string}
     */
    public static function verify(string $pin, string $action, ?int $forbidUserId = null): array
    {
        $pin = trim($pin);
        if ($pin === '') {
            return ['ok' => false, 'error' => 'Manager PIN required.'];
        }

        $ip  = Audit::clientIp() ?? '0.0.0.0';
        $key = 'override:' . $action . ':' . $ip;

        $c = RateLimit::check($key);
        if (!$c['allowed']) {
            Audit::write(null, 'OVERRIDE_RATE_LIMITED', 'Override', null, null, ['action' => $action]);
            return ['ok' => false, 'error' => 'Too many attempts. Wait a minute and try again.'];
        }

        // Pull all active managers/admins; bcrypt-verify against each.
        // pin_hash is not indexable so we can't lookup directly by PIN —
        // the dataset is small (a handful of managers per pharmacy) so a
        // linear bcrypt scan is acceptable.  An attacker is rate-limited
        // long before the scan cost matters.
        $candidates = Db::fetchAll(
            "SELECT id, full_name, role, pin_hash
               FROM users
              WHERE role IN ('MANAGER', 'ADMIN')
                AND is_active = TRUE
                AND deleted_at IS NULL"
            . ($forbidUserId !== null ? ' AND id <> :forbid' : ''),
            $forbidUserId !== null ? [':forbid' => $forbidUserId] : [],
        );

        foreach ($candidates as $u) {
            if (password_verify($pin, (string) $u['pin_hash'])) {
                RateLimit::reset($key);
                Audit::write(
                    (int) $u['id'],
                    'OVERRIDE_GRANTED',
                    'Override',
                    null,
                    null,
                    ['action' => $action, 'role' => (string) $u['role']],
                );
                return [
                    'ok'        => true,
                    'user_id'   => (int) $u['id'],
                    'full_name' => (string) $u['full_name'],
                ];
            }
        }

        Audit::write(null, 'OVERRIDE_DENIED', 'Override', null, null,
            ['action' => $action, 'forbid_user_id' => $forbidUserId]);
        return ['ok' => false, 'error' => 'Manager PIN not recognised.'];
    }
}
