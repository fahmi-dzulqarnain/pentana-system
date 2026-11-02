#!/bin/sh
# Container start-up for the app image: the same steps DEPLOYMENT.md runs on each deploy,
# minus the ones already done at build time (dependencies, assets, the storage symlink).
set -eu

# A fresh volume mounted over storage/ may lack the framework directories.
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

# Config is read from the container's environment, so cache it at start, not at build.
php artisan optimize --no-interaction

exec "$@"
