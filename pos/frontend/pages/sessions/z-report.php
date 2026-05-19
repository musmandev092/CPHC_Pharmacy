<?php
declare(strict_types=1);

/**
 * Printable Z-report.  Press Ctrl+P (or "Save as PDF" in the browser) for
 * the regulator's paper copy.  The HMAC signature is embedded as text + a
 * verifyable URL so any auditor can independently confirm the document
 * hasn't been altered.
 *
 * Replaces backend's DomPDF-based ZReportPdf::generate() PDF render.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Db;
use CPHC\Session;
use CPHC\Services\ZReport;

Auth::requireLogin();
$sid = (int) ($params['id'] ?? 0);
if ($sid <= 0) {
    http_response_code(404);
    echo 'Not found';
    return;
}

$sessionRow = Db::fetchOne(
    "SELECT s.id, s.cashier_id, u.full_name AS cashier_name,
            s.opened_at::text AS opened_at, s.closed_at::text AS closed_at,
            s.opening_float::text AS opening_float,
            s.counted_cash::text AS counted_cash,
            s.expected_cash_in_drawer::text AS expected_cash_in_drawer,
            s.cash_variance::text AS cash_variance,
            s.status::text AS status
       FROM cashier_sessions s
       LEFT JOIN users u ON u.id = s.cashier_id
      WHERE s.id = :id",
    [':id' => $sid],
);
if ($sessionRow === null) {
    http_response_code(404);
    echo 'Session not found';
    return;
}

// IDOR guard: cashiers can only view their own Z-reports.  Managers and
// admins see every session (they need to for reconciliation).
$viewerId   = (int) \CPHC\Session::userId();
$viewerRole = (string) \CPHC\Session::role();
if (!in_array($viewerRole, [Auth::ROLE_MANAGER, Auth::ROLE_ADMIN], true)
    && (int) $sessionRow['cashier_id'] !== $viewerId) {
    http_response_code(403);
    \CPHC\Audit::write($viewerId, 'IDOR_BLOCKED', 'cashier_sessions', $sid, null,
        ['target_cashier_id' => (int) $sessionRow['cashier_id']]);
    \CPHC\Router::redirect('/unauthorized');
    return;
}

$z = ZReport::loadForSession($sid);

$pharmacy = Db::fetchAll('SELECT key, value FROM settings');
$pname = $paddr = $pphone = '';
foreach ($pharmacy as $r) {
    if ($r['key'] === 'pharmacy_name')    $pname  = $r['value'];
    if ($r['key'] === 'pharmacy_address') $paddr  = $r['value'];
    if ($r['key'] === 'pharmacy_phone')   $pphone = $r['value'];
}

$verifyUrl = ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://'
    . ($_SERVER['HTTP_HOST'] ?? 'pharmacy.local')
    . '/zreport/verify/' . $sid;
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Z-Report · Session <?= e($sid) ?> · Care Point</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="icon" type="image/jpeg" href="/assets/logo.jpeg">
</head>
<body>
<div class="print-bar no-print">
    <a href="/pos/session">← Back to shift</a>
    <button type="button" id="z-print-btn">Print / Save as PDF</button>
</div>
<script nonce="<?= e(Csp::nonce()) ?>">
    document.getElementById('z-print-btn').addEventListener('click', () => window.print());
</script>

<article class="zreport">
    <header class="zreport__header">
        <img src="/assets/logo.jpeg" alt="Care Point logo">
        <h1 class="zreport__title">Z-Report</h1>
        <p class="zreport__sub">
            <?= e($pname ?: 'CARE POINT PHARMACY') ?>
            <?php if ($paddr !== ''): ?><br><?= e($paddr) ?><?php endif; ?>
            <?php if ($pphone !== ''): ?> · Ph: <?= e($pphone) ?><?php endif; ?>
        </p>
    </header>

    <dl>
        <dt>Session #</dt>            <dd>#<?= e($sid) ?></dd>
        <dt>Cashier</dt>              <dd><?= e($sessionRow['cashier_name'] ?? '—') ?></dd>
        <dt>Opened</dt>               <dd><?= e(substr((string) ($sessionRow['opened_at'] ?? ''), 0, 19)) ?></dd>
        <dt>Closed</dt>               <dd><?= e(substr((string) ($sessionRow['closed_at'] ?? ''), 0, 19) ?: '—') ?></dd>
        <dt>Status</dt>
        <dd>
            <span class="badge badge--<?= ($sessionRow['status'] ?? '') === 'RECONCILED' ? 'success' : (($sessionRow['status'] ?? '') === 'CLOSED' ? 'info' : 'neutral') ?>">
                <?= e($sessionRow['status'] ?? '—') ?>
            </span>
        </dd>
    </dl>

    <h2>Cash drawer</h2>
    <dl>
        <dt>Opening float</dt>        <dd>PKR <?= e(\CPHC\Money::fmt($sessionRow['opening_float'] ?? '0.00')) ?></dd>
        <dt>Expected cash</dt>        <dd>PKR <?= e(\CPHC\Money::fmt($sessionRow['expected_cash_in_drawer'] ?? '0.00')) ?></dd>
        <dt>Counted cash</dt>         <dd>PKR <?= e(\CPHC\Money::fmt($sessionRow['counted_cash'] ?? '0.00')) ?></dd>
        <dt>Variance</dt>
        <dd>
            <span style="color:<?= str_starts_with((string) ($sessionRow['cash_variance'] ?? '0'), '-') ? 'var(--danger)' : (((float) ($sessionRow['cash_variance'] ?? 0)) > 0 ? 'var(--warning)' : 'var(--success)') ?>;">
                PKR <?= e(\CPHC\Money::fmt($sessionRow['cash_variance'] ?? '0.00')) ?>
            </span>
        </dd>
    </dl>

    <h2>Sales recap</h2>
    <dl>
        <dt>Cash sales</dt>           <dd><?= e($z['payload']['cash_sales_count'] ?? 0) ?> × PKR <?= e(\CPHC\Money::fmt($z['payload']['cash_total'] ?? '0.00')) ?></dd>
        <dt>Card sales</dt>           <dd><?= e($z['payload']['card_sales_count'] ?? 0) ?> × PKR <?= e(\CPHC\Money::fmt($z['payload']['card_total'] ?? '0.00')) ?></dd>
        <dt>Grand total</dt>          <dd>PKR <?= e(\CPHC\Money::fmt($z['payload']['all_total'] ?? '0.00')) ?></dd>
    </dl>

    <?php if (count($z['sales']) > 0): ?>
        <h2>Receipts</h2>
        <table>
            <thead><tr><th>Receipt #</th><th>Time</th><th>Mode</th><th>Status</th><th class="right">Total</th></tr></thead>
            <tbody>
            <?php foreach ($z['sales'] as $s): ?>
                <tr>
                    <td><strong><?= e($s['receipt_number']) ?></strong></td>
                    <td><?= e(substr((string) $s['sold_at'], 0, 19)) ?></td>
                    <td><?= e($s['payment_mode']) ?></td>
                    <td><?= e($s['status']) ?></td>
                    <td class="num">PKR <?= e(\CPHC\Money::fmt($s['grand_total'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="sig-block">
        <strong>HMAC signature (SHA-256):</strong>
        <?= $z['signature'] !== '' ? e($z['signature']) : '<em>(unsigned — no audit key configured)</em>' ?>
        <br><br>
        <strong>Verify URL:</strong>
        <?= e($verifyUrl) ?>
        <br><br>
        <strong>Archive id:</strong> #<?= e($z['archive_id'] ?? '—') ?>
    </div>
</article>
</body>
</html>
