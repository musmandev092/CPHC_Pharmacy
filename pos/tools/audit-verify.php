<?php
declare(strict_types=1);

/*
 * tools/audit-verify.php
 *
 * Walk the audit_log HMAC chain and report any tampering.  Read-only:
 * recomputes each row's row_hmac from prev_hmac || canonical_payload and
 * compares to what's stored.
 *
 * Designed to be invoked from cron / a systemd timer / by hand:
 *
 *   docker compose exec app php /var/www/tools/audit-verify.php
 *
 * Exits 0 if the chain is intact, 1 if any row fails verification.
 *
 * The HMAC computation lives entirely inside the Postgres function
 * audit_log_compute_hmac() — we just SELECT it and compare to row_hmac.
 * That keeps the source-of-truth in one place (the trigger that signed
 * the row at INSERT time).
 */

require_once __DIR__ . '/../backend/bootstrap.php';

use CPHC\Db;

try {
    $pdo = Db::pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: cannot connect to database: " . $e->getMessage() . "\n");
    exit(2);
}

$count = (int) Db::fetchOne("SELECT count(*) AS c FROM audit_log WHERE row_hmac <> ''")['c'];
if ($count === 0) {
    echo "OK: chain is empty (no signed rows yet).\n";
    exit(0);
}

echo "Walking $count chain rows…\n";

$bad  = 0;
$prev = '';
$st = $pdo->prepare(
    "SELECT a.id, a.row_hmac, a.prev_hmac,
            audit_log_compute_hmac(:prev, a.*) AS recomputed
       FROM audit_log a
      WHERE a.id = :id"
);

// Iterate in id order; for each row, recompute using prev_hmac we observed
// from the PREVIOUS row (which is what the trigger stored on INSERT).
foreach (Db::fetchAll(
    "SELECT id, row_hmac, prev_hmac FROM audit_log
      WHERE row_hmac <> '' ORDER BY id"
) as $row) {
    $st->execute([':prev' => $prev, ':id' => (int) $row['id']]);
    $r = $st->fetch();
    if ($r['recomputed'] !== $row['row_hmac']) {
        fwrite(STDERR, "TAMPERED: row id={$row['id']}, expected={$r['recomputed']}, stored={$row['row_hmac']}\n");
        $bad++;
        // Don't break — keep counting so the operator sees the scope.
    }
    if ($row['prev_hmac'] !== $prev) {
        fwrite(STDERR, "CHAIN BREAK: row id={$row['id']}, prev_hmac stored={$row['prev_hmac']}, expected={$prev}\n");
        $bad++;
    }
    $prev = (string) $row['row_hmac'];
}

if ($bad === 0) {
    echo "OK: $count rows, every signature matches.\n";
    exit(0);
}

fwrite(STDERR, "FAIL: $bad anomaly/anomalies detected.\n");
exit(1);
