<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;
use CPHC\Money;

/*
 * Manager-driven reconciliation — CLOSED → RECONCILED.
 *
 * Port of backend/app/Services/Sessions/Reconciler.php.  Optionally re-records
 * counted_cash if the manager recounted; appends a note prefixed with
 * "[reconciled]" so the closing cashier's note isn't lost.
 */
final class Reconciler
{
    /**
     * Pure helper: expected_cash = opening_float + Σ(CASH grand_total) − refundsPaid.
     * Used both at close time (via cashier_sessions.total_cash_sales) and from
     * any audit view that needs to re-derive the expected.
     *
     * @param iterable<array{payment_mode:string, grand_total:string}> $sales
     */
    public static function expectedCash(string $openingFloat, iterable $sales, string $refundsPaid = '0'): string
    {
        $cash = Money::zero(Money::SCALE_WORK);
        foreach ($sales as $sale) {
            if (($sale['payment_mode'] ?? '') === 'CASH') {
                $cash = Money::add($cash, (string) ($sale['grand_total'] ?? '0'));
            }
        }
        return Money::round(Money::sub(Money::add($openingFloat, $cash), $refundsPaid), 2);
    }

    public static function reconcile(int $sessionId, int $managerId, ?string $recountedCash = null, ?string $notes = null): array
    {
        $s = Db::fetchOne(
            "SELECT id, status::text AS status,
                    expected_cash_in_drawer::text AS expected_cash_in_drawer,
                    counted_cash::text AS counted_cash,
                    cash_variance::text AS cash_variance,
                    notes
               FROM cashier_sessions WHERE id = :id",
            [':id' => $sessionId],
        );
        if ($s === null) {
            throw new \RuntimeException('Session not found.');
        }
        if ($s['status'] !== 'CLOSED') {
            throw new \RuntimeException('Session must be CLOSED before reconciliation (current: ' . $s['status'] . ').');
        }

        return Db::transaction(function () use ($s, $managerId, $recountedCash, $notes) {
            $counted  = $s['counted_cash'];
            $variance = $s['cash_variance'];

            if ($recountedCash !== null && $recountedCash !== '') {
                $counted  = Money::round($recountedCash, 2);
                $variance = Money::round(Money::sub($counted, $s['expected_cash_in_drawer'] ?? '0'), 2);
            }

            $newNotes = $notes !== null && $notes !== ''
                ? trim((($s['notes'] ?? '') !== '' ? $s['notes'] . "\n" : '') . '[reconciled] ' . $notes)
                : $s['notes'];

            Db::execute(
                "UPDATE cashier_sessions
                    SET status = 'RECONCILED',
                        counted_cash = :cnt,
                        cash_variance = :var,
                        notes = :notes,
                        updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id",
                [':cnt' => $counted, ':var' => $variance, ':notes' => $newNotes, ':id' => (int) $s['id']],
            );

            Audit::write($managerId, 'SESSION_RECONCILED', 'cashier_sessions', (int) $s['id'], null,
                ['counted_cash' => $counted, 'expected' => $s['expected_cash_in_drawer'] ?? '0', 'variance' => $variance]);

            return ['id' => (int) $s['id'], 'counted_cash' => $counted, 'variance' => $variance];
        });
    }
}
