#!/bin/bash
# CPHC Pharmacy POS — apply a code update.
#
# After you `git pull` (or copy in new source), run this to rebuild the app
# image and roll the container.  The PostgreSQL data volume is NOT touched —
# all sales, audit log, GRNs, returns survive.
#
# Usage:  sudo bash update.sh
#
# What it does:
#   1. Builds the app image from the current source (no cache).
#   2. Recreates only the app container (postgres stays running with its data).
#   3. Waits for healthy.
#   4. Reports status.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$REPO_DIR"

g(){ printf "\033[32m%s\033[0m\n" "$*"; }
y(){ printf "\033[33m%s\033[0m\n" "$*"; }
r(){ printf "\033[31m%s\033[0m\n" "$*"; }
hdr(){ printf "\n\033[36m═══ %s ═══\033[0m\n" "$*"; }

[ "$EUID" -eq 0 ] || { r "Run as root (sudo bash update.sh)"; exit 1; }
command -v docker >/dev/null || { r "Docker not installed"; exit 1; }

# Confirm the stack already exists (so we don't accidentally do a first-install here).
if ! docker volume inspect cphc-pos_pg-data >/dev/null 2>&1; then
    r "No existing database volume found — run install.sh first."
    exit 1
fi

hdr "1. Building new app image (no cache)"
y "  takes ~60s; postgres keeps running…"
docker compose build --no-cache --quiet app

hdr "2. Rolling the app container"
# --no-deps means postgres is left running with its data volume intact.
# Compose recreates only the app container with the new image.
docker compose up -d --no-deps app

hdr "3. Waiting for health"
for i in $(seq 1 30); do
    H=$(docker inspect cphc-pos-app-1 --format '{{.State.Health.Status}}' 2>/dev/null || echo starting)
    if [ "$H" = healthy ]; then
        g "  ✓ healthy after ${i}s"; break
    fi
    sleep 1
done
[ "$H" = healthy ] || { r "  app didn't become healthy"; docker compose logs --tail=40 app; exit 1; }

hdr "4. Smoke test"
HTTPS_OK=$(curl -sk -o /dev/null -w "%{http_code}" https://pharmacy.local/healthz)
[ "$HTTPS_OK" = "200" ] && g "  ✓ https://pharmacy.local/healthz → 200" || r "  healthz failed: $HTTPS_OK"

hdr "Update complete"
cat <<EOF

  New app image: $(docker images cphc/pos-app:latest --format '{{.ID}} ({{.CreatedSince}})')
  Database volume: $(docker volume inspect cphc-pos_pg-data --format '{{.Name}}') — untouched
  Open in browser: https://pharmacy.local

EOF
