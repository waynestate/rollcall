#!/bin/sh
# Dashboard accessibility gate: Preflight's a11y checker (vendored in
# tools/a11y/check.mjs from Preflight 0.3.1, pinned by tests/A11yCheckerPinTest.php),
# run in Docker so no host browser is needed. Renders the public, internal
# and empty-state dashboard pages with PHPUnit, from Laravel and from the
# raw-PHP entry point (six pages), then checks all six with the
# same image, tags, color schemes and widths as the dashboard-a11y CI job, so a
# page that passes here passes CI. Needs only Docker. Linux and macOS; on
# Windows use scripts/dashboard-a11y.ps1, or this script from Git Bash (the
# MSYSTEM block below handles its path conversion).
#
# The render step copies the repo into a container workdir (never the host
# checkout) and writes the six pages to build/dashboard-a11y on the host; the
# check step reuses the same image and named volume as scripts/a11y.sh, so
# npm installs once and later runs reuse it. The reports (gl-a11y-junit.xml,
# gl-a11y-report.json) land next to the rendered pages, in build/dashboard-a11y
# (gitignored). Exit 0 clean, 1 a violation or a page that did not load, 2 a
# tool or configuration error.
#
# The substitute takes the register three times, once for the class, once for
# the office and once for the empty room, then does it all again in the raw-PHP
# room down the hall, and marks everybody present on every roll.
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
# The a11y component's image default, digest included (templates/a11y/template.yml
# of Preflight 0.3.1). Its tag sets the playwright npm version, so the package
# and the image's browsers always match.
IMAGE="mcr.microsoft.com/playwright:v1.65.0-noble@sha256:6e3fb709361521eb27161606e6da6d2674e5e89fa16516cb37d6fdd91372273d"
PW=$(printf '%s\n' "$IMAGE" | sed -n 's/^.*playwright:v\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\).*$/\1/p')
OUT_DIR="build/dashboard-a11y"
# The pages link the bundled WayneUI stylesheet, so the render always styles them.
WAYNEUI_CSS="/assets/wayne-ui/wayne-ui.css"

mkdir -p "$OUT_DIR"

# Render: the repo is mounted read-only at /src and copied to /w, so vendor
# lands in the copy, never in the host checkout. Composer's cache stays in a
# named volume; the six pages come out through /out, bound to $OUT_DIR here.
docker run --rm \
  -e ROLLCALL_WAYNEUI_CSS="$WAYNEUI_CSS" \
  -v "${REPO}:/src:ro" \
  -v rollcall-composer-cache:/composer-cache \
  -e COMPOSER_CACHE_DIR=/composer-cache \
  -v "${REPO}/${OUT_DIR}:/out" \
  "$COMPOSER_IMAGE" \
  sh -c 'cp -r /src /w && cd /w && composer install -q --no-progress && ROLLCALL_DASHBOARD_OUT=/out vendor/bin/phpunit --filter DashboardRender'

# The pages link the bundled stylesheet, so the checker's local server must serve it.
rm -rf "$OUT_DIR/assets"
mkdir -p "$OUT_DIR/assets"
cp -R site/assets/wayne-ui "$OUT_DIR/assets/"

# Check: the same image and node_modules volume as scripts/a11y.sh, so npm
# installs once and later runs reuse it. The named volume mounts over this
# folder; give it an empty mountpoint first (gitignored) so Docker can mount
# it over the read-only repo. $OUT_DIR is mounted read-write, which also gives
# the checker a place for its reports, next to the pages it checks.
mkdir -p tools/a11y/node_modules

# The dashboard-a11y CI job's inputs, as JSON for the checker.
CONFIG='{"siteDir": "build/dashboard-a11y", "pages": ["index.html", "apps.html", "empty.html", "raw.html", "raw-apps.html", "raw-empty.html"], "tags": ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"], "colorSchemes": ["light", "dark"], "widths": [320, 1280], "axeCoreVersion": "4.14.0"}'

# The checker reads its inputs from a JSON file next to its reports.
printf '%s\n' "$CONFIG" > "$OUT_DIR/config.json"

# Install the checker's packages when they are missing or when the installed
# playwright stops matching the image tag; the installed version is stamped in
# node_modules/.a11y-pw-stamp after each install. Exact versions from
# tools/a11y/package.json, no install scripts: the image already carries
# Chromium for this playwright release. The inner code carries no quotes and
# no backslashes: PowerShell 5.1 mangles both when it builds docker's command
# line, and the .ps1 twin passes this very text through.
INNER='if [ ! -f tools/a11y/node_modules/.a11y-pw-stamp ] || [ $(cat tools/a11y/node_modules/.a11y-pw-stamp) != $A11Y_PW ]; then (cd tools/a11y && npm ci --ignore-scripts --no-audit --no-fund && printf %s $A11Y_PW > node_modules/.a11y-pw-stamp); fi
A11Y_REPORT_DIR=/work/build/dashboard-a11y node tools/a11y/check.mjs /work/build/dashboard-a11y/config.json'

docker run --rm \
  -v "${REPO}:/work:ro" \
  -v "${REPO}/${OUT_DIR}:/work/${OUT_DIR}" \
  -v rollcall-a11y-node-modules:/work/tools/a11y/node_modules \
  -v stack-npm-cache:/npm-cache \
  -e npm_config_cache=/npm-cache \
  -e "A11Y_PW=$PW" \
  -w /work \
  "$IMAGE" \
  sh -c "$INNER"
