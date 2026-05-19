#!/usr/bin/env bash
# CPHC Pharmacy — reverse the printer install.
#
# Removes the CUPS queue + filter + PPDs. Also tears down legacy print-agent
# artefacts (user systemd unit, sudoers helper, cphc-printer group) that may
# linger on machines that were set up before the agent-free refactor — those
# steps are no-ops on a clean install.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
QUEUE_NAME="${CPHC_CUPS_QUEUE:-CPHC_Receipt_80mm}"

# shellcheck source=lib/cups-service.sh
. "$SCRIPT_DIR/lib/cups-service.sh"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
info() { echo -e "${BLUE}  →${NC} $*"; }
ok()   { echo -e "${GREEN}  ✓${NC} $*"; }
warn() { echo -e "${YELLOW}  ⚠${NC} $*"; }
die()  { echo -e "${RED}  ✗${NC} $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "uninstall.sh requires root. Re-run with: sudo bash $0"
[ -n "${SUDO_USER:-}" ] || die "Run via sudo from your desktop user, not as the literal root user."

echo -e "\n${BOLD}  CPHC Pharmacy — printer uninstall${NC}\n"

# ── 1. CUPS queue ─────────────────────────────────────────────────────────
if lpstat -p "$QUEUE_NAME" >/dev/null 2>&1; then
    info "Removing CUPS queue '$QUEUE_NAME'…"
    lpadmin -x "$QUEUE_NAME"
    ok "Queue removed"
fi

# ── 2. CUPS filter + PPDs ─────────────────────────────────────────────────
if [ -f /usr/lib/cups/filter/rastertozj ]; then
    rm -f /usr/lib/cups/filter/rastertozj
    ok "Removed /usr/lib/cups/filter/rastertozj"
fi
if [ -d /usr/share/cups/model/zjiang ]; then
    rm -rf /usr/share/cups/model/zjiang
    ok "Removed /usr/share/cups/model/zjiang/"
fi

# ── 3. Restart CUPS ───────────────────────────────────────────────────────
cups_restart || warn "Could not restart CUPS — do it manually"

# ── 4. Legacy print-agent leftovers (pre-refactor cleanup) ────────────────
# All silently no-op when nothing's there. Keep these blocks until the
# refactor has aged enough that no installed system has them any more.
SERVICE_FILE="/home/$SUDO_USER/.config/systemd/user/cphc-print-agent.service"
if [ -f "$SERVICE_FILE" ]; then
    info "Removing legacy print-agent user service…"
    sudo -u "$SUDO_USER" systemctl --user disable --now cphc-print-agent.service 2>/dev/null || true
    sudo -u "$SUDO_USER" rm -f "$SERVICE_FILE"
    sudo -u "$SUDO_USER" systemctl --user daemon-reload 2>/dev/null || true
    ok "Legacy agent service removed"
fi
AGENT_DIR="/home/$SUDO_USER/.local/share/cphc/print-agent"
if [ -d "$AGENT_DIR" ]; then
    rm -rf "$AGENT_DIR"
    rmdir "/home/$SUDO_USER/.local/share/cphc" 2>/dev/null || true
    ok "Removed $AGENT_DIR"
fi
if [ -f /usr/local/bin/cphc-printer-helper ]; then
    rm -f /usr/local/bin/cphc-printer-helper
    ok "Removed /usr/local/bin/cphc-printer-helper"
fi
if [ -f /etc/sudoers.d/cphc-printer-helper ]; then
    rm -f /etc/sudoers.d/cphc-printer-helper
    ok "Removed /etc/sudoers.d/cphc-printer-helper"
fi
if getent group cphc-printer >/dev/null; then
    groupdel cphc-printer 2>/dev/null && ok "Removed cphc-printer group" || true
fi

echo -e "\n  ${GREEN}${BOLD}Uninstall complete.${NC}\n"
