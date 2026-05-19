<?php
declare(strict_types=1);

namespace CPHC;

/*
 * Single entry point for audit_log INSERTs.
 *
 * The HMAC chain is computed by the Postgres BEFORE-INSERT trigger
 * (db/triggers/audit_log_immutable.sql).  Our responsibility:
 *
 *   1. Canonicalise before_value / after_value so JSONB serialisation is
 *      stable across PHP versions and locales.  Recursive ksort matches the
 *      old Laravel AuditWriter exactly so the chain remains verifiable
 *      across the migration.
 *   2. Default ip / user_agent from the current request.
 *   3. Keep this the ONE grep-able call site.  Anything that writes to
 *      audit_log without going through here is a bug.
 *
 * The trigger needs the per-session GUC cphc.audit_secret to be set, which
 * Db::pdo() does on first connection.
 */
final class Audit
{
    public static function write(
        ?int $userId,
        string $action,
        string $entityType = 'System',
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        $ip = self::clientIp();
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255)
            : null;

        $sql = "INSERT INTO audit_log
                  (timestamp, user_id, action_type, entity_type, entity_id,
                   before_value, after_value, reason, ip_address, user_agent)
                VALUES
                  (CURRENT_TIMESTAMP, :user_id, :action, :etype, :eid,
                   CAST(:before AS JSONB), CAST(:after AS JSONB),
                   :reason, :ip, :ua)";

        Db::execute($sql, [
            ':user_id' => $userId,
            ':action'  => $action,
            ':etype'   => $entityType,
            ':eid'     => $entityId,
            ':before'  => $before !== null ? json_encode(self::canonicalize($before), JSON_THROW_ON_ERROR) : null,
            ':after'   => $after  !== null ? json_encode(self::canonicalize($after),  JSON_THROW_ON_ERROR) : null,
            ':reason'  => $reason,
            ':ip'      => $ip,
            ':ua'      => $ua,
        ]);
    }

    /** Recursive ksort.  Matches the old Laravel AuditWriter byte-for-byte. */
    private static function canonicalize(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $payload[$k] = self::canonicalize($v);
            }
        }
        return $payload;
    }

    /** Honour X-Forwarded-For if present (Caddy sets it).  Strip to /45. */
    public static function clientIp(): ?string
    {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($xff) && $xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '') {
                return substr($first, 0, 45);
            }
        }
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        return $remote ? substr((string) $remote, 0, 45) : null;
    }
}
