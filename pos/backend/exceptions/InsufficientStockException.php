<?php
declare(strict_types=1);

namespace CPHC\Exceptions;

/**
 * Thrown by Fefo::allocate() when a medicine doesn't have enough live stock
 * (after quarantine + expiry filters) to satisfy the requested base-unit qty.
 *
 * Carries the shortfall so callers (Sale::commit, UI flash) can present a
 * useful message without re-parsing the exception text.
 */
final class InsufficientStockException extends \RuntimeException
{
    public function __construct(
        public readonly int $medicineId,
        public readonly int $requestedBaseUnits,
        public readonly int $availableBaseUnits,
        ?\Throwable $previous = null,
    ) {
        $shortfall = $requestedBaseUnits - $availableBaseUnits;
        parent::__construct(
            "Insufficient stock for medicine #{$medicineId}. Requested {$requestedBaseUnits}, available {$availableBaseUnits} (shortfall: {$shortfall} units).",
            0,
            $previous,
        );
    }

    public function shortfall(): int
    {
        return $this->requestedBaseUnits - $this->availableBaseUnits;
    }
}
