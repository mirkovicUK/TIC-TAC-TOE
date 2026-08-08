#!/bin/sh
#
# The `app` container's entrypoint: bring the schema up to date, then hand over to
# whatever the image's CMD is (php-fpm in normal use).
#
# `set -e` is the point of the script. Without it a failed migration would be followed
# by a php-fpm that starts and serves 500s against a half-migrated database, which is a
# harder failure to diagnose than a container that refuses to start.
set -e

# `--force` because `migrate` refuses to run unprompted when APP_ENV is production, and
# there is no terminal here to confirm at. This is a single-instance SQLite deployment
# (ADR-004): one container owns the file, so there is no second replica to race with and
# migrating on start is what makes a fresh `sqlite-data` volume self-healing.
#
# It runs on every start and that is safe rather than merely tolerable — `migrate`
# applies only the migrations absent from the `migrations` table, so a restart with
# nothing new to do is a no-op.
#
# The database file is deliberately NOT created here. `config/database.php` points the
# sqlite connection at `database/database.sqlite`, and Laravel's SQLite connector
# creates it when absent; touching it first would only add a way for it to exist with
# the wrong owner.
echo "Running database migrations..."
php artisan migrate --force

# Caches built here rather than in the image, because both bake the resolved environment
# in and the environment is Compose's, not the build's. A config cache created at build
# time would freeze whatever `.env` existed then — which is why `.dockerignore` keeps
# `.env` out of the context in the first place.
#
# `config:cache` also stops Laravel reading `.env` at runtime, so every value must reach
# the container through Compose. That is the intended arrangement and is worth knowing
# before adding a variable and wondering why it is not visible.
echo "Caching configuration and routes..."
php artisan config:cache
php artisan route:cache

# `exec` so the CMD replaces this shell as PID 1 and receives Docker's signals directly.
# Without it php-fpm runs as a child of a shell that ignores SIGTERM, and `docker compose
# down` waits out its full timeout before killing the container on every deploy.
exec "$@"
