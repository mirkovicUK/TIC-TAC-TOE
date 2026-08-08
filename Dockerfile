# syntax=docker/dockerfile:1

# Two production images from one file (task 13.1, ADR-011): `app` runs the PHP through
# php-fpm, `web` runs Caddy in front of it terminating TLS and serving the static
# assets. ADR-011 records why that is two containers rather than one embedded-PHP
# container, including that both would work at this scale.
#
# Four stages. The two that do the expensive work — Composer and the asset build — are
# discarded, so neither Composer itself nor any Node runtime reaches production.
#
#   vendor ──▶ assets ──▶ app ──▶ web
#
# The `vendor` ──▶ `assets` edge is load-bearing and is the one thing here that is not
# obvious. `resources/css/app.css` carries
# `@source '../../vendor/laravel/framework/.../Pagination/resources/views/*.blade.php'`,
# so Tailwind scans a path inside `vendor` for class names. Build the assets without it
# and Tailwind does NOT fail — it silently emits different CSS. Composer therefore runs
# first and the one path that stylesheet names is carried into the asset stage.
#
# Measured, on a cleared Blade cache: 17.33 kB with that path present, 11.94 kB without,
# and the asset hash changes with it. An earlier version of this comment cited 41.91 kB
# and 38.94 kB, which were measured against a *dirty* local Blade cache and were wrong —
# see the note on `storage/framework/views` in the asset stage for why that inflated the
# figure. The structural conclusion held; the numbers did not.
#
# The image's CSS is deliberately NOT identical to a host build, and that is worth
# stating so nobody treats the difference as a defect. The image emits 14.95 kB against
# a clean host build's 17.33 kB, and the class sets were compared rather than eyeballed:
# 146 selectors against 162, the image a strict SUBSET with nothing of its own. All 16
# extras are generic single-word utilities — `absolute`, `static`, `table`, `filter`,
# `container`, `relative` and the like — that Tailwind emits because those words occur in
# PHP source, tests and markdown which `.dockerignore` keeps out of the context. They are
# scanner false positives, not styles this application uses.


# ---------------------------------------------------------------------------------
# Stage 1: Composer dependencies, production only.
# ---------------------------------------------------------------------------------
# On the php:8.5 base rather than the `composer:2` image on purpose. `composer.json`
# requires `php: ^8.5`, and `composer install` enforces that against the PHP running
# it — so installing on the composer image's own PHP would either fail the platform
# check or have to be waved through with `--ignore-platform-reqs`, which is how a
# dependency resolved for the wrong runtime reaches production.
FROM php:8.5-fpm AS vendor

# `unzip` and `git` are Composer's, not the application's: without unzip it falls back
# to a slower path, and without git it cannot install a package that resolves to a
# source reference.
RUN apt-get update \
 && apt-get install --no-install-recommends -y git unzip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# The lock file and manifest alone, before the source, so this layer is cached on every
# build that changes only code. `--no-scripts` because the `post-autoload-dump` hook
# runs `artisan package:discover`, which boots the framework — and at this point there
# is no application here to boot.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

# Now the source, and the autoloader generated against it. `--classmap-authoritative`
# makes the classmap the only lookup path: it is the faster production form and it also
# means a class that was not present at build time cannot be autoloaded at runtime.
#
# `--no-scripts` again, and for a reason worth stating because removing it fails the
# build: the `post-autoload-dump` hook runs `artisan package:discover`, which writes
# `bootstrap/cache/packages.php` and errors with "The bootstrap/cache directory must be
# present and writable" when it is absent — and `.dockerignore` excludes that directory
# precisely because it is generated state. What this stage exists to produce is the
# autoloader, not the package manifest, and the manifest would not survive anyway: the
# `app` stage copies its source from the build context, so nothing written here outside
# `vendor` reaches it. Laravel rebuilds the manifest on first boot into the writable
# `bootstrap/cache` the `app` stage creates.
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts


# ---------------------------------------------------------------------------------
# Stage 2: the front-end build. This is where Node lives and dies.
# ---------------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

# `npm ci` and never `npm install`: ci installs exactly the lock file and fails if the
# manifest and lock disagree, which is what makes this reproducible.
#
# `--ignore-scripts` matches `composer setup`. It costs nothing here — the pinned
# `playwright` devDependency ships no install hook, and the browsers it would otherwise
# fetch are a test concern that must not reach a production build.
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY vite.config.ts tsconfig.json ./
COPY resources ./resources

# The Tailwind `@source` dependency described at the top of this file. Only the one
# framework path is copied rather than all 156 MB of `vendor`, because that is the only
# part the stylesheet names.
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
                   ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views

# The stylesheet's second `@source` is `storage/framework/views`, Blade's compiled-view
# cache, which is gitignored and empty here. This `mkdir` is insurance rather than a
# requirement: removing the directory entirely and rebuilding on the host produced the
# byte-identical stylesheet, same hash, so Tailwind treats an unmatched `@source` glob as
# matching nothing rather than as an error. It is kept because it costs one empty layer
# and would absorb a future Tailwind that decided to be stricter.
#
# That directive is also why the figures above had to be measured twice. It makes the
# stylesheet depend on which Blade views a machine happens to have rendered — 45 stale
# entries locally inflated the build from 17.33 kB to 41.91 kB. The image is unaffected,
# because the cache is always empty here, but a local `npm run build` and a CI one can
# legitimately differ. Making the asset build reproducible would mean narrowing that
# `@source` in `resources/css/app.css`, which is application source and not this task's.
RUN mkdir -p storage/framework/views

RUN npm run build


# ---------------------------------------------------------------------------------
# Stage 3: the `app` image — php-fpm and the application.
# ---------------------------------------------------------------------------------
FROM php:8.5-fpm AS app

