<?php
declare(strict_types=1);

namespace CPHC;

use PDO;
use PDOException;

/*
 * Single PDO factory.  One process-wide connection.
 *
 * On first connection we SET the per-session GUC `cphc.audit_secret` so the
 * audit_log BEFORE-INSERT trigger has the key it needs to compute row_hmac.
 * That is the same mechanism the old Laravel app used (scripts/seed-audit-key.sh
 * stamped it as a ROLE default via ALTER ROLE ... SET).  We set it per-session
 * here so the host secret file is the single source of truth and re-deploying
 * does not require a DB-side reseed.
 *
 * NEVER use ->query() or ->exec() on the returned PDO with user-supplied input.
 * Always prepare with named placeholders.  The CI lint enforces this.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'cphc';
        $user = getenv('DB_USER') ?: 'cphc_app';

        $passwordFile = getenv('DB_PASSWORD_FILE') ?: '';
        if ($passwordFile === '' || !is_readable($passwordFile)) {
            throw new \RuntimeException('DB_PASSWORD_FILE is unset or unreadable');
        }
        $password = trim((string) file_get_contents($passwordFile));

        $dsn = "pgsql:host={$host};port={$port};dbname={$name};options='--client_encoding=UTF8'";

        try {
            $pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
            ]);
        } catch (PDOException $e) {
            // Log details to stderr; never leak DSN/password to the client.
            error_log('DB connect failed: ' . $e->getMessage());
            throw new \RuntimeException('Database unavailable', 503, $e);
        }

        // Seed audit secret into this session so the audit_log trigger can sign
        // rows.  The secret file is bind-mounted at /run/secrets/audit_key.
        $auditKeyFile = getenv('AUDIT_KEY_FILE') ?: '';
        if ($auditKeyFile !== '' && is_readable($auditKeyFile)) {
            $secret = trim((string) file_get_contents($auditKeyFile));
            if ($secret !== '') {
                $stmt = $pdo->prepare("SELECT set_config('cphc.audit_secret', :s, false)");
                $stmt->execute([':s' => $secret]);
            }
        }

        // App timezone — keep timestamps consistent across containers.
        $tz = getenv('APP_TZ') ?: 'Asia/Karachi';
        $pdo->exec("SET TIME ZONE '" . str_replace("'", "''", $tz) . "'");

        self::$pdo = $pdo;
        return $pdo;
    }

    /** @param array<string,mixed> $params */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $params  @return array<int,array<string,mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @param array<string,mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** Wrap a closure in a transaction with retry on serialization failure. */
    public static function transaction(callable $fn, int $maxRetries = 1): mixed
    {
        $pdo = self::pdo();
        $attempt = 0;
        while (true) {
            $pdo->beginTransaction();
            try {
                $result = $fn($pdo);
                $pdo->commit();
                return $result;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // Postgres serialization_failure = 40001
                if ($e->getCode() === '40001' && $attempt < $maxRetries) {
                    $attempt++;
                    continue;
                }
                throw $e;
            } catch (\Throwable $t) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $t;
            }
        }
    }
}
