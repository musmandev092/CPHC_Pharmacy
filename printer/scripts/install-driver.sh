#!/usr/bin/env bash
# CPHC Pharmacy — install the CUPS filter (rastertozj) for the Black Copper BC-88AC
# and other ESC/POS 80mm thermal receipt printers.
#
# Builds rastertozj.c from source, installs the binary into /usr/lib/cups/filter/,
# installs PPDs into /usr/share/cups/model/zjiang/, and restarts CUPS.
#
# Requires: sudo / root, working internet briefly (for package install), cmake.
# Supported distros: Debian, Ubuntu, Arch, Manjaro, Fedora.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PRINTER_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
DRIVER_DIR="$PRINTER_ROOT/driver/zj-58"
BUILD_DIR="$DRIVER_DIR/build"

# shellcheck source=lib/distro-detect.sh
. "$SCRIPT_DIR/lib/distro-detect.sh"
# shellcheck source=lib/cups-service.sh
. "$SCRIPT_DIR/lib/cups-service.sh"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
info() { echo -e "${BLUE}  →${NC} $*"; }
ok()   { echo -e "${GREEN}  ✓${NC} $*"; }
warn() { echo -e "${YELLOW}  ⚠${NC} $*"; }
die()  { echo -e "${RED}  ✗${NC} $*" >&2; exit 1; }

# ── Sanity ────────────────────────────────────────────────────────────────
if [ "$(id -u)" -ne 0 ]; then
    die "install-driver.sh must run as root. Re-run with: sudo bash $0"
fi

[ -f "$DRIVER_DIR/CMakeLists.txt" ] || die "Driver source missing at $DRIVER_DIR"

echo -e "\n${BOLD}  CPHC Pharmacy — CUPS filter installer  (ESC/POS 80mm)${NC}\n"

# ── Detect distro ─────────────────────────────────────────────────────────
detect_distro
info "Detected: $DISTRO_ID  (family=$DISTRO_FAMILY)"

if [ "$DISTRO_FAMILY" = "unknown" ]; then
    warn "Unrecognised distro. You may need to install build deps manually."
    warn "Required: gcc/g++, make, cmake, cups, cups-devel (libcups2-dev, libcupsimage2-dev on Debian)"
fi

# ── Install build deps ────────────────────────────────────────────────────
if [ -n "$INSTALL_CMD" ] && [ -n "$PKG_DRIVER_DEPS" ]; then
    info "Installing build dependencies: $PKG_DRIVER_DEPS"
    # shellcheck disable=SC2086
    $INSTALL_CMD $PKG_DRIVER_DEPS || die "Package install failed. Try manually: $(manual_install_hint)"
    ok "Dependencies installed"
fi

# ── Find CUPS service ─────────────────────────────────────────────────────
if ! find_cups_service; then
    warn "CUPS systemd unit not found (will rely on the CMake install scripts)"
else
    info "CUPS service: $CUPS_SERVICE"
fi

# ── Build ─────────────────────────────────────────────────────────────────
# Wipe the build dir before configuring. `mkdir -p` doesn't clean it, and
# CMake aborts when CMakeCache.txt's recorded source path doesn't match the
# current one — which happens whenever the project is moved (e.g. cloned
# under ~/Downloads first, then relocated). A fresh build is cheap here, so
# unconditionally remove the previous build dir.
info "Configuring (cmake)..."
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"
cmake -DCMAKE_BUILD_TYPE=Release .. >/dev/null

info "Compiling rastertozj..."
cmake --build . --parallel >/dev/null
ok "Built $(file rastertozj 2>/dev/null | cut -d: -f2- | xargs)"

# ── Install ───────────────────────────────────────────────────────────────
info "Installing to /usr/lib/cups/filter/ and /usr/share/cups/model/zjiang/ ..."
# Upstream CMakeLists already stops/starts CUPS in install steps, but it uses
# /etc/init.d/cups which isn't available everywhere. We swap that behaviour
# for our own service handler below.
cmake --install . 2>&1 | grep -vE '^(/etc/init\.d/cups|Failed|systemctl)' || true

# Verify filter is present + executable
if [ ! -x /usr/lib/cups/filter/rastertozj ]; then
    die "Install failed — /usr/lib/cups/filter/rastertozj is missing or not executable"
fi
chown root:root /usr/lib/cups/filter/rastertozj
chmod 755 /usr/lib/cups/filter/rastertozj
ok "Filter installed: /usr/lib/cups/filter/rastertozj"

# Verify the 80mm PPD landed where CUPS expects
for ppd in /usr/share/cups/model/zjiang/zj80.ppd /usr/share/ppd/zjiang/zj80.ppd; do
    if [ -f "$ppd" ]; then
        ok "PPD installed: $ppd"
        FOUND_PPD=1
        break
    fi
done
if [ "${FOUND_PPD:-0}" != 1 ]; then
    # Some distros expect /usr/share/cups/model/...; copy manually if missing
    mkdir -p /usr/share/cups/model/zjiang
    cp -f "$DRIVER_DIR"/zj{58,80}.ppd /usr/share/cups/model/zjiang/
    ok "PPDs copied to /usr/share/cups/model/zjiang/"
fi

# ── Restart CUPS ──────────────────────────────────────────────────────────
if cups_restart; then
    ok "CUPS restarted ($CUPS_SERVICE)"
else
    warn "Could not restart CUPS automatically. Please run: sudo systemctl restart cups"
fi

echo -e "\n  ${GREEN}${BOLD}Driver installed.${NC}"
echo -e "  Next: bash $SCRIPT_DIR/setup-cups-queue.sh\n"
