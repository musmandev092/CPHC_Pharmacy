#!/usr/bin/env bash
# CPHC Pharmacy — send a self-test ticket through the CUPS queue.
# Uses raw ESC/POS bytes (-o raw) so the rastertozj filter is bypassed and
# the exact path the Laravel container takes (lp -o raw → cups.sock) is
# exercised end-to-end.

set -euo pipefail

QUEUE_NAME="${CPHC_CUPS_QUEUE:-CPHC_Receipt_80mm}"
TS="$(date '+%Y-%m-%d %H:%M:%S')"

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
info() { echo -e "${BLUE}  →${NC} $*"; }
ok()   { echo -e "${GREEN}  ✓${NC} $*"; }
die()  { echo -e "${RED}  ✗${NC} $*" >&2; exit 1; }

command -v lp >/dev/null || die "'lp' not found — install CUPS first."
lpstat -p "$QUEUE_NAME" >/dev/null 2>&1 \
    || die "Queue '$QUEUE_NAME' does not exist. Run setup-cups-queue.sh first."

echo -e "\n${BOLD}  CPHC Pharmacy — Test print  (queue: $QUEUE_NAME)${NC}\n"

# Build the ESC/POS payload in a temp file
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

{
    # ESC @  — initialise printer
    printf '\x1b\x40'
    # ESC a 1  — centre
    printf '\x1ba\x01'
    # GS ! 0x11  — double width + double height
    printf '\x1d\x21\x11'
    printf 'CPHC Pharmacy\n'
    # GS ! 0x00  — reset size
    printf '\x1d\x21\x00'
    printf 'Printer self-test\n'
    printf 'Black Copper BC-88AC\n'
    printf -- '----------------------------\n'
    # ESC a 0  — left
    printf '\x1ba\x00'
    printf 'Queue : %s\n' "$QUEUE_NAME"
    printf 'PPD   : zjiang/zj80.ppd\n'
    printf 'Time  : %s\n' "$TS"
    printf 'Status: OK\n'
    printf -- '----------------------------\n'
    printf '\x1ba\x01'
    printf 'If you can read this, the\n'
    printf 'CUPS queue + driver work.\n'
    # Feed 6 lines
    printf '\n\n\n\n\n\n'
    # GS V 1  — partial cut (auto-cutter on the BC-88AC)
    printf '\x1d\x56\x01'
} > "$TMP"

info "Sending raw payload (${#TMP} bytes via tmpfile) ..."
lp -d "$QUEUE_NAME" -o raw "$TMP" >/dev/null
ok "Job queued. Check the printer."

echo
lpstat -o "$QUEUE_NAME" | head -3
