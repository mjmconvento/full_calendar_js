# syntax=docker/dockerfile:1
#
# Booking calendar — Symfony 8.1 / PHP 8.5 image.
#
# Stage graph:
#   base ---> vendor ---> assets ---> prod   (php-fpm runtime, compose `php` service)
#     \                        \       \
#      \                        \       `--> render  (nginx + php-fpm in ONE container,
#       \                        \                    answering HTTP on $PORT; Render)
#        \                        `--> web     (nginx + this build's public/; compose)
#         `--> dev                             (bind-mounted source, dev deps at runtime)
#
# NOTE: `render` is deliberately the LAST stage, because a hosting platform's
# bare `docker build .` builds the last stage and that is the one it can run.
# Every compose service pins its stage explicitly (`target: prod`, `target: web`,
# `target: dev`), so always pass `--target` when building by hand.

# ---------------------------------------------------------------------------
# base — runtime extensions and configuration shared by every stage
# ---------------------------------------------------------------------------
FROM php:8.5.10-fpm-alpine3.24 AS base

# Runtime shared objects only. `fcgi` provides cgi-fcgi, used by HEALTHCHECK.
#
# Not installed here, because php:8.5 already has them:
#   opcache    — compiled in statically and enabled by default in PHP 8.5
#                (`php -m` lists "Zend OPcache" and there is no opcache.so),
#                so `docker-php-ext-install opcache` builds no shared module
#                and its `make install` fails on `cp modules/*`.
#                docker/php/opcache.ini still configures it.
#   pdo_sqlite — compiled in; used by the test suite.
RUN set -eux; \
    apk add --no-cache \
        fcgi \
        icu-libs \
        libpq \
        libzip \
        oniguruma \
    ; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
    ; \
    docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_pgsql \
        zip \
    ; \
    yes '' | pecl install apcu; \
    docker-php-ext-enable apcu; \
    pecl clear-cache; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear; \
    php -m

COPY docker/php/php.ini     $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini
COPY docker/php/www.conf    /usr/local/etc/php-fpm.d/zz-www.conf

# Composer binary, taken from the official image; no PHP-level dependency.
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor — production dependencies + optimized autoloader
# ---------------------------------------------------------------------------
FROM base AS vendor

ENV APP_ENV=prod \
    APP_DEBUG=0

# `composer.*` covers composer.json + composer.lock; `symfony.lock*` tolerates a
# missing symfony.lock (glob matching zero files is not an error for COPY as long
# as at least one pattern matches, which composer.* guarantees).
COPY composer.* symfony.lock* ./

# --no-scripts: the Symfony auto-scripts boot the kernel, which cannot happen
# before the source is in place. The entrypoint warms the cache at runtime with
# the real environment anyway.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

# Application source, then a fully authoritative autoloader over the real tree.
COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------------------------------------------------------------------------
# assets — AssetMapper output (no Node.js anywhere in this project)
# ---------------------------------------------------------------------------
FROM vendor AS assets

# Booting the kernel needs APP_SECRET and DATABASE_URL to be set, but no
# database is contacted and neither value is a real credential. They are passed
# per-command so nothing placeholder-shaped is baked into an image layer.
# importmap:install is a no-op unless assets/vendor was excluded from the build
# context; asset-map:compile fails loudly if an import cannot be resolved.
RUN set -eux; \
    build_env="APP_SECRET=build-time-placeholder DATABASE_URL=sqlite:///%kernel.project_dir%/var/build.db"; \
    env $build_env php bin/console importmap:install; \
    env $build_env php bin/console asset-map:compile; \
    rm -rf var/cache/prod var/build.db
# Produces: /var/www/html/assets/vendor  and  /var/www/html/public/assets

# ---------------------------------------------------------------------------
# prod — the shipped runtime image
# ---------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY . .
COPY --from=vendor /var/www/html/vendor        ./vendor
COPY --from=assets /var/www/html/public/assets ./public/assets
COPY --from=assets /var/www/html/assets/vendor ./assets/vendor

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint

RUN set -eux; \
    chmod +x /usr/local/bin/entrypoint; \
    rm -rf tests docker bower_components; \
    mkdir -p var/cache var/log; \
    chown -R www-data:www-data var

