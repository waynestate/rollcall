#!/bin/sh
# Dashboard accessibility gate (axe-core in Playwright), run in Docker so no
# host browser is needed. Renders the public and internal dashboard pages
# with PHPUnit, then checks both rendered pages in light and dark mode at a
# narrow and a wide viewport. Needs only Docker. Linux and macOS; on Windows
# use scripts/dashboard-a11y.ps1, or this script from Git Bash (the MSYSTEM
# block below handles its path conversion).
#
# The render step copies the repo into a container workdir (never the host
# checkout) and writes the two pages to build/dashboard-a11y on the host; the
# check step reuses the same image and named volume as scripts/a11y.sh, so
# npm installs once and later runs reuse it.
#
# The substitute takes the register twice, once for the class and once for
# the office, and marks everybody present on both rolls.
set -eu

cd "$(dirname "$0")/.."

# Git Bash on Windows rewrites absolute-looking arguments for native programs
# (docker would turn -w /work into a Windows path). Turn that off and pass the
# Windows form of the repo path instead.
if [ -n "${MSYSTEM:-}" ]; then
  export MSYS_NO_PATHCONV=1
  REPO="$(pwd -W)"
else
  REPO="$PWD"
fi

COMPOSER_IMAGE="composer:2.10.3"
IMAGE="mcr.microsoft.com/playwright:v1.49.1-noble"
OUT_DIR="build/dashboard-a11y"

mkdir -p "$OUT_DIR"

CSS_FLAG=""
if [ -n "${ROLLCALL_WAYNEUI_CSS:-}" ]; then
  CSS_FLAG="-e ROLLCALL_WAYNEUI_CSS=${ROLLCALL_WAYNEUI_CSS}"
fi

# Render: the repo is mounted read-only at /src and copied to /w, so vendor
# lands in the copy, never in the host checkout. Composer's cache stays in a
# named volume; the two pages come out through /out, bound to $OUT_DIR here.
docker run --rm \
  ${CSS_FLAG} \
  -v "${REPO}:/src:ro" \
  -v rollcall-composer-cache:/composer-cache \
  -e COMPOSER_CACHE_DIR=/composer-cache \
  -v "${REPO}/${OUT_DIR}:/out" \
  "$COMPOSER_IMAGE" \
  sh -c 'cp -r /src /w && cd /w && composer install -q --no-progress && ROLLCALL_DASHBOARD_OUT=/out vendor/bin/phpunit --filter DashboardRender'

# Check: the same image and node_modules volume as scripts/a11y.sh, so npm
# installs once and later runs reuse it. The named volume mounts over this
# folder; give it an empty mountpoint first (gitignored) so Docker can mount
# it over the read-only repo.
mkdir -p tools/a11y/node_modules

docker run --rm \
  -v "${REPO}:/work:ro" \
  -v "${REPO}/${OUT_DIR}:/work/${OUT_DIR}" \
  -v rollcall-a11y-node-modules:/work/tools/a11y/node_modules \
  -w /work \
  "$IMAGE" \
  sh -c 'if [ ! -d tools/a11y/node_modules/playwright ]; then if [ -f tools/a11y/package-lock.json ]; then (cd tools/a11y && npm ci --no-audit --no-fund); else (cd tools/a11y && npm install --no-audit --no-fund); fi; fi && node tools/a11y/check.mjs --root=build/dashboard-a11y --page=index.html && node tools/a11y/check.mjs --root=build/dashboard-a11y --page=apps.html'
