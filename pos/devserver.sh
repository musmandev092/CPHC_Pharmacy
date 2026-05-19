#!/bin/sh
# Dev-only: run the POS app via PHP's built-in server.  Talks to the same
# Postgres container that docker-compose started, but no Docker rebuilds for
# CSS/HTML iteration.  Run from /home/mani/CPHC_Pharmacy/pos.
#
#   ./devserver.sh
#   → http://localhost:8888
#
# Stop with Ctrl+C.

cd "$(dirname "$0")" || exit 1

# Postgres container IP (cphc-pos-postgres-1).
DB_HOST=$(docker inspect cphc-pos-postgres-1 --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null)
DB_HOST=${DB_HOST:-127.0.0.1}

DB_PORT=5432 \
DB_NAME=cphc \
DB_USER=cphc_app \
DB_PASSWORD_FILE="$(pwd)/secrets/db_password" \
AUDIT_KEY_FILE="$(pwd)/secrets/audit_key" \
SESSION_SECRET_FILE="$(pwd)/secrets/session_secret" \
APP_TZ=Asia/Karachi \
APP_ENV=dev \
DB_HOST="$DB_HOST" \
    php \
        -d session.cookie_httponly=1 \
        -d session.cookie_secure=0 \
        -d session.cookie_samesite=Lax \
        -d session.use_strict_mode=1 \
        -d session.use_only_cookies=1 \
        -d session.gc_maxlifetime=43200 \
        -d session.cookie_lifetime=0 \
        -d disable_functions= \
        -d open_basedir= \
        -d date.timezone=Asia/Karachi \
        -S 127.0.0.1:8888 -t public public/index.php
