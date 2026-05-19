# shellcheck shell=bash
# CUPS service control — different distros name the service differently:
#   Debian/Ubuntu: cups
#   Arch:          cups (sometimes cupsd)
#   Fedora:        cups
#   Some legacy:   cupsd
#   macOS:         org.cups.cupsd (launchd, not systemd)

CUPS_SERVICE=""

find_cups_service() {
    if ! command -v systemctl >/dev/null 2>&1; then
        # Not systemd — try sysvinit
        if [ -x /etc/init.d/cups ]; then
            CUPS_SERVICE="cups"
            export CUPS_SERVICE
            return 0
        fi
        CUPS_SERVICE=""
        return 1
    fi
    # `systemctl cat` exits 0 iff the unit file is known to systemd. That's a
    # stable contract — unlike scraping `systemctl list-unit-files`, whose
    # column widths and "WARNING: ..." preambles change between systemd
    # versions and have bitten us in the past.
    for unit in cups cupsd org.cups.cupsd; do
        if systemctl cat "${unit}.service" >/dev/null 2>&1; then
            CUPS_SERVICE="$unit"
            export CUPS_SERVICE
            return 0
        fi
    done
    return 1
}

cups_is_active() {
    [ -n "$CUPS_SERVICE" ] || find_cups_service || return 1
    systemctl is-active --quiet "$CUPS_SERVICE"
}

cups_restart() {
    [ -n "$CUPS_SERVICE" ] || find_cups_service \
        || { echo "✗ No CUPS service found (tried cups, cupsd, org.cups.cupsd)" >&2; return 1; }
    if command -v systemctl >/dev/null 2>&1; then
        systemctl restart "$CUPS_SERVICE"
    else
        /etc/init.d/cups restart
    fi
}
