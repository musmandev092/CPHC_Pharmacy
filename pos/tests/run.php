<?php
declare(strict_types=1);

/*
 * tests/run.php — bare-PHP test runner.  Zero composer dependencies.
 *
 * Run inside the container so the bcmath / pdo_pgsql extensions are present:
 *
 *   docker compose exec app php /var/www/tests/run.php
 *
 * Exits 0 if all tests pass, 1 otherwise.  Each `it()` is independent; a
 * failure in one doesn't stop the others (so a fresh run reports the full
 * scope of regressions, not just the first).
 *
 * Coverage:
 *   - Money: bcmath helpers, HALF_UP rounding, formatting
 *   - SaleCalculator: line subtotal, change
 *   - CostBlender: FOC blend, MRP per base, line total, invalid inputs
 *   - QrParser: GS1, GTIN, URL, free-text, name search
 *   - Fefo: FEFO sort, quarantine/expired skip, exact-match, shortfall
 *
 * Tests that need a database connection are intentionally NOT in this
 * runner — they belong in an integration suite that's outside Step 10's
 * scope.
 */

require_once __DIR__ . '/../backend/e.php';

require_once __DIR__ . '/../backend/Money.php';
require_once __DIR__ . '/../backend/services/SaleCalculator.php';
require_once __DIR__ . '/../backend/services/CostBlender.php';
require_once __DIR__ . '/../backend/services/QrParser.php';
require_once __DIR__ . '/../backend/services/Fefo.php';

use CPHC\Money;
use CPHC\Services\SaleCalculator;
use CPHC\Services\CostBlender;
use CPHC\Services\QrParser;
use CPHC\Services\Fefo;

$total = 0; $passed = 0; $failed = 0; $failures = [];

function it(string $name, callable $fn): void
{
    global $total, $passed, $failed, $failures;
    $total++;
    try {
        $fn();
        $passed++;
        echo "  ✓ $name\n";
    } catch (\Throwable $e) {
        $failed++;
        $failures[] = "  ✗ $name: " . $e->getMessage();
        echo "  ✗ $name\n      " . $e->getMessage() . "\n";
    }
}

function assertEq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            ($msg ? "$msg — " : '') . 'expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}
function assertThrows(callable $fn, string $expectedClass = \Throwable::class, ?string $needle = null): void
{
    try { $fn(); }
    catch (\Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new \RuntimeException("Expected $expectedClass, got " . $e::class);
        }
        if ($needle !== null && !str_contains($e->getMessage(), $needle)) {
            throw new \RuntimeException("Expected message to contain '$needle', got '" . $e->getMessage() . "'");
        }
        return;
    }
    throw new \RuntimeException("Expected throw of $expectedClass — nothing thrown.");
}

// ── Money ──────────────────────────────────────────────────────────────
echo "\n[ Money ]\n";
it('add 0.1 + 0.2 = 0.30 at scale 2', function () {
    assertEq('0.30', Money::round(Money::add('0.1', '0.2'), 2));
});
it('mul 3 × 75.00 = 225.00', function () {
    assertEq('225.00', Money::round(Money::mul('3', '75.00'), 2));
});
it('HALF_UP rounds 12.345 × 3 → 37.04', function () {
    assertEq('37.04', Money::round(Money::mul('12.345', '3'), 2));
});
it('round negative HALF_UP works', function () {
    assertEq('-0.50', Money::round('-0.504', 2));
    assertEq('-0.51', Money::round('-0.505', 2));
});
it('div by zero throws', function () {
    assertThrows(fn () => Money::div('1', '0'), \DivisionByZeroError::class);
});
it('format adds thousands separator', function () {
    assertEq('1,234,567.89', Money::fmt('1234567.89'));
});

// ── SaleCalculator ─────────────────────────────────────────────────────
echo "\n[ SaleCalculator ]\n";
it('lineSubtotal: 3 × 75.00 = 225.00', function () {
    assertEq('225.00', SaleCalculator::lineSubtotal(3, '75.00'));
});
it('lineSubtotal rounds HALF_UP at scale 2', function () {
    assertEq('37.04', SaleCalculator::lineSubtotal(3, '12.345'));
});
it('lineTotal = subtotal - discount + tax', function () {
    assertEq('95.50', SaleCalculator::lineTotal('100.00', '10.00', '5.50'));
});
it('change = tendered - grand_total, never negative', function () {
    assertEq('0.00', SaleCalculator::change('500.00', '555.19'));
    assertEq('44.81', SaleCalculator::change('600.00', '555.19'));
});
it('sum rounds at the end', function () {
    assertEq('470.50', SaleCalculator::sum(['150.00', '320.50']));
});