# Only the two extensions the base image lacks. Verified against `php:8.5-fpm` rather
# than assumed: it already provides mbstring, dom, xml, curl, fileinfo, tokenizer,
# sqlite3 and pdo_sqlite from CI's list, leaving intl and bcmath to build.
#
# Neither is actually required, and that is worth recording rather than quietly relying
# on. `composer why ext-intl` and `composer why ext-bcmath` both report no installed
# package depending on them, and nothing under `app/`, `config/`, `routes/`, `database/`
# or `bootstrap/` calls a function from either. They are built because CI's `setup-php`
# step installs them, so building them keeps the image's extension set identical to the
# one every test result in this repository was produced against. Narrowing both to the
# set that is genuinely needed is a real improvement and a separate decision: the suite
# cannot verify it, since the host running the tests has both, so the evidence would have
# to come from exercising the image itself.
#
# OPcache is deliberately absent from that command. It is already compiled into this
# base image — `php -v` reports "Zend OPcache v8.5.9" and there is no
# `/usr/src/php/ext/opcache` to build from — so `docker-php-ext-install opcache` fails
# with `cp: cannot stat 'modules/*'`. It needs configuration, below, and nothing else.
#
# `libicu-dev` is installed and then LEFT IN PLACE, which costs about 90 MB and is the
# deliberate choice over reclaiming it. An earlier version of this stage did reclaim it,
# using the official PHP image's `apt-mark` idiom: record what was manually installed,
# mark everything automatic, then re-mark whatever the built `.so` files link against so
# that `apt-get purge --auto-remove` drops the toolchain and keeps the libraries. It did
# not work here — the build failed on the `php -m | grep intl` assertion below, so the
# purge had taken libicu with it and `intl` no longer loaded.
#
# The assertion is why that was a failed build rather than a broken image, and it is the
# reason to keep it. Without those two greps the stage would have succeeded and produced
# an image whose `intl` was missing, which nothing downstream checks and no test could
# catch: the suite runs on a host that has the extension.
#
# Chasing the idiom to ground was not worth more of the budget than 90 MB on an image
# that is already about 500 MB, for a deployment of one instance with no registry to
# push through. If image size ever matters, the fix is to derive the runtime package from
# `apt-cache depends libicu-dev` and `apt-mark manual` it by name before purging — not to
# reinstate a pipeline that silently produced the wrong result.
# `libfcgi-bin` is not an application dependency: it provides `cgi-fcgi`, and the Compose
# healthcheck is the only thing that uses it. php-fpm speaks FastCGI and nothing else, so
# the `curl` this image already carries cannot probe it — a healthcheck has to either
# speak FastCGI or go around through the `web` container, and going around makes `app`'s
# health depend on Caddy. `command -v` is asserted for the same reason as the two `php -m`
# greps below: a missing probe would leave a healthcheck that fails for the wrong reason.
RUN set -eux; \
    apt-get update; \
    apt-get install --no-install-recommends -y libicu-dev libfcgi-bin; \
    docker-php-ext-install -j"$(nproc)" intl bcmath; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -q '^intl$'; \
    php -m | grep -q '^bcmath$'; \
    command -v cgi-fcgi >/dev/null

# `validate_timestamps=0` is what makes opcache worth enabling and is only safe because
# the code is immutable in the image: PHP never stats a file to ask whether it changed,
# so a code change requires a new image and a restart, which is exactly how this is
# deployed. Do not set this in a development container.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=20000'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# The application, then the two artefacts from the discarded stages. `vendor` comes from
# stage 1 with its authoritative classmap already generated; `public/build` from stage 2.
COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# `.dockerignore` excludes the writable directories under `storage` along with their
# contents, so the skeleton is recreated here. Laravel writes into all of these at
# runtime and fails on the first request if any is missing.
#
# `www-data` owns them and nothing else: the code is read-only to the process that runs
# it, which means a compromised request cannot rewrite the application it is running.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        storage/app/private \
        storage/app/public \
        bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

# The database directory, owned by the runtime user because SQLite writes the file AND
# needs to create `-wal` and `-shm` siblings next to it under the WAL journal mode
# `config/database.php` sets. A writable file in a read-only directory is not enough.
#
# Compose mounts the `sqlite-data` volume here, and Docker seeds an empty volume from
# the image's directory — so this ownership is what the volume inherits on first run.
RUN mkdir -p /var/www/html/database \
 && chown -R www-data:www-data /var/www/html/database

COPY docker/app-entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

# php-fpm serves FastCGI here and this port is deliberately NOT published by Compose.
# `TrustProxies` is configured with `*`, which means the application believes
# `X-Forwarded-For` from any peer that can open a connection to this port — acceptable
# only while the sole thing able to reach it is the `web` container. Publishing 9000, or
# adding another service that can reach `app`, invalidates that and with it the IP-keyed
# half of the rate limiter. See the Rate limits section of the design.
EXPOSE 9000

USER www-data

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]


# ---------------------------------------------------------------------------------
# Stage 4: the `web` image — Caddy, TLS, and the static assets.
# ---------------------------------------------------------------------------------
# `public/` is copied in rather than shared through a volume, which is the whole reason
# this stage exists. Caddy needs the document root to serve the static files and to
# resolve which requests are PHP; giving it its own copy at build time means there is no
# shared code volume between the two containers to configure, and no way for the two to
# disagree about what they are serving.
#
# The copy comes from `app` rather than from `assets` so that what Caddy serves is
# byte-identical to what the running application was built with — one source, not two.
#
# No Caddyfile here: task 13.2 writes the production one and Compose mounts it, because
# it names the hostname and that is deployment configuration rather than image content.
FROM caddy:2-alpine AS web

COPY --from=app /var/www/html/public /srv/public
