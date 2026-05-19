# CPHC Pharmacy — Printer Stack

Everything host-side that makes the **Black Copper BC-88AC** (80 mm USB thermal
POS receipt printer) work with the CPHC Pharmacy app inside Docker.

## How it works

```
pos container ──/var/run/cups/cups.sock (bind-mount)──► CUPS on host
                                                            │
                                                            ▼
                                              rastertozj filter → ESC/POS → USB → printer
```

The PHP app talks to the host's `cupsd` directly through the bind-mounted UNIX
socket using `lp` / `lpadmin` / `lpinfo` / `lpstat` (the `cups-client` package,
baked into the runtime image). There is **no HTTP print-agent**, no sudoers
helper, no second systemd service — the docker-compose mount plus `lp` group
membership give the container all the privilege it needs.

The admin manages the printer from the app at **`/admin/printer`**.

## Install

Host-side install is **one-time and manual** — the new POS deliberately doesn't
ship an installer that runs as root. Run the scripts in this order:

```bash
sudo bash printer/scripts/bootstrap-host.sh   # CUPS + build deps + rastertozj filter + PPDs
sudo bash printer/scripts/setup-cups-queue.sh # registers the CPHC_Receipt_80mm queue
sudo bash printer/scripts/test-print.sh       # prints a host-side self-test page
```

After that, plug in the BC-88AC and open `/admin/printer` in the app:
**Detect USB → Test print.**

## Layout

```
printer/
├── README.md
├── driver/zj-58/                  Vendored CUPS filter (rastertozj + PPDs)
│   ├── rastertozj.c, CMakeLists.txt
│   ├── zj80.ppd  ← what we use (80 mm)
│   ├── zj58.ppd  ← if you ever swap to 58 mm rolls
│   └── UPSTREAM.md
└── scripts/
    ├── bootstrap-host.sh   ← One-time install (CUPS + build deps + rastertozj)
    ├── install-driver.sh   ← Just the rastertozj compile + install
    ├── setup-cups-queue.sh ← Registers CPHC_Receipt_80mm
    ├── test-print.sh       ← Host-side self-test
    ├── uninstall.sh        ← Removes queue, filter, PPDs
    └── lib/{distro-detect,cups-service}.sh
```

## Tested devices

| Device | Paper | Interface | PPD | Status |
|---|---|---|---|---|
| **Black Copper BC-88AC** | 80 mm | USB | `zjiang/zj80.ppd` | ✅ Reference device |
| Zijiang ZJ-80           | 80 mm | USB | `zjiang/zj80.ppd` | ✅ Same family |
| XPrinter XP-80          | 80 mm | USB | `zjiang/zj80.ppd` | ✅ ESC/POS compatible |
| Epson TM-T20 / II / III | 80 mm | USB | `zjiang/zj80.ppd` | ✅ ESC/POS compatible |

## Security model

- The container talks to CUPS through a UNIX socket bind-mount — no network
  port is exposed for printing.
- `lpadmin` requires write on `cups.sock`; on Debian the socket is
  `rw-rw-rw-` (world) by default. As a safety net the container is also added
  to the `lp` group via `docker-compose.yml`'s `group_add: ["7"]`.
- No NOPASSWD sudo entry, no extra `cphc-printer` group, no privileged daemon.
- Auditable from `journalctl -u cups` (CUPS) and `docker compose logs app`.

## Uninstall

```bash
sudo bash printer/scripts/uninstall.sh
```

Removes the queue, filter, and PPDs.
