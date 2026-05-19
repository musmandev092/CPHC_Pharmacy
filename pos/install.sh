#!/bin/bash
# CPHC Pharmacy POS — first-time install.
#
# Run this ONCE on a fresh machine (Debian/Ubuntu host with Docker installed).
# It does everything the user needs to access the app at https://pharmacy.local
# in a browser with a green padlock — no "Not Secure" warning.
#
# Idempotent — safe to re-run. Won't touch your database if it already exists.
#
# Usage:  sudo bash install.sh
#
# What it does:
#   1. Verifies Docker is installed and the user is in the docker group.
#   2. Generates per-installation secrets (db password, audit-chain HMAC key,
#      session-fingerprint HMAC key) if not already present.
#   3. Adds `127.0.0.1 pharmacy.local` to /etc/hosts so the browser resolves
#      the friendly name.
#   4. Builds the Docker image from source and brings the stack up.
#   5. Copies Caddy's auto-generated root CA into the system trust store
#      (and into Firefox/Brave's NSS DB if present) so the browser shows
#      a green padlock instead of "Not Secure".
#   6. Prints the URL + default admin credentials.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_DIR"

# ─── Colour helpers ──────────────────────────────────────────────────────────
g()  { printf "\033[32m%s\033[0m\n" "$*"; }     # green
y()  { printf "\033[33m%s\033[0m\n" "$*"; }     # yellow
r()  { printf "\033[31m%s\033[0m\n" "$*"; }     # red
hdr(){ printf "\n\033[36m═══ %s ═══\033[0m\n" "$*"; }

# ─── 0. Prerequisites ────────────────────────────────────────────────────────
hdr "0. Prerequisites"
if [ "$EUID" -ne 0 ]; then
    r "Run as root (sudo bash install.sh)"; exit 1
fi
command -v docker >/dev/null || { r "Docker not installed. apt install docker.io docker-compose-plugin"; exit 1; }
docker compose version >/dev/null 2>&1 || { r "docker compose plugin missing"; exit 1; }
g "  ✓ Docker $(docker --version | awk '{print $3}' | tr -d ,) + Compose $(docker compose version --short)"

# Real (non-root) user — needed for hosts file UX message + Firefox profile.
REAL_USER="${SUDO_USER:-$(logname 2>/dev/null || whoami)}"
REAL_HOME=$(getent passwd "$REAL_USER" | cut -d: -f6)
g "  ✓ Install user: $REAL_USER (home: $REAL_HOME)"

# ─── 1. Secrets ──────────────────────────────────────────────────────────────
hdr "1. Per-installation secrets"
mkdir -p secrets && chmod 700 secrets
gen() {
    local f=$1 lbl=$2
    if [ -s "secrets/$f" ]; then
        g "  ✓ $lbl already set (secrets/$f)"
    else
        openssl rand -hex 32 > "secrets/$f"
        chmod 600 "secrets/$f"
        g "  + Generated $lbl"
    fi
}
gen db_password    "DB password"
gen audit_key      "audit-chain HMAC key"
gen session_secret "session fingerprint HMAC key"

# ─── 2. /etc/hosts entry ─────────────────────────────────────────────────────
hdr "2. Host resolution for pharmacy.local"
if grep -qE '^[^#]*\bpharmacy\.local\b' /etc/hosts; then
    g "  ✓ pharmacy.local already in /etc/hosts"
else
    echo "127.0.0.1 pharmacy.local" >> /etc/hosts
    g "  + Added 127.0.0.1 pharmacy.local"
fi

# ─── 3. Build + bring up ─────────────────────────────────────────────────────
hdr "3. Building image + bringing up containers"
y "  this takes ~60-90s on first build…"
docker compose build --quiet --pull app
docker compose up -d

# Wait for both healthy.
g "  waiting for containers to be healthy…"
for i in $(seq 1 60); do
    POSTGRES=$(docker inspect cphc-pos-postgres-1 --format '{{.State.Health.Status}}' 2>/dev/null || echo "starting")
    APP=$(docker inspect cphc-pos-app-1 --format '{{.State.Health.Status}}' 2>/dev/null || echo "starting")
    if [ "$POSTGRES" = "healthy" ] && [ "$APP" = "healthy" ]; then
        g "  ✓ both healthy after ${i}s"; break
    fi
    sleep 1
done
[ "$POSTGRES" = "healthy" ] && [ "$APP" = "healthy" ] || { r "  containers didn't become healthy"; docker compose logs --tail=30; exit 1; }

# ─── 4. Trust Caddy's local CA ──────────────────────────────────────────────
hdr "4. Trusting Caddy's local CA"
CADDY_ROOT_PATH=/run/caddy-data/caddy/pki/authorities/local/root.crt
# Wait for Caddy to mint the CA on first run.
for i in 1 2 3 4 5 6 7 8 9 10; do
    docker exec cphc-pos-app-1 test -f $CADDY_ROOT_PATH 2>/dev/null && break
    sleep 1
done
docker exec cphc-pos-app-1 cat $CADDY_ROOT_PATH > /usr/local/share/ca-certificates/cphc-caddy-root.crt
update-ca-certificates 2>&1 | tail -1

# Firefox / Brave use their own NSS DB — install there too if present.
NSS_DB_FOUND=0
for d in "$REAL_HOME/.mozilla/firefox"/*.default* \
         "$REAL_HOME/snap/firefox/common/.mozilla/firefox"/*.default*; do
    [ -d "$d" ] || continue
    if command -v certutil >/dev/null; then
        sudo -u "$REAL_USER" certutil -A -n "CPHC-CaddyLocal" -t "C,," \
            -i /usr/local/share/ca-certificates/cphc-caddy-root.crt \
            -d "sql:$d" 2>/dev/null && NSS_DB_FOUND=1
    fi
done
# Chromium/Brave: ~/.pki/nssdb
PKIDB="$REAL_HOME/.pki/nssdb"
if [ -d "$PKIDB" ] && command -v certutil >/dev/null; then
    sudo -u "$REAL_USER" certutil -A -n "CPHC-CaddyLocal" -t "C,," \
        -i /usr/local/share/ca-certificates/cphc-caddy-root.crt \
        -d "sql:$PKIDB" 2>/dev/null && NSS_DB_FOUND=1
fi

if [ $NSS_DB_FOUND -eq 1 ]; then
    g "  ✓ Caddy root CA installed system-wide + into Firefox/Brave NSS DB"
else
    g "  ✓ Caddy root CA installed system-wide"
    y "  (Firefox/Brave NSS DB not found — open the browser once, then re-run this script to add to it)"
fi

# ─── 5. Final smoke test ────────────────────────────────────────────────────
hdr "5. Smoke test"
HTTPS_OK=$(curl -sk -o /dev/null -w "%{http_code}" https://pharmacy.local/healthz)
[ "$HTTPS_OK" = "200" ] && g "  ✓ https://pharmacy.local/healthz → 200" || r "  healthz failed: $HTTPS_OK"

# ─── 6. Friendly closing message ────────────────────────────────────────────
hdr "Installation complete"
cat <<EOF

  $(g "Open in your browser:")  https://pharmacy.local

  Default admin credentials (change on first login):
      Username:  admin
      PIN:       472913

  Useful commands:
      docker compose logs -f app        # tail app logs
      docker compose logs -f postgres   # tail DB logs
      docker compose ps                 # container status
      sudo bash update.sh               # pull new code + rebuild app
      docker compose down               # stop (DB volume preserved)

  Database persists in the named volume 'cphc-pos_pg-data'.
  To truly wipe: docker compose down --volumes (irreversible).

EOF
