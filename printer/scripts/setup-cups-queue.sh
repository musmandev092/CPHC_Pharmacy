#!/usr/bin/env bash
# CPHC Pharmacy — create the CUPS print queue.
#
# Discovers the USB-connected receipt printer, registers it as queue
# `CPHC_Receipt_80mm` using zj80.ppd, and enables it.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

QUEUE_NAME="${CPHC_CUPS_QUEUE:-CPHC_Receipt_80mm}"
PPD_MODEL="zjiang/zj80.ppd"
LOCATION="CPHC Pharmacy — 80mm Thermal Receipt Printer"
DESCRIPTION="Black Copper BC-88AC (or compatible ESC/POS 80mm)"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
info() { echo -e "${BLUE}  →${NC} $*"; }
ok()   { echo -e "${GREEN}  ✓${NC} $*"; }
warn() { echo -e "${YELLOW}  ⚠${NC} $*"; }
die()  { echo -e "${RED}  ✗${NC} $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "setup-cups-queue.sh requires root. Re-run with: sudo bash $0"

command -v lpinfo  >/dev/null || die "'lpinfo' not found — install CUPS first."
command -v lpadmin >/dev/null || die "'lpadmin' not found — install CUPS first."

echo -e "\n${BOLD}  CPHC Pharmacy — CUPS queue setup${NC}\n"

# ── Already exists? ───────────────────────────────────────────────────────
if lpstat -p "$QUEUE_NAME" >/dev/null 2>&1; then
    info "Queue '$QUEUE_NAME' already exists. Re-running will overwrite the device URI."
    EXISTING_URI="$(lpstat -v "$QUEUE_NAME" 2>/dev/null | sed -E 's|.*: ||')"
    info "Current URI: $EXISTING_URI"
fi

# ── Discover USB devices ──────────────────────────────────────────────────
info "Scanning USB for thermal/POS devices..."
mapfile -t USB_URIS < <(lpinfo -v 2>/dev/null | awk '$1=="direct" && $2 ~ /^usb:\/\// {print $2}')

if [ "${#USB_URIS[@]}" -eq 0 ]; then
    echo
    warn "No USB printer detected by CUPS."
    echo "    Things to check:"
    echo "      1. Printer is powered on and connected via USB"
    echo "      2. 'lsusb' shows the device"
    echo "      3. Current user is in the 'lp' group (sudo usermod -aG lp \$USER)"
    echo "      4. Manually rescan: lpinfo -v"
    echo
    read -rp "  Enter the USB URI manually (or press Enter to abort): " MANUAL_URI
    [ -z "$MANUAL_URI" ] && die "Aborting — no printer URI."
    USB_URIS=("$MANUAL_URI")
fi

if [ "${#USB_URIS[@]}" -eq 1 ]; then
    USB_URI="${USB_URIS[0]}"
    info "One USB device found: $USB_URI"
else
    echo
    echo "  Multiple USB devices detected:"
    i=1
    for u in "${USB_URIS[@]}"; do
        echo "    $i) $u"
        i=$((i+1))
    done
    echo
    read -rp "  Pick one [1-${#USB_URIS[@]}]: " CHOICE
    USB_URI="${USB_URIS[$((CHOICE - 1))]}"
    [ -n "$USB_URI" ] || die "Invalid choice."
fi

# ── Verify PPD is reachable by lpadmin ────────────────────────────────────
if ! lpinfo -m 2>/dev/null | grep -q "$PPD_MODEL"; then
    warn "lpadmin doesn't list '$PPD_MODEL' yet — CUPS may need a restart after the driver install."
    warn "Try: sudo systemctl restart cups   then re-run this script."
fi

# ── Register the queue ────────────────────────────────────────────────────
info "Creating CUPS queue '$QUEUE_NAME' → $USB_URI ..."
lpadmin -p "$QUEUE_NAME" -E \
    -v "$USB_URI" \
    -m "$PPD_MODEL" \
    -L "$LOCATION" \
    -D "$DESCRIPTION"
cupsenable "$QUEUE_NAME"
cupsaccept "$QUEUE_NAME"
ok "Queue created and enabled"

# ── Make it the default queue if there isn't one yet ─────────────────────
if ! lpstat -d 2>/dev/null | grep -qE 'system default'; then
    lpadmin -d "$QUEUE_NAME" && ok "Set '$QUEUE_NAME' as the system default printer"
fi

# ── Summary ───────────────────────────────────────────────────────────────
echo
lpstat -p "$QUEUE_NAME" -l 2>/dev/null | head -10
echo
echo -e "  ${GREEN}${BOLD}Queue ready.${NC}"
echo -e "  Next: bash $SCRIPT_DIR/test-print.sh"
