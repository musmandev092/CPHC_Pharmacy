<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;
use CPHC\Money;

/*
 * Cashier-session lifecycle.  Port of backend/app/Services/Sessions/SessionService.php.
 *
 *   currentFor($cashierId)  → the OPEN session for this cashier, or null
 *   open($cashierId, $float)→ create a new OPEN session
 *   close($id, $counted, $by) → close, compute expected/variance, status=CLOSED,
 *                              best-effort Z-report generation
 *
 * Reconciliation (CLOSED → RECONCILED) lives in Reconciler.
 */
final class Sessions
{
    public static function currentFor(int $cashierId): ?array
    {
        return Db::fetchOne(
            "SELECT id, branch_id, cashier_id, opened_at::text AS opened_at,
                    opening_float::text AS opening_float,
                    total_cash_sales::text AS total_cash_sales,
                    total_refunds_paid::text AS total_refunds_paid,
                    total_sales_count
               FROM cashier_sessions
              WHERE cashier_id = :c AND status = 'OPEN'
              ORDER BY opened_at DESC LIMIT 1",
            [':c' => $cashierId],
        );
    }

    public static function open(int $cashierId, string $openingFloat, int $branchId = 1): int
    {
        if (self::currentFor($cashierId) !== null) {
            throw new \RuntimeException('You already have an open session. Close it before opening a new one.');
        }
        $trimmed = trim($openingFloat);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $trimmed)) {
            throw new \InvalidArgumentException('Opening float must be a non-negative amount.');
        }
        $float = Money::round($trimmed, 2);
        if (Money::cmp($float, '0') < 0) {
            throw new \InvalidArgumentException('Opening float cannot be negative.');
        }

        return (int) Db::transaction(function () use ($cashierId, $float, $branchId) {
            $row = Db::fetchOne(
                "INSERT INTO cashier_sessions
                    (branch_id, cashier_id, opened_at, opening_float,
                     total_cash_sales, total_refunds_paid, total_sales_count, status)
                  VALUES (:branch, :c, CURRENT_TIMESTAMP, :float, '0', '0', 0, 'OPEN')
                  RETURNING id",
                [':branch' => $branchId, ':c' => $cashierId, ':float' => $float],
            );
            $id = (int) $row['id'];
            Audit::write($cashierId, 'SESSION_OPENED', 'cashier_sessions', $id, null,
                ['opening_float' => $float]);
            return $id;
        });
    }

    /** Close + compute expected/variance.  Best-effort Z-report. */
    public static function close(int $sessionId, string $countedCash, int $closedBy, ?string $notes = null): array
    {
        $session = Db::fetchOne(
            "SELECT id, cashier_id, status::text AS status,
                    opening_float::text AS opening_float,
                    total_cash_sales::text AS total_cash_sales,
                    total_refunds_paid::text AS total_refunds_paid
               FROM cashier_sessions WHERE id = :id",
            [':id' => $sessionId],
        );
        if ($session === null) {
            throw new \RuntimeException('Session not found.');
        }
        if ($session['status'] !== 'OPEN') {
            throw new \RuntimeException('Session is not OPEN — cannot close.');
        }
        $trimmed = trim($countedCash);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $trimmed)) {
            throw new \InvalidArgumentException('Counted cash must be a non-negative amount.');
        }
        if (Money::cmp(Money::round($trimmed, 2), '0') < 0) {
            throw new \InvalidArgumentException('Counted cash cannot be negative.');
        }

        $result = Db::transaction(function () use ($session, $countedCash, $closedBy, $notes) {
            $expected = Money::round(
                Money::sub(
                    Money::add($session['opening_float'], $session['total_cash_sales']),
                    $session['total_refunds_paid'],
                ),
                2,
            );
            $counted  = Money::round($countedCash, 2);
            $variance = Money::round(Money::sub($counted, $expected), 2);

            Db::execute(
                "UPDATE cashier_sessions
                    SET closed_at = CURRENT_TIMESTAMP,
                        expected_cash_in_drawer = :exp,
                        counted_cash = :cnt,
                        cash_variance = :var,
                        status = 'CLOSED',
                        notes = COALESCE(:notes, notes),
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id",
                [':exp' => $expected, ':cnt' => $counted, ':var' => $variance,
                 ':notes' => $notes, ':id' => (int) $session['id']],
            );
            Audit::write($closedBy, 'SESSION_CLOSED', 'cashier_sessions', (int) $session['id'], null,
                ['expected_cash' => $expected, 'counted_cash' => $counted, 'variance' => $variance]);

            return ['id' => (int) $session['id'], 'expected' => $expected, 'counted' => $counted, 'variance' => $variance];
        });

        // Best-effort Z-report archive.  Failure here MUST NOT unwind the close;
        // a session can be closed and a Z-report regenerated later if needed.
        try {
            ZReport::generate((int) $session['id'], (int) $session['cashier_id']);
        } catch (\Throwable $e) {
            error_log('Z-report generation failed for session ' . $session['id'] . ': ' . $e->getMessage());
        }

        return $result;
    }
}