# php-fpm answers its own ping path (see docker/php/www.conf).
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD REQUEST_METHOD=GET \
        SCRIPT_NAME=/fpm-ping \
        SCRIPT_FILENAME=/fpm-ping \
        cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# web — nginx front end holding this build's compiled public/ directory
# ---------------------------------------------------------------------------
# Baking public/ into the nginx image (instead of sharing a named volume with
# the php container) means the two images are always in step: a rebuild ships
# the new digest-hashed assets, with no volume to re-seed by hand.
FROM nginx:1.31-alpine AS web

COPY docker/nginx/conf.d/app.conf /etc/nginx/conf.d/default.conf
COPY --from=assets /var/www/html/public /var/www/html/public

EXPOSE 80

# ---------------------------------------------------------------------------
# dev — source is bind-mounted, dev dependencies installed by the entrypoint
# ---------------------------------------------------------------------------
FROM base AS dev

ENV APP_ENV=dev \
    APP_DEBUG=1

# Xdebug 3 is built in but NOT loaded: the ini lives outside conf.d.
# `make xdebug-on` copies it into conf.d and restarts php-fpm.
RUN set -eux; \
    apk add --no-cache --virtual .xdebug-deps $PHPIZE_DEPS linux-headers; \
    yes '' | pecl install xdebug; \
    pecl clear-cache; \
    apk del --no-network .xdebug-deps; \
    rm -rf /tmp/pear; \
    { \
        echo 'zend_extension=xdebug'; \
        echo 'xdebug.mode=debug,develop'; \
        echo 'xdebug.start_with_request=yes'; \
        echo 'xdebug.client_host=host.docker.internal'; \
        echo 'xdebug.client_port=9003'; \
        echo 'xdebug.idekey=booking-calendar'; \
        echo 'xdebug.log_level=0'; \
    } > /usr/local/etc/php/xdebug.ini.disabled

# Dev opcache/error behaviour on top of the shared ini files.
RUN { \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
        echo 'opcache.preload='; \
        echo 'display_errors=On'; \
        echo 'zend.assertions=1'; \
    } > "$PHP_INI_DIR/conf.d/zzz-dev.ini"

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# render — single-container deployable (Render, Cloud Run, Container Apps)
# ---------------------------------------------------------------------------
# The `prod` stage speaks FastCGI on 9000 and only nginx can talk to it. A free
# PaaS gives you ONE container and routes HTTP to ONE injected port, so a
# platform pointed at `prod` would connect, receive FastCGI bytes it cannot
# parse, and fail every health check. This stage puts nginx in front of
# php-fpm over loopback, both under supervisord, and answers HTTP on ${PORT}.
#
# compose never builds this stage. Keep it last (see the header).
# ---------------------------------------------------------------------------
FROM prod AS render

# A documented default, not the real value: the platform overrides it
# (Render injects 10000) and the entrypoint renders it into the vhost.
ENV PORT=8080

RUN set -eux; \
    apk add --no-cache nginx supervisor; \
    # The package's default vhost listens on 80; ours is rendered at start.
    rm -f /etc/nginx/http.d/default.conf; \
    mkdir -p /etc/nginx/templates /run/nginx; \
    # nginx's own logs go to the container's streams, next to php-fpm's.
    sed -i \
        -e 's|error_log /var/log/nginx/error.log|error_log /dev/stderr|' \
        -e 's|access_log /var/log/nginx/access.log|access_log /dev/stdout|' \
        /etc/nginx/nginx.conf

COPY docker/nginx/render.conf.template /etc/nginx/templates/default.conf.template
COPY docker/php/supervisord.conf       /etc/supervisord.conf

# Sized for Render's Free plan: 0.1 CPU / 512 MB for the whole container. The
# shared ini files reserve 256 MB of opcache and allow 20 workers at 256 MB
# each, which OOM-kills this instance and surfaces as intermittent 502s.
COPY docker/php/render-php.ini $PHP_INI_DIR/conf.d/zzz-render.ini
COPY docker/php/render-fpm.conf /usr/local/etc/php-fpm.d/zzz-render.conf

EXPOSE 8080

# Over HTTP, through nginx AND php-fpm: the `prod` stage's FastCGI ping would
# report healthy while nginx was dead.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -qO- "http://127.0.0.1:${PORT}/healthz" >/dev/null || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
