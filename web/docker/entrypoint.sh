#!/bin/sh
set -e

# PaaS conveniences: Render injects RENDER_EXTERNAL_URL; use it as
# APP_URL unless one was configured explicitly.
if [ -n "$RENDER_EXTERNAL_URL" ] && [ -z "$APP_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

# Wait for the database before migrating (driver-aware; compose
# healthchecks cover most of this, the loop covers PaaS cold starts).
if [ -n "$DB_HOST" ] || [ -n "$DB_URL" ]; then
    tries=0
    until php -r '
        $url = getenv("DB_URL");
        if ($url) {
            $p = parse_url($url);
            $driver = str_starts_with($p["scheme"] ?? "", "postgres") ? "pgsql" : $p["scheme"];
            $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", $driver, $p["host"], $p["port"] ?? ($driver === "pgsql" ? 5432 : 3306), ltrim($p["path"] ?? "", "/"));
            $user = $p["user"] ?? null; $pass = $p["pass"] ?? null;
        } else {
            $driver = getenv("DB_CONNECTION") ?: "mysql";
            $dsn = sprintf("%s:host=%s;port=%s", $driver, getenv("DB_HOST"), getenv("DB_PORT") ?: ($driver === "pgsql" ? 5432 : 3306));
            $user = getenv("DB_USERNAME"); $pass = getenv("DB_PASSWORD");
        }
        try { new PDO($dsn, $user, $pass); } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }
    '; do
        tries=$((tries + 1))
        [ "$tries" -gt 30 ] && echo "Database never became ready" && exit 1
        echo "Waiting for database ($tries)..."
        sleep 2
    done
fi

php artisan migrate --force
if [ "$SEED_DEMO" = "true" ]; then
    php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
