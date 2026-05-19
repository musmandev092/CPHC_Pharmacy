<?php
declare(strict_types=1);

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Csv;

Auth::requireRole(Auth::ROLE_MANAGER, Auth::ROLE_ADMIN);

$from = $_GET['from'] ?? (new \DateTimeImmutable('-90 days'))->format('Y-m-d');
$to   = $_GET['to']   ?? (new \DateTimeImmutable('now'))->format('Y-m-d');

$rows = Db::fetchAll(
    "SELECT s.id, s.receipt_number, s.sold_at::text AS sold_at,
            s.doctor_name, s.patient_name, s.patient_phone,
            s.prescriber_license_number, s.has_controlled_drug,
            uw.full_name AS witness_name,
            uc.full_name AS cashier_name,
            STRING_AGG(m.brand_name || ' (' || m.controlled_schedule::text || ')', ', ') AS items
       FROM sales s
       JOIN users uc      ON uc.id = s.cashier_id
  LEFT JOIN users uw      ON uw.id = s.narcotic_witness_user_id
       JOIN sale_items si ON si.sale_id = s.id
       JOIN medicines m   ON m.id = si.medicine_id
      WHERE s.sold_at::date BETWEEN :f AND :t
        AND (s.has_controlled_drug = TRUE OR m.controlled_schedule <> 'NONE')
      GROUP BY s.id, uw.full_name, uc.full_name
      ORDER BY s.sold_at DESC LIMIT 500",
    [':f' => $from, ':t' => $to],
);

if (($_GET['export'] ?? '') === 'csv') {
    Csv::stream("compliance_{$from}_to_{$to}",
        ['Receipt', 'When', 'Items', 'Doctor', 'Prescriber lic #', 'Patient', 'Phone', 'Cashier', 'Witness'],
        array_map(fn ($r) => [
            $r['receipt_number'], substr((string) $r['sold_at'], 0, 19), $r['items'],
            $r['doctor_name'], $r['prescriber_license_number'],
            $r['patient_name'], $r['patient_phone'],
            $r['cashier_name'], $r['witness_name'] ?? '',
        ], $rows));
    return;
}

$ic = static fn (string $d, int $size = 16) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Compliance report</h1>
        <p class="page-header__sub">Every controlled-drug sale in the period, with doctor, patient, prescriber license, and witness.</p>
    </div>
    <div class="page-header__actions">
        <a class="btn-secondary" href="/reports">← Reports</a>
        <a class="btn" href="/reports/compliance?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv">
            <?= $ic('<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>') ?>
            Download CSV
        </a>
    </div>
</div>

<section class="card mb-4">
    <form method="get" action="/reports/compliance" class="card__body" style="padding:18px 22px;">
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
        <h3>No controlled-drug sales in this range</h3>
        <p>Widen the date range or come back later.</p>
    </div>
<?php else: ?>
    <section class="card" style="padding:0; overflow:hidden;">
        <table>
            <thead><tr><th>Receipt</th><th>When</th><th>Items</th><th>Doctor &amp; Rx</th><th>Patient</th><th>Cashier · Witness</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><code style="font-size:12px;"><?= e($r['receipt_number']) ?></code></td>
                    <td class="muted nowrap"><?= e(substr((string) $r['sold_at'], 0, 19)) ?></td>
                    <td><?= e($r['items']) ?></td>
                    <td>
                        <?= e($r['doctor_name'] ?? '—') ?>
                        <br><small class="muted">Lic: <?= e($r['prescriber_license_number'] ?? '—') ?></small>
                    </td>
                    <td>
                        <?= e($r['patient_name'] ?? '—') ?>
                        <?php if ($r['patient_phone']): ?><br><small class="muted"><?= e($r['patient_phone']) ?></small><?php endif; ?>
                    </td>
                    <td>
                        <?= e($r['cashier_name']) ?>
                        <br><small class="muted">witness: <strong><?= e($r['witness_name'] ?? '—') ?></strong></small>
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
    'title' => 'Compliance', 'body' => $body, 'active' => 'reports',
    'flash_success' => Session::flash('success'), 'flash_error' => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
