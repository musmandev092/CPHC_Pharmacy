<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Audit;
use CPHC\Db;
use CPHC\Money;

/*
 * Z-Report — signed end-of-shift archive.
 *
 * Port of backend/app/Services/Reports/ZReportPdf.php with the PDF step
 * replaced by a printable HTML page (pages/sessions/z-report.php).  The user
 * Ctrl+P's it for a paper copy or "Save as PDF".  This drops DomPDF entirely.
 *
 * The PAYLOAD + SIGNATURE side is preserved byte-for-byte:
 *   - exact same key set as the Laravel version
 *   - ksort applied
 *   - json_encode with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
 *   - hmac_sha256 with cphc.audit_secret (the same key the audit chain uses)
 *
 * Archived to the append-only z_report_archive table (Phase 5 trigger
 * prevents UPDATE / DELETE).  Tampering invalidates the signature.
 */
final class ZReport
{
    /**
     * Compute payload, HMAC-sign, INSERT into z_report_archive.
     * @return array{archive_id:int, payload:array<string,mixed>, signature:string}
     */
    public static function generate(int $sessionId, int $generatedBy): array
    {
        [$payload, $sales] = self::buildPayload($sessionId);
        $signature = self::sign($payload);

        $row = Db::fetchOne(
            "INSERT INTO z_report_archive (session_id, generated_at, generated_by, payload, signature_hex)
             VALUES (:s, CURRENT_TIMESTAMP, :g, CAST(:p AS JSONB), :sig)
             RETURNING id",
            [
                ':s'   => $sessionId,
                ':g'   => $generatedBy,
                ':p'   => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ':sig' => $signature,
            ],
        );

        Audit::write($generatedBy, 'Z_REPORT_GENERATED', 'cashier_sessions', $sessionId, null,
            ['signature_hex' => $signature, 'archive_id' => (int) $row['id']]);

        return [
            'archive_id' => (int) $row['id'],
            'payload'    => $payload,
            'signature'  => $signature,
        ];
    }

