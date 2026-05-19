<?php
declare(strict_types=1);

namespace CPHC\Services;

/*
 * CUPS client.  Talks to the host's cupsd over the bind-mounted UNIX socket
 * /var/run/cups/cups.sock using the cups-client binaries (lp, lpstat, lpinfo,
 * lpadmin).  Replaces backend/app/Services/Printing/CupsClient.php; same
 * surface, but uses proc_open instead of Symfony Process.
 *
 * Privileged operations (lpadmin) rely on socket permissions: the container's
 * runtime user (nobody) must be in the host's `lp` group (GID 7, set via
 * docker-compose group_add) so the 0660 socket grants access.
 *
 * Process spawning uses proc_open with an argv array — never a shell string —
 * so user-supplied data can't trigger shell injection.  exec/shell_exec/
 * passthru/system are all in the PHP disable_functions list.
 */
final class Cups
{
    public const DEFAULT_QUEUE = 'CPHC_Receipt_80mm';
    public const DEFAULT_PPD   = 'zjiang/zj80.ppd';

    public function __construct(
        private readonly string $queue = self::DEFAULT_QUEUE,
        private readonly string $ppd   = self::DEFAULT_PPD,
    ) {}

    public function queueName(): string
    {
        return $this->queue;
    }

    /** Status snapshot for /admin/printer. */
    public function status(): array
    {
        $cupsActive = $this->cupsResponsive();
        $driverOk   = $cupsActive ? $this->driverInstalled() : false;
        $queueOk    = $cupsActive ? $this->queueExists()     : false;

        return [
            'cups'   => ['active'    => $cupsActive],
            'driver' => ['installed' => $driverOk],
            'queue'  => ['name' => $this->queue, 'exists' => $queueOk],
        ];
    }

    public function cupsResponsive(): bool
    {
        if (!file_exists('/var/run/cups/cups.sock')) {
            return false;
        }
        [$ok, $out] = $this->run(['lpstat', '-r'], timeoutMs: 2000);
        return $ok && str_contains($out, 'is running');
    }

    public function driverInstalled(): bool
    {
        // Robust check that doesn't depend on lpinfo being on PATH:
        //   1. The rastertozj filter binary lives in /usr/lib/cups/filter/.
        //   2. OR the queue's PPD references Zijiang / ZJ-80.
        // Either is sufficient to confirm the host can drive the printer.
        foreach (['/usr/lib/cups/filter/rastertozj', '/usr/libexec/cups/filter/rastertozj'] as $p) {
            if (is_file($p)) return true;
        }
        $ppd = '/etc/cups/ppd/' . $this->queue . '.ppd';
        if (is_file($ppd) && is_readable($ppd)) {
            $head = (string) @file_get_contents($ppd, false, null, 0, 4096);
            foreach (['zj-80', 'zj80', 'zijiang', 'rastertozj'] as $needle) {
                if (stripos($head, $needle) !== false) return true;
            }
        }
        // Last resort: lpinfo via absolute path (Debian puts it in /usr/sbin/).
        foreach (['/usr/sbin/lpinfo', '/usr/bin/lpinfo'] as $bin) {
            if (is_executable($bin)) {
                [$ok, $out] = $this->run([$bin, '-m'], timeoutMs: 4000);
                if ($ok && (stripos($out, 'zj') !== false || stripos($out, 'zijiang') !== false)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function queueExists(?string $name = null): bool
    {
        [$ok] = $this->run(['lpstat', '-p', $name ?? $this->queue], timeoutMs: 2000);
        return $ok;
    }

    /** @return array<int,array{uri:string,description:string}> */
    public function usbPrinters(): array
    {
        [$ok, $out] = $this->run(['lpinfo', '--include-schemes=usb', '-v'], timeoutMs: 5000);
        if (!$ok) return [];
        $devices = [];
        foreach (preg_split('/\r?\n/', $out) ?: [] as $line) {
            if (preg_match('/^\s*\S+\s+(usb:\S+)\s*(.*)$/', $line, $m)) {
                $devices[] = ['uri' => $m[1], 'description' => trim($m[2], " \t\"'")];
            }
        }
        return $devices;
    }

    /** Send raw ESC/POS bytes to the queue.  @return array{ok:bool,label:string,error:?string} */
    public function printRaw(string $bytes, string $label = 'job', ?string $queue = null): array
    {
        $q = $queue ?? $this->queue;
        [$ok, $stdout, $stderr] = $this->run(
            ['lp', '-d', $q, '-o', 'raw', '-t', $label],
            timeoutMs: 15000,
            stdin: $bytes,
        );
        if (!$ok) {
            return ['ok' => false, 'label' => $label, 'error' => trim($stderr ?: $stdout) ?: 'lp returned non-zero'];
        }
        return ['ok' => true, 'label' => $label, 'error' => null];
    }

    /**
     * Run a process with a bounded timeout.  Returns [success, stdout, stderr].
     *
     * @param  array<int,string>  $argv
     * @return array{0:bool, 1:string, 2:string}
     */
    private function run(array $argv, int $timeoutMs = 5000, ?string $stdin = null): array
    {
        // Resolve the binary against well-known CUPS paths if the caller
        // didn't pass an absolute path.  Debian/Ubuntu install lpstat / lpinfo
        // in /usr/sbin which isn't on the PHP-FPM PATH by default.
        if (isset($argv[0]) && !str_starts_with($argv[0], '/')) {
            foreach (['/usr/bin/', '/usr/sbin/', '/usr/local/bin/'] as $dir) {
                if (is_executable($dir . $argv[0])) {
                    $argv[0] = $dir . $argv[0];
                    break;
                }
            }
        }
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($argv, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return [false, '', 'proc_open failed'];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + ($timeoutMs / 1000);
        $stdout = '';
        $stderr = '';

        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) break;
            if (microtime(true) > $deadline) {
                proc_terminate($proc, SIGTERM);
                usleep(50000);
                proc_terminate($proc, SIGKILL);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return [false, $stdout, $stderr . "\n[timeout after {$timeoutMs}ms]"];
            }
            usleep(20000); // 20 ms
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return [$exit === 0, $stdout, $stderr];
    }
}
