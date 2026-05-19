#!/usr/bin/env bash
# CPHC Pharmacy — one-time HOST bootstrap for the printer.
#
# After the agent-free refactor, this script's only job is:
#   1. Install CUPS + driver build deps
#   2. Add the desktop user to the `lp` group (needed for /admin/printer's
#      shell access — the Docker container's www-data is mapped to the lp
#      GID via docker-compose group_add, while host-side tools want the
#      real user in `lp` too)
#   3. Compile + install the rastertozj CUPS filter (idempotent)
#
# That's it. No agent, no helper, no sudoers entry, no user systemd unit,
# no extra group, no linger requirement.
#
# Usage:
#     sudo bash printer/scripts/bootstrap-host.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PRINTER_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=lib/distro-detect.sh
. "$SCRIPT_DIR/lib/distro-detect.sh"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
info() { echo -e "${BLUE}  →${NC} $*"; }
ok()   { echo -e "${GREEN}  ✓${NC} $*"; }
warn() { echo -e "${YELLOW}  ⚠${NC} $*"; }
die()  { echo -e "${RED}  ✗${NC} $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "bootstrap-host.sh requires root. Re-run with: sudo bash $0"
[ -n "${SUDO_USER:-}" ] || die "Run via sudo from your desktop user (not as the literal root user)."

echo -e "\n${BOLD}  CPHC Pharmacy — printer host bootstrap${NC}"
echo -e "  Desktop user: $SUDO_USER\n"

detect_distro
info "Distro: ${DISTRO_ID} (family=${DISTRO_FAMILY})"

# ── 1. CUPS + driver build deps ──────────────────────────────────────────
case "$DISTRO_FAMILY" in
    debian)
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
            cups build-essential cmake libcups2-dev libcupsimage2-dev
        ;;
    arch)
        pacman -Sy --needed --noconfirm cups base-devel cmake
        ;;
    rhel)
        dnf install -y cups gcc-c++ make cmake cups-devel
        ;;
    *)
        die "Unsupported distro family '$DISTRO_FAMILY'. Install cups + a C/C++ toolchain manually."
        ;;
esac

# Ensure CUPS is up + enabled
for unit in cups cupsd org.cups.cupsd; do
    if systemctl cat "${unit}.service" >/dev/null 2>&1; then
        systemctl enable --now "$unit"
        ok "CUPS service: $unit (active)"
        break
    fi
done

# ── 2. lp group membership ──────────────────────────────────────────────
# The desktop user is in `lp` for direct lp / cancel etc. from a host
# terminal. The Laravel container's www-data is mapped to the same group
# via docker-compose group_add: "7" — but that has nothing to do with this
# step, which is purely a host-side convenience.
if ! id -nG "$SUDO_USER" | tr ' ' '\n' | grep -qx 'lp'; then
    usermod -aG lp "$SUDO_USER"
    ok "Added $SUDO_USER to lp (re-login required for the new session to inherit it)"
else
    ok "$SUDO_USER already in lp"
fi

# ── 3. CUPS filter (rastertozj) ─────────────────────────────────────────
if [ -x /usr/lib/cups/filter/rastertozj ]; then
    ok "CUPS filter already installed: /usr/lib/cups/filter/rastertozj"
else
    info "Building + installing the rastertozj CUPS filter…"
    if bash "$SCRIPT_DIR/install-driver.sh"; then
        ok "Driver installed"
    else
        warn "Driver install failed — re-run later from the host."
    fi
fi

echo
echo -e "${BOLD}${GREEN}  Printer host bootstrap complete.${NC}"
echo
echo -e "  Next:"
echo -e "  1. Bring up the Laravel stack:  ${BOLD}docker compose up -d${NC}"
echo -e "  2. Plug in the BC-88AC, then in /admin/printer: pick the USB device → Create queue → Test print."
echo