    /**
     * Reconstruct the payload + signature for a session.  Used by the public
     * /zreport/verify/{id} page and by the printable Z-report page.
     *
     * @return array{archive_id:?int, payload:array<string,mixed>, signature:string, verified:bool, sales:array<int,array<string,mixed>>}
     */
    public static function loadForSession(int $sessionId): array
    {
        $archive = Db::fetchOne(
            'SELECT id, payload::text AS payload_json, signature_hex
               FROM z_report_archive WHERE session_id = :s
              ORDER BY generated_at DESC LIMIT 1',
            [':s' => $sessionId],
        );

        if ($archive === null) {
            [$payload, $sales] = self::buildPayload($sessionId);
            return [
                'archive_id' => null,
                'payload'    => $payload,
                'signature'  => '',
                'verified'   => false,
                'sales'      => $sales,
            ];
        }

        $payload = (array) json_decode((string) $archive['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $stored  = (string) $archive['signature_hex'];

        // Re-compute and compare to detect tampering with the payload row.
        $recomputed = self::sign($payload);
        $verified   = $stored !== '' && hash_equals($stored, $recomputed);

        // Also pull the sales list for display.
        $sales = Db::fetchAll(
            "SELECT id, receipt_number, sold_at::text AS sold_at,
                    grand_total::text AS grand_total,
                    payment_mode::text AS payment_mode,
                    status::text AS status
               FROM sales
              WHERE cashier_session_id = :s
              ORDER BY sold_at",
            [':s' => $sessionId],
        );

        return [
            'archive_id' => (int) $archive['id'],
            'payload'    => $payload,
            'signature'  => $stored,
            'verified'   => $verified,
            'sales'      => $sales,
        ];
    }

    /** Build the deterministic payload + the sales list it counts. */
    private static function buildPayload(int $sessionId): array
    {
        $session = Db::fetchOne(
            "SELECT s.id, s.cashier_id,
                    s.opened_at::text AS opened_at,
                    s.closed_at::text AS closed_at,
                    s.opening_float::text AS opening_float,
                    s.counted_cash::text AS counted_cash,
                    s.expected_cash_in_drawer::text AS expected_cash_in_drawer,
                    s.cash_variance::text AS cash_variance
               FROM cashier_sessions s
              WHERE s.id = :id",
            [':id' => $sessionId],
        );
        if ($session === null) {
            throw new \RuntimeException('Session not found for Z-report.');
        }

        $sales = Db::fetchAll(
            "SELECT receipt_number, payment_mode::text AS payment_mode,
                    grand_total::text AS grand_total, status::text AS status,
                    sold_at::text AS sold_at
               FROM sales
              WHERE cashier_session_id = :s
              ORDER BY sold_at",
            [':s' => $sessionId],
        );

        $cashCount = 0; $cardCount = 0;
        $cashTotal = Money::zero(Money::SCALE_WORK);
        $cardTotal = Money::zero(Money::SCALE_WORK);
        $allTotal  = Money::zero(Money::SCALE_WORK);
        $receipts  = [];
        foreach ($sales as $s) {
            $receipts[] = (string) $s['receipt_number'];
            $allTotal = Money::add($allTotal, (string) $s['grand_total']);
            if ($s['payment_mode'] === 'CASH') {
                $cashCount++;
                $cashTotal = Money::add($cashTotal, (string) $s['grand_total']);
            } elseif ($s['payment_mode'] === 'CARD') {
                $cardCount++;
                $cardTotal = Money::add($cardTotal, (string) $s['grand_total']);
            }
        }

        $pharmacyName = (string) (Db::fetchOne(
            "SELECT value FROM settings WHERE key = 'pharmacy_name'"
        )['value'] ?? 'CARE POINT PHARMACY');

        $payload = [
            'all_total'              => Money::round($allTotal, 2),
            'card_sales_count'       => $cardCount,
            'card_total'             => Money::round($cardTotal, 2),
            'cash_sales_count'       => $cashCount,
            'cash_total'             => Money::round($cashTotal, 2),
            'cashier_id'             => (int) $session['cashier_id'],
            'closed_at'              => $session['closed_at']  ? self::iso8601($session['closed_at'])  : null,
            'counted_cash'           => (string) ($session['counted_cash'] ?? '0'),
            'expected_cash_in_drawer'=> (string) ($session['expected_cash_in_drawer'] ?? '0'),
            'generated_at'           => self::iso8601((new \DateTimeImmutable('now'))->format('c')),
            'opened_at'              => $session['opened_at']  ? self::iso8601($session['opened_at'])  : null,
            'opening_float'          => (string) $session['opening_float'],
            'pharmacy_name'          => $pharmacyName,
            'sale_receipts'          => $receipts,
            'session_id'             => (int) $session['id'],
            'variance'               => (string) ($session['cash_variance'] ?? '0'),  // payload key kept 'variance' for compatibility
        ];
        // ksort applied implicitly above (keys are already alphabetical); ksort
        // defensively in case a later edit reorders.
        ksort($payload);

        return [$payload, $sales];
    }

    public static function sign(array $payload): string
    {
        $secret = self::secret();
        if ($secret === '') return '';
        // ksort recursively so the signature is reproducible regardless of
        // the order in which keys arrive (which differs between PHP-encoded
        // input and Postgres jsonb::text round-trip — jsonb sorts by key
        // length, not alphabetically, and json_decode preserves THAT order).
        self::ksortRecursive($payload);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256', (string) $canonical, $secret);
    }

    private static function ksortRecursive(array &$arr): void
    {
        // Only sort associative arrays; preserve order in lists.
        if (!array_is_list($arr)) {
            ksort($arr);
        }
        foreach ($arr as &$v) {
            if (is_array($v)) self::ksortRecursive($v);
        }
    }

    /** Reads the same per-session GUC used by the audit_log trigger. */
    private static function secret(): string
    {
        try {
            $row = Db::fetchOne("SELECT current_setting('cphc.audit_secret', true) AS s");
            return (string) ($row['s'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** Normalise a TIMESTAMPTZ::text to strict ISO-8601 with timezone offset. */
    private static function iso8601(string $ts): string
    {
        try {
            return (new \DateTimeImmutable($ts))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return $ts;
        }
    }
}