// ── CostBlender ────────────────────────────────────────────────────────
echo "\n[ CostBlender ]\n";
it('legacy formula: 10 paid + 2 FOC × 100, cost 1000 → 8.3333', function () {
    assertEq('8.3333', CostBlender::blendedCostPerBaseUnit(10, 2, '1000', 100));
});
it('no FOC: (1200 × 1) / (1 × 24) → 50.0000', function () {
    assertEq('50.0000', CostBlender::blendedCostPerBaseUnit(1, 0, '1200', 24));
});
it('FOC alone (paid=0) → 0.0000', function () {
    assertEq('0.0000', CostBlender::blendedCostPerBaseUnit(0, 5, '1000', 10));
});
it('zero total base units → 0.0000', function () {
    assertEq('0.0000', CostBlender::blendedCostPerBaseUnit(0, 0, '1000', 10));
});
it('mrpPerBaseUnit: 320.50 / 10 → 32.0500', function () {
    assertEq('32.0500', CostBlender::mrpPerBaseUnit('320.50', 10));
});
it('lineTotal: 10 × 1000 → 10000.00', function () {
    assertEq('10000.00', CostBlender::lineTotal(10, '1000'));
});
it('negative paid_qty rejected', function () {
    assertThrows(fn () => CostBlender::blendedCostPerBaseUnit(-1, 0, '100', 10), \InvalidArgumentException::class);
});
it('zero units_per_purchase rejected', function () {
    assertThrows(fn () => CostBlender::blendedCostPerBaseUnit(1, 0, '100', 0), \InvalidArgumentException::class);
});

// ── QrParser ───────────────────────────────────────────────────────────
echo "\n[ QrParser ]\n";
it('bare 13-digit EAN → GTIN', function () {
    $r = QrParser::parse('5012345678900');
    assertEq('GTIN', $r['type']);
    assertEq('5012345678900', $r['gtin']);
});
it('GS1 parses GTIN + expiry + batch', function () {
    $r = QrParser::parse('010501234567890617280430' . '10' . 'BX2024A');
    assertEq('GS1', $r['type']);
    assertEq('05012345678906', $r['gtin']);
    assertEq('2028-04-30', $r['expiry']);
    assertEq('BX2024A', $r['batch']);
});
it('GS1 DD=00 means last day of month', function () {
    assertEq('2027-02-28', QrParser::gs1DateToIso('270200'));
    assertEq('2028-02-29', QrParser::gs1DateToIso('280200')); // leap
});
it('YY >= 50 maps to 19YY', function () {
    assertEq('1950-12-31', QrParser::gs1DateToIso('501231'));
});
it('URL is rejected', function () {
    assertEq('URL', QrParser::parse('https://example.com/123')['type']);
});
it('free-text with batch + DD/MM/YYYY expiry', function () {
    $r = QrParser::parse('MRP: Rs. 9700/box  Batch No.: 20231214  Exp.Date: 13/12/2028');
    assertEq('FREE_TEXT', $r['type']);
    assertEq('20231214', $r['batch']);
    assertEq('2028-12-13', $r['expiry']);
});
it('free-text MM/YYYY → last day of month', function () {
    $r = QrParser::parse('Batch No: ABCD  Exp Date: 02/2027');
    assertEq('2027-02-28', $r['expiry']);
});
it('plain text becomes name-search query', function () {
    $r = QrParser::parse('panadol extra');
    assertEq('TEXT', $r['type']);
    assertEq('panadol extra', $r['query']);
});

// ── Fefo ────────────────────────────────────────────────────────────────
echo "\n[ Fefo ]\n";
function mkBatch(int $id, string $expiry, int $current, bool $quarantined = false, bool $expired = false): array {
    return ['id' => $id, 'expiry_date' => $expiry, 'current_qty' => $current,
            'is_quarantined' => $quarantined, 'is_expired' => $expired];
}
it('allocates from earliest expiry first', function () {
    $batches = [
        mkBatch(1, '2030-12-31', 10),
        mkBatch(2, '2026-06-30', 5),  // earliest
        mkBatch(3, '2028-01-31', 7),
    ];
    $a = Fefo::allocate($batches, 9);
    assertEq(2, count($a));
    assertEq(2, $a[0]['batch']['id']);
    assertEq(5, $a[0]['deduction']);
    assertEq(3, $a[1]['batch']['id']);
    assertEq(4, $a[1]['deduction']);
});
it('quarantined batches are skipped', function () {
    $a = Fefo::allocate([
        mkBatch(1, '2026-06-30', 5, quarantined: true),
        mkBatch(2, '2027-06-30', 5),
    ], 3);
    assertEq(2, $a[0]['batch']['id']);
});
it('expired batches are skipped', function () {
    $a = Fefo::allocate([
        mkBatch(1, '2030-12-31', 5),
        mkBatch(2, '2025-01-01', 100, expired: true),
    ], 3);
    assertEq(1, $a[0]['batch']['id']);
});
it('shortfall throws with the remaining count', function () {
    assertThrows(fn () => Fefo::allocate([mkBatch(1, '2027-06-30', 3)], 10),
        \RuntimeException::class, 'Shortfall: 7');
});
it('exact-match allocation across batches', function () {
    $a = Fefo::allocate([
        mkBatch(1, '2027-06-30', 4),
        mkBatch(2, '2028-06-30', 6),
    ], 10);
    assertEq(2, count($a));
    assertEq(4, $a[0]['deduction']);
    assertEq(6, $a[1]['deduction']);
});
it('zero qty returns empty allocation', function () {
    assertEq([], Fefo::allocate([mkBatch(1, '2030-12-31', 10)], 0));
});
it('assertSortedByExpiry flags out-of-order', function () {
    assertThrows(fn () => Fefo::assertSortedByExpiry([
        mkBatch(1, '2030-01-01', 5),
        mkBatch(2, '2026-01-01', 5),
    ]), \RuntimeException::class, 'FEFO ordering violation');
});

echo "\n";
echo "═════════════════════════════════════════════════════\n";
echo "  $passed passed, $failed failed, $total total\n";
echo "═════════════════════════════════════════════════════\n";

exit($failed === 0 ? 0 : 1);
