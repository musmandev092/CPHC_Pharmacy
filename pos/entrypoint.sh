#!/bin/sh
# CPHC Pharmacy POS — container entrypoint.
# Starts PHP-FPM in background, then exec's Caddy as PID 1's child.
# tini (the actual PID 1) reaps zombies and forwards signals.
set -e

# Caddy needs writable XDG dirs to persist its internal CA root cert.
# The container root FS is read-only, so point them at the /run tmpfs.
export HOME=/tmp
export XDG_DATA_HOME=/run/caddy-data
export XDG_CONFIG_HOME=/run/caddy-config
mkdir -p "$XDG_DATA_HOME" "$XDG_CONFIG_HOME"

php-fpm -F &
PHP_PID=$!

# If PHP-FPM dies, kill Caddy too so the container exits and restarts.
trap "kill -TERM $PHP_PID 2>/dev/null; exit 0" TERM INT

exec caddy run --config /etc/caddy/Caddyfile --adapter caddyfile
