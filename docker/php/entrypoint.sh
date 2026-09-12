#!/bin/sh
#
# Booking calendar — php container entrypoint.
#
# Bootstraps the Symfony app only when the container is actually starting
# php-fpm. Every other command (bin/console, composer, sh, ...) is exec'd
# immediately with no side effects, so `make console` / `make composer` stay
# fast and never touch the database.
set -eu

APP_ROOT=/var/www/html
APP_ENV="${APP_ENV:-prod}"

log() {
    echo "[entrypoint] $*"
}

# Resolve the database endpoint from DATABASE_URL, falling back to the compose
# service name and Postgres' port. parse_url() keeps us honest about custom
# hosts/ports - a managed database (Neon) is a different host entirely.
db_endpoint() {
    php -r '
        $url = getenv("DATABASE_URL") ?: "";
        $parts = $url !== "" ? parse_url($url) : [];
        printf("%s %d", $parts["host"] ?? "database", $parts["port"] ?? 5432);
    '
}

# Bounded TCP wait. No extra packages: the PHP binary is already here.
wait_for_db() {
    host="$1"
    port="$2"
    attempt=1
    max_attempts=60

    log "waiting for database at ${host}:${port} (up to ${max_attempts}s)"
    while [ "$attempt" -le "$max_attempts" ]; do
        if php -r '
            $sock = @fsockopen($argv[1], (int) $argv[2], $errno, $errstr, 2);
            if ($sock === false) { exit(1); }
            fclose($sock);
            exit(0);
        ' "$host" "$port" 2>/dev/null; then
            log "database is accepting connections after ${attempt} attempt(s)"
            return 0
        fi
        attempt=$((attempt + 1))
        sleep 1
    done

    log "ERROR: database at ${host}:${port} did not become reachable"
    return 1
}

# Bootstrap only when the container is actually starting the application:
# php-fpm alone (compose), or nginx + php-fpm under supervisord (the `render`
# stage on a single-container platform).
case "${1:-}" in
    php-fpm|supervisord) ;;
    *) exec "$@" ;;
esac

cd "$APP_ROOT"

log "APP_ENV=${APP_ENV} APP_DEBUG=${APP_DEBUG:-0}"

db_endpoint_value="$(db_endpoint)"
db_host="${db_endpoint_value% *}"
db_port="${db_endpoint_value#* }"
wait_for_db "$db_host" "$db_port"

if [ "$APP_ENV" = "dev" ] && [ ! -f vendor/autoload.php ]; then
    log "vendor/autoload.php missing — installing dev dependencies"
    composer install --no-interaction --no-progress
fi

log "clearing and warming the ${APP_ENV} cache"
php bin/console cache:clear --no-interaction
php bin/console cache:warmup --no-interaction

log "running doctrine migrations"
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

log "fixing ownership of var/"
mkdir -p var/cache var/log
chown -R www-data:www-data var

# nginx has no equivalent of env(), and a platform only tells you the port at
# runtime, so the single-container vhost ships as a template. Only the literal
# ${PORT} token is replaced; nginx's own $variables are left alone.
if [ -f /etc/nginx/templates/default.conf.template ]; then
    log "rendering the nginx vhost on port ${PORT:-8080}"
    sed "s|\${PORT}|${PORT:-8080}|g" /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf
    nginx -t -q
fi

log "starting: $*"
exec "$@"
