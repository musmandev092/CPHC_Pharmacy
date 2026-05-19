#!/usr/bin/env bash
# CPHC Pharmacy — one-shot host-side printer install (CLI alternative to /admin/printer).
#
# Most users should just run `scripts/install-all.sh` from the repo root —
# that invokes `bootstrap-host.sh` which already installs the driver. This
# script is a thin wrapper for when you only want the printer bits:
#
#   1. Install the CUPS filter (rastertozj)        — needs sudo
#   2. Add the CUPS queue 'CPHC_Receipt_80mm'      — needs sudo
#   3. Send a test print to verify                 — no sudo
#
# After the agent-free refactor there is no step 4 — Laravel talks to CUPS
# directly through the bind-mounted /var/run/cups/cups.sock.
#
# Run with sudo from the repo root:
#     sudo bash printer/scripts/install.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

RED='\033[0;31m'; GREEN='\033[0;32m'; BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
step() { echo -e "\n${BOLD}${BLUE}══ $* ══${NC}\n"; }
die()  { echo -e "${RED}  ✗${NC} $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "install.sh requires root. Re-run with: sudo bash $0"
[ -n "${SUDO_USER:-}" ] || die "Run via sudo from your desktop user, not as the literal root user."

echo -e "\n${BOLD}  CPHC Pharmacy — host printer setup  (BC-88AC / 80mm)${NC}"
echo -e "  Invoking user: $SUDO_USER"

step "Step 1/3 — CUPS filter"
bash "$SCRIPT_DIR/install-driver.sh"

step "Step 2/3 — CUPS queue"
bash "$SCRIPT_DIR/setup-cups-queue.sh"

step "Step 3/3 — Test print"
sudo -u "$SUDO_USER" bash "$SCRIPT_DIR/test-print.sh"

echo -e "\n${BOLD}${GREEN}  All steps complete.${NC}"
echo -e "  - CUPS filter: /usr/lib/cups/filter/rastertozj"
echo -e "  - CUPS queue:  CPHC_Receipt_80mm"
echo -e "  - From now on: print jobs go Laravel → /var/run/cups/cups.sock → CUPS → printer"
echo
