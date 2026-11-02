#!/bin/sh
# Front page accessibility gate (axe-core in Playwright), run in Docker so no
# host browser is needed. Linux and macOS; on Windows use scripts/a11y.ps1.
#
# The repo is mounted read-only; only tools/a11y/node_modules lives in a named
# volume, so npm installs once and later runs reuse it.
set -eu

cd "$(dirname "$0")/.."

IMAGE="mcr.microsoft.com/playwright:v1.49.1-noble"

docker run --rm \
  -v "${PWD}:/work:ro" \
  -v rollcall-a11y-node-modules:/work/tools/a11y/node_modules \
  -w /work \
  "$IMAGE" \
  sh -c 'if [ ! -d tools/a11y/node_modules/playwright ]; then if [ -f tools/a11y/package-lock.json ]; then (cd tools/a11y && npm ci --no-audit --no-fund); else (cd tools/a11y && npm install --no-audit --no-fund); fi; fi && node tools/a11y/check.mjs --root=site --page=index.html'
