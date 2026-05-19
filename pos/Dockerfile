# CPHC Pharmacy POS — single-stage Alpine image.
# PHP 8.4-FPM + Caddy (HTTPS + reverse proxy) + cups-client (lp to host CUPS).
# Zero composer dependencies. No npm, no Tailwind binary, no Vite, no Node.
# Final image target: <100 MB.

FROM php:8.4-fpm-alpine

# Build-time deps for pecl/pdo_pgsql etc., installed and removed in one layer.
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev \
        libpng-dev \
        oniguruma-dev; \
    apk add --no-cache \
        caddy \
        cups-client \
        icu-libs \
        libpng \
        postgresql-libs \
        tini; \
    docker-php-ext-configure gd; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        bcmath \
        gd \
        intl \
        opcache; \
    apk del --no-network .build-deps; \
    rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

# OPcache tuned for a single-machine POS: no revalidation hits at request time.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=64'; \
        echo 'opcache.max_accelerated_files=4000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=32M'; \
    } > /usr/local/etc/php/conf.d/opcache.ini; \
    { \
        echo 'expose_php=0'; \
        echo 'html_errors=0'; \
        echo 'session.cookie_httponly=1'; \
        echo 'session.cookie_secure=1'; \
        echo 'session.cookie_samesite=Strict'; \
        echo 'session.use_strict_mode=1'; \
        echo 'session.use_only_cookies=1'; \
        echo 'session.cookie_lifetime=0'; \
        echo 'session.gc_maxlifetime=43200'; \
        echo 'open_basedir=/var/www:/tmp:/var/cphc/storage:/dev/urandom:/run/secrets'; \
        echo 'disable_functions=exec,passthru,popen,shell_exec,system,pcntl_exec'; \
        echo 'allow_url_fopen=0'; \
        echo 'allow_url_include=0'; \
    } > /usr/local/etc/php/conf.d/security.ini

# PHP-FPM pool overrides: loopback TCP, nobody user/group.
# Placed in zz-cphc.conf so it loads AFTER the default www.conf and wins.
# (TCP loopback chosen over UNIX socket because cap_drop ALL strips
#  DAC_OVERRIDE in the container, blocking even root from a 0660 socket
#  owned by nobody.)
RUN { \
        echo '[www]'; \
        echo 'user = nobody'; \
        echo 'group = nobody'; \
        echo 'listen = 127.0.0.1:9000'; \
        echo 'clear_env = no'; \
        echo 'catch_workers_output = yes'; \
        echo 'decorate_workers_output = no'; \
    } > /usr/local/etc/php-fpm.d/zz-cphc.conf

# `lp` group on Debian host is GID 7. cups-client tools in container need this
# supplementary group to access the bind-mounted /var/run/cups/cups.sock.
# `addgroup -g 7 lp || true` is a no-op if it already exists.
RUN addgroup -g 7 lp 2>/dev/null || true; \
    addgroup nobody lp || true

COPY Caddyfile /etc/caddy/Caddyfile
COPY public/           /var/www/public/
COPY backend/          /var/www/backend/
COPY frontend/         /var/www/frontend/
# Static assets live in frontend/assets/ as source of truth; Caddy serves them
# from /var/www/public/assets/ — copy them into place at image build time.
COPY frontend/assets/  /var/www/public/assets/
COPY tools/            /var/www/tools/
COPY tests/            /var/www/tests/

# Storage dir is a mounted volume at runtime; placeholder for image layout.
RUN mkdir -p /var/cphc/storage /run /var/log/caddy; \
    chown -R nobody:nobody /var/cphc /var/log/caddy

WORKDIR /var/www

# Caddy needs to bind 80/443. PHP-FPM runs as nobody.
# tini → entrypoint script → php-fpm + caddy.
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 443

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["/usr/local/bin/entrypoint.sh"]
