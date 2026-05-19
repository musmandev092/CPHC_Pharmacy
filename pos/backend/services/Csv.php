<?php
declare(strict_types=1);

namespace CPHC\Services;

/*
 * Hand-rolled RFC 4180 CSV writer.  Replaces league/csv (3 transitive
 * deps) with ~40 lines.  Streams rows directly to php://output so memory
 * stays O(1) regardless of result set size.
 *
 * Quoting rules:
 *   - Any field containing comma, double-quote, or newline is wrapped in "..."
 *   - Inner " is escaped by doubling: ""
 *   - UTF-8 BOM emitted first so Excel opens the file with PKR symbols intact.
 */
final class Csv
{
    /**
     * Send a CSV download.  Caller passes a generator/iterable so we never
     * buffer the whole result set in memory.
     *
     * @param string                                       $filename       base name without `.csv`
     * @param list<string>                                 $header
     * @param iterable<int, array<int, string|int|float|null>> $rows
     */
    public static function stream(string $filename, array $header, iterable $rows): void
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $filename) ?: 'export';
        $stamped = $safe . '_' . date('Ymd_His') . '.csv';

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $stamped . '"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        self::writeRow($out, $header);
        foreach ($rows as $row) {
            self::writeRow($out, $row);
        }
        fclose($out);
    }

    /** @param resource $fh @param array<int,mixed> $fields */
    private static function writeRow($fh, array $fields): void
    {
        $parts = [];
        foreach ($fields as $f) {
            $s = $f === null ? '' : (string) $f;
            if (strpbrk($s, ",\"\r\n") !== false) {
                $s = '"' . str_replace('"', '""', $s) . '"';
            }
            $parts[] = $s;
        }
        fwrite($fh, implode(',', $parts) . "\r\n");
    }
}
