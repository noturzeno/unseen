#!/bin/sh
set -e

# Install dependencies on first run (vendor/ is a named volume, separate from the host's).
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

php artisan migrate --force

exec "$@"
