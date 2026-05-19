<?php
declare(strict_types=1);

/**
 * DRAP narcotic register — every NARCOTIC dispense with witness, doctor,
 * patient, prescriber license #, and adjudication trail.  Printable + CSV.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Csv;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);

$from = $_GET['from'] ?? (new \DateTimeImmutable('-180 days'))->format('Y-m-d');
$to   = $_GET['to']   ?? (new \DateTimeImmutable('now'))->format('Y-m-d');

$rows = Db::fetchAll(
    "SELECT s.id, s.receipt_number, s.sold_at::text AS sold_at,
            s.doctor_name, s.prescriber_license_number,
            s.patient_name, s.patient_phone, s.patient_address,
            uc.full_name AS cashier_name,
            uw.full_name AS witness_name,
            s.narcotic_witness_at::text AS witness_at,
            STRING_AGG(m.brand_name || ' × ' || si.qty_in_base_units || ' ' || m.base_unit, '; ') AS items
       FROM sales s
       JOIN users uc       ON uc.id = s.cashier_id
  LEFT JOIN users uw       ON uw.id = s.narcotic_witness_user_id
       JOIN sale_items si  ON si.sale_id = s.id
       JOIN medicines m    ON m.id = si.medicine_id
      WHERE s.sold_at::date BETWEEN :f AND :t
        AND m.controlled_schedule = 'NARCOTIC'
      GROUP BY s.id, uc.full_name, uw.full_name
      ORDER BY s.sold_at DESC",
    [':f' => $from, ':t' => $to],
);

if (($_GET['export'] ?? '') === 'csv') {
    Csv::stream("narcotic_register_{$from}_to_{$to}",
        ['Receipt','When','Items','Doctor','Prescriber lic #','Patient','Patient phone','Patient address','Cashier','Witness','Witness at'],
        array_map(fn ($r) => [
            $r['receipt_number'], substr((string) $r['sold_at'], 0, 19), $r['items'],
            $r['doctor_name'], $r['prescriber_license_number'],
            $r['patient_name'], $r['patient_phone'], $r['patient_address'],
            $r['cashier_name'], $r['witness_name'] ?? '',
            $r['witness_at'] ? substr((string) $r['witness_at'], 0, 19) : '',
        ], $rows));
    return;
}

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Narcotic register</h1>
        <p class="page-header__sub">DRAP-compliant ledger of every narcotic-schedule dispense — prescriber license, patient details, and witness recorded.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/reports">← Reports</a>
        <button type="button" class="btn-secondary" data-print-page>
            <?= $ic('<path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/>') ?>
            Print
        </button>
        <a class="btn" href="/reports/narcotic-register?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv">
            <?= $ic('<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>') ?>
            CSV
        </a>
    </div>
</div>

<section class="card mb-4">
    <form method="get" action="/reports/narcotic-register" class="card__body" style="padding:18px 22px;">
        <div style="display:flex; flex-wrap:wrap; gap:12px 20px; align-items:end;">
            <label style="margin:0; min-width:160px;"><span>From</span><input type="date" name="from" value="<?= e($from) ?>"></label>
            <label style="margin:0; min-width:160px;"><span>To</span><input type="date" name="to" value="<?= e($to) ?>"></label>
            <button type="submit">Filter</button>
        </div>
    </form>
</section>

<?php if (count($rows) === 0): ?>
    <div class="empty-state">
        <svg class="empty-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L4 6v6c0 5 3.4 9.4 8 10 4.6-.6 8-5 8-10V6l-8-4z"/></svg>
        <h3>No narcotic dispenses in this range</h3>
        <p>The register is clean for the selected period.</p>
    </div>
<?php else: ?>
    <section class="card" style="padding:0; overflow:hidden;">
        <table>
            <thead><tr><th>Receipt</th><th>When</th><th>Items</th><th>Doctor &amp; Rx</th><th>Patient</th><th>Cashier · Witness</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><code style="font-size:12px;"><?= e($r['receipt_number']) ?></code></strong></td>
                    <td class="muted nowrap"><?= e(substr((string) $r['sold_at'], 0, 19)) ?></td>
                    <td><?= e($r['items']) ?></td>
                    <td>
                        <?= e($r['doctor_name'] ?? '—') ?>
                        <br><small class="muted">Lic #: <?= e($r['prescriber_license_number'] ?? '—') ?></small>
                    </td>
                    <td>
                        <?= e($r['patient_name'] ?? '—') ?>
                        <?php if ($r['patient_phone']): ?><br><small class="muted"><?= e($r['patient_phone']) ?></small><?php endif; ?>
                        <?php if ($r['patient_address']): ?><br><small class="muted"><?= e($r['patient_address']) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?= e($r['cashier_name']) ?>
                        <br><small>witness: <strong><?= e($r['witness_name'] ?? '—') ?></strong>
                            <?php if ($r['witness_at']): ?>
                                <br><span class="muted">@ <?= e(substr((string) $r['witness_at'], 0, 19)) ?></span>
                            <?php endif; ?>
                        </small>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Narcotic register', 'body' => $body, 'active' => 'narcotic',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
