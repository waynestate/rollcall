# Rollcall collector image: a plain PHP CLI that answers present from any Docker host.
# Every setting arrives at run time with -e or --env-file; nothing secret is baked in.

# The base is pinned here so CI can swap in its dependency-proxy path.
ARG PHP_IMAGE=php:8.5-cli-trixie@sha256:9236fd373d3bcda0659cf94838a744bd79791419292958963f8c6b34a0071554

# Composer stage: resolve the production dependencies away from the final image.
FROM composer:2 AS builder
# Everything lives in /app, so the relative paths in .env.example keep working.
WORKDIR /app
# The lock file keeps installs repeatable; the wildcard copes when a repo has none.
COPY composer.json composer.lock* ./
# --no-dev keeps tests and lint tools out; --optimize-autoloader trims startup cost.
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

# Build args do not cross stages, so re-declare it for the second FROM.
ARG PHP_IMAGE
# Final stage: the same PHP line the apps run, so the collector sees the same engine.
FROM ${PHP_IMAGE}
# No PHP extension beyond the base image is required (composer.json requires none).
WORKDIR /app
# Production dependencies only, built in the first stage.
COPY --from=builder /app/vendor ./vendor
# The core the CLI loads.
COPY src/ ./src/
# The entry point script.
COPY bin/ ./bin/
# The package configuration.
COPY config/ ./config/
# Any shipped resources.
COPY resources/ ./resources/
# The manifest, so the installed packages match what was built.
COPY composer.json ./
# Numeric user with no home and no shell: the collector writes only its snapshot folder.
RUN groupadd --gid 10001 rollcall \
    && useradd --uid 10001 --gid 10001 --no-create-home --shell /usr/sbin/nologin rollcall \
    && mkdir -p var/snapshots \
    && chown 10001:10001 var/snapshots
# The snapshot folder is the only state; declare it a volume so mounts are obvious.
VOLUME /app/var/snapshots
# Drop privileges for good: every command from here on runs as 10001.
USER 10001:10001
# `docker run <image> scan` (or report, drift, advisories, prune) from here on.
ENTRYPOINT ["php", "bin/rollcall"]
# The default command; any other command from the CLI replaces it.
CMD ["report"]
