<?php
declare(strict_types=1);

/**
 * Printer admin.  Shows CUPS reachability through the bind-mounted socket,
 * driver presence (zj80 PPD), queue status, and offers a test-print button
 * that fires a self-test ticket through CUPS.
 *
 * CUPS and the rastertozj driver are installed on the HOST, not in this
 * container.  See ../printer/scripts/ for the host-side install path.
 */

use CPHC\Auth;
use CPHC\Csp;
use CPHC\Csrf;
use CPHC\Router;
use CPHC\Session;
use CPHC\View;
use CPHC\Services\Cups;
use CPHC\Services\Printer;

Auth::requireRole(Auth::ROLE_ADMIN);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_print') {
    $r = (new Printer())->selfTest();
    Session::flash(
        $r['ok'] ? 'success' : 'error',
        $r['ok'] ? 'Self-test ticket sent to CUPS.' : 'Self-test failed: ' . ($r['error'] ?? 'unknown'),
    );
    Router::redirect('/admin/printer');
}

$cups   = new Cups();
$status = $cups->status();
$usb    = $status['cups']['active'] ? $cups->usbPrinters() : [];

$canPrint = $status['cups']['active'] && $status['queue']['exists'];

$ic = static fn (string $d, int $size = 20) =>
    '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';

ob_start();
?>
<div class="page-header">
    <div class="page-header__title">
        <h1 class="font-display">Printer</h1>
        <p class="page-header__sub">CUPS reachability, driver status, queue, and one-click self-test.</p>
    </div>
    <div class="page-header__actions">
        <form method="post" action="/admin/printer" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= e(Csrf::token()) ?>">
            <input type="hidden" name="action" value="test_print">
            <button type="submit" <?= $canPrint ? '' : 'disabled' ?>>
                <?= $ic('<path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/>', 16) ?>
                Print self-test
            </button>
        </form>
    </div>
</div>

<div class="card-grid mb-6">
    <div class="kpi">
        <div>
            <div class="kpi__label">CUPS</div>
            <div class="kpi__value" style="font-size:1.25rem;">
                <?php if ($status['cups']['active']): ?>
                    <span style="color:hsl(var(--success));">Active</span>
                <?php else: ?>
                    <span style="color:hsl(var(--destructive));">Not reachable</span>
                <?php endif; ?>
            </div>
            <div class="kpi__delta">over <code>/var/run/cups/cups.sock</code></div>
        </div>
        <div class="kpi__icon <?= $status['cups']['active'] ? 'kpi__icon--success' : 'kpi__icon--danger' ?>">
            <?= $ic('<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>') ?>
        </div>
    </div>

    <div class="kpi">
        <div>
            <div class="kpi__label">Driver (zj80)</div>
            <div class="kpi__value" style="font-size:1.25rem;">
                <?php if ($status['driver']['installed']): ?>
                    <span style="color:hsl(var(--success));">Installed</span>
                <?php else: ?>
                    <span style="color:hsl(var(--destructive));">Missing</span>
                <?php endif; ?>
            </div>
            <div class="kpi__delta">rastertozj filter + PPD</div>
        </div>
        <div class="kpi__icon <?= $status['driver']['installed'] ? 'kpi__icon--success' : 'kpi__icon--danger' ?>">
            <?= $ic('<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/>') ?>
        </div>
    </div>

    <div class="kpi">
        <div>
            <div class="kpi__label">Queue</div>
            <div class="kpi__value" style="font-size:1.25rem;">
                <?php if ($status['queue']['exists']): ?>
                    <span style="color:hsl(var(--success));">Ready</span>
                <?php else: ?>
                    <span style="color:hsl(var(--warning));">Missing</span>
                <?php endif; ?>
            </div>
            <div class="kpi__delta"><code><?= e($status['queue']['name']) ?></code></div>
        </div>
        <div class="kpi__icon <?= $status['queue']['exists'] ? 'kpi__icon--success' : 'kpi__icon--warning' ?>">
            <?= $ic('<path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z"/>') ?>
        </div>
    </div>
</div>

<?php if (!$status['cups']['active']): ?>
    <div class="flash error mb-4">
        <?= $ic('<path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/>', 18) ?>
        <div>
            CUPS is not reachable from inside the container.  Confirm the host has CUPS running
            and that <code>/var/run/cups/cups.sock</code> is bind-mounted into the container.
        </div>
    </div>
<?php endif; ?>

<?php if (count($usb) > 0): ?>
    <section class="card mb-4">
        <div class="card__header">
            <h2 class="card__title">USB printers detected</h2>
            <span class="badge badge--neutral"><?= count($usb) ?></span>
        </div>
        <table style="border:0; border-radius:0; box-shadow:none;">
            <thead><tr><th>URI</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($usb as $u): ?>
                <tr>
                    <td><code style="font-size:12px;"><?= e($u['uri']) ?></code></td>
                    <td><?= e($u['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>

<section class="card">
    <div class="card__header">
        <h2 class="card__title">Host-side install</h2>
    </div>
    <div class="card__body">
        <p class="muted">The host install runs <strong>once, outside Docker</strong>. See the scripts in
            <code>printer/scripts/</code> at the repo root:</p>
        <ul style="line-height:1.9;">
            <li><code>bootstrap-host.sh</code> — installs CUPS &amp; build dependencies</li>
            <li><code>install-driver.sh</code> — compiles &amp; registers <code>rastertozj</code></li>
            <li><code>setup-cups-queue.sh</code> — creates the <code><?= e($status['queue']['name']) ?></code> queue</li>
            <li><code>test-print.sh</code> — host-side self-test ticket</li>
            <li><code>uninstall.sh</code> — removes queue, filter, and PPDs</li>
        </ul>
    </div>
</section>
<?php
$body = (string) ob_get_clean();
echo View::partial('layout', [
    'title' => 'Printer',
    'body'  => $body,
    'active' => 'printer',
    'flash_success' => Session::flash('success'),
    'flash_error'   => Session::flash('error'),
    'nonce' => Csp::nonce(),
]);
