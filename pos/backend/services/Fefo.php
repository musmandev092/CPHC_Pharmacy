<?php
declare(strict_types=1);

namespace CPHC\Services;

use CPHC\Exceptions\InsufficientStockException;

/*
 * First-Expired-First-Out batch allocation.
 *
 * Port of backend/app/Services/Pos/FefoAllocator.php.  Pure function, no
 * Eloquent — caller passes pre-fetched batch rows (already filtered to one
 * medicine and locked via SELECT … FOR UPDATE in the surrounding transaction),
 * sorted by expiry_date ASC.
 *
 * Returns a list of {batch, deduction} pairs that sum to qty_needed.
 * Throws if there isn't enough valid stock.
 */
final class Fefo
{
    /**
     * @param  array<int, array<string,mixed>>  $batches  rows from `batches` (lockForUpdate'd by caller)
     * @param  int                              $medicineId  for typed exception payload
     * @return list<array{batch: array<string,mixed>, deduction: int}>
     * @throws InsufficientStockException  when stock is short of $totalQtyNeeded.
     */
    public static function allocate(array $batches, int $totalQtyNeeded, int $medicineId = 0): array
    {
        if ($totalQtyNeeded < 1) {
            return [];
        }

        $now = new \DateTimeImmutable('now');

        // Defensive filter + re-sort (caller usually already does this).
        $valid = array_values(array_filter($batches, function (array $b) use ($now) {
            if (!empty($b['is_quarantined'])) return false;
            if (!empty($b['is_expired']))    return false;
            if ((int) ($b['current_qty'] ?? 0) <= 0) return false;
            try {
                $exp = new \DateTimeImmutable((string) $b['expiry_date']);
                return $exp > $now;
            } catch (\Throwable) {
                return false;
            }
        }));
        usort($valid, fn ($a, $b) =>
            strcmp((string) $a['expiry_date'], (string) $b['expiry_date']) ?: ((int) $a['id'] - (int) $b['id'])
        );

        $available = array_sum(array_map(fn ($b) => (int) $b['current_qty'], $valid));
        $remaining = $totalQtyNeeded;
        $allocations = [];

        foreach ($valid as $batch) {
            if ($remaining <= 0) break;
            $deduction = (int) min((int) $batch['current_qty'], $remaining);
            $allocations[] = ['batch' => $batch, 'deduction' => $deduction];
            $remaining -= $deduction;
        }

        if ($remaining > 0) {
            throw new InsufficientStockException($medicineId, $totalQtyNeeded, $available);
        }
        return $allocations;
    }

    /** Defensive: assert the input is already sorted by expiry_date ASC. */
    public static function assertSortedByExpiry(array $batches): void
    {
        $previous = null;
        foreach ($batches as $b) {
            if ($previous !== null && strcmp((string) $b['expiry_date'], (string) $previous['expiry_date']) < 0) {
                throw new \RuntimeException('FEFO ordering violation: batches not sorted by expiry_date ASC');
            }
            $previous = $b;
        }
    }
}
