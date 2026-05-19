<?php
declare(strict_types=1);

namespace CPHC\Services;

/*
 * Barcode / QR parser — port of backend/app/Services/Barcode/QrParser.php
 * (which itself was ported from app/src/lib/qr-parser.ts in the Next.js
 * version).  Pure function, no dependencies.
 *
 * Handles four input formats:
 *   GS1     — "01" + 14-digit GTIN + AIs 17/10/21 (expiry / batch / serial)
 *   GTIN    — bare 8-18 digit barcode (EAN-13, UPC-A, etc.)
 *   URL     — bounced; rejected because no medicine has a URL barcode
 *   TEXT    — anything else → name-search fallback
 *
 * Result shape is the same as the Laravel version so the POS terminal page
 * can keep its current behaviour as-is.
 */
final class QrParser
{
    /** @return array{type:string, raw:string, gtin?:?string, batch?:?string, expiry?:?string, serial?:?string, url?:?string, query?:?string} */
    public static function parse(string $raw): array
    {
        $s = trim($raw);

        // GS1 DataMatrix: "01" + 14-digit GTIN + AIs.
        if (preg_match('/^01\d{14}/', $s)) {
            return self::parseGs1($s);
        }

        // Bare GTIN/EAN/UPC.
        if (preg_match('/^\d{8,18}$/', $s)) {
            return ['type' => 'GTIN', 'raw' => $s, 'gtin' => $s];
        }

        // URL — reject.
        if (preg_match('/^https?:\/\//i', $s)) {
            return ['type' => 'URL', 'raw' => $s, 'url' => $s];
        }

        // Free-text label.
        if (preg_match('/batch\s*no|exp(?:iry)?\.?\s*date|m\.?r\.?p/i', $s)) {
            return self::parseFreeText($s);
        }

        // Fallback: name search.
        return ['type' => 'TEXT', 'raw' => $s, 'query' => $s];
    }

    private static function parseGs1(string $s): array
    {
        $gtin = substr($s, 2, 14);
        $rest = substr($s, 16);

        $expiry = null;
        if (preg_match('/17(\d{6})/', $rest, $m)) {
            $expiry = self::gs1DateToIso($m[1]);
        }

        $batch = null;
        if (preg_match('/10([A-Z0-9]{1,20}?)(?=17\d{6}|21[A-Z0-9]|240|11\d{6}|[^A-Z0-9]|$)/i', $rest, $m)) {
            $batch = $m[1];
        }

        $serial = null;
        if (preg_match('/21([A-Z0-9]{1,20}?)(?=17\d{6}|10[A-Z0-9]|240|11\d{6}|[^A-Z0-9]|$)/i', $rest, $m)) {
            $serial = $m[1];
        }

        return [
            'type'   => 'GS1',
            'raw'    => $s,
            'gtin'   => $gtin,
            'expiry' => $expiry,
            'batch'  => $batch,
            'serial' => $serial,
        ];
    }

    /** GS1 YYMMDD → ISO YYYY-MM-DD. DD=00 means last day of the month. */
    public static function gs1DateToIso(string $yymmdd): string
    {
        $yy = (int) substr($yymmdd, 0, 2);
        $mm = (int) substr($yymmdd, 2, 2);
        $dd = (int) substr($yymmdd, 4, 2);
        $year = $yy < 50 ? 2000 + $yy : 1900 + $yy;
        if ($dd === 0) {
            $dd = (int) date('t', (int) mktime(0, 0, 0, $mm, 1, $year));
        }
        return sprintf('%04d-%02d-%02d', $year, $mm, $dd);
    }

    private static function parseFreeText(string $s): array
    {
        $batch = null;
        if (preg_match('/batch\s*no\.?\s*[:\s]+([A-Z0-9\-\/]+)/i', $s, $m)) {
            $batch = trim($m[1]);
        }

        $expiry = null;
        if (preg_match('/exp(?:iry|\.?\s*date)?[:\s.]+(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/i', $s, $m)) {
            $expiry = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if ($expiry === null && preg_match('/exp(?:iry|\.?\s*date)?[:\s.]+(\d{1,2})[\/\-](\d{4})/i', $s, $m)) {
            $year = (int) $m[2];
            $mm   = (int) $m[1];
            $last = (int) date('t', (int) mktime(0, 0, 0, $mm, 1, $year));
            $expiry = sprintf('%04d-%02d-%02d', $year, $mm, $last);
        }

        return [
            'type'   => 'FREE_TEXT',
            'raw'    => $s,
            'batch'  => $batch,
            'expiry' => $expiry,
        ];
    }
}
