#!/bin/sh
set -e

# Wait for MySQL before migrating (compose healthcheck covers most of
# this; the loop covers cold starts on PaaS where there is none).
if [ -n "$DB_HOST" ]; then
    tries=0
    until php -r 'try { new PDO(sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); } catch (Throwable $e) { exit(1); }'; do
        tries=$((tries + 1))
        [ "$tries" -gt 30 ] && echo "MySQL never became ready" && exit 1
        echo "Waiting for MySQL ($tries)..."
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
