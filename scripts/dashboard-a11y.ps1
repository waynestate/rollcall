# Dashboard accessibility gate (axe-core in Playwright), run in Docker so no
# host browser is needed. Renders the public and internal dashboard pages
# with PHPUnit, then checks both rendered pages in light and dark mode at a
# narrow and a wide viewport. Needs only Docker. Windows PowerShell 5.1 and
# PowerShell 7; on Linux and macOS use scripts/dashboard-a11y.sh.
#
# The render step copies the repo into a container workdir (never the host
# checkout) and writes the two pages to build\dashboard-a11y on the host; the
# check step reuses the same image and named volume as scripts/a11y.ps1, so
# npm installs once and later runs reuse it.
#
# The substitute takes the register twice, once for the class and once for
# the office, and marks everybody present on both rolls.
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)

$composerImage = "composer:2.10.3"
$image = "mcr.microsoft.com/playwright:v1.49.1-noble"
$outDir = "build/dashboard-a11y"

New-Item -ItemType Directory -Force -Path $outDir | Out-Null

$cssFlag = @()
if ($env:ROLLCALL_WAYNEUI_CSS) {
    $cssFlag = @("-e", "ROLLCALL_WAYNEUI_CSS=$($env:ROLLCALL_WAYNEUI_CSS)")
}

# Render: the repo is mounted read-only at /src and copied to /w, so vendor
# lands in the copy, never in the host checkout. Composer's cache stays in a
# named volume; the two pages come out through /out, bound to $outDir here.
$inner = 'cp -r /src /w && cd /w && composer install -q --no-progress && ROLLCALL_DASHBOARD_OUT=/out vendor/bin/phpunit --filter DashboardRender'

docker run --rm `
    @cssFlag `
    -v "${PWD}:/src:ro" `
    -v rollcall-composer-cache:/composer-cache `
    -e COMPOSER_CACHE_DIR=/composer-cache `
    -v "${PWD}/${outDir}:/out" `
    $composerImage `
    sh -c $inner

if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

# Check: the same image and node_modules volume as scripts/a11y.ps1, so npm
# installs once and later runs reuse it. The named volume mounts over this
# folder; give it an empty mountpoint first (gitignored) so Docker can mount
# it over the read-only repo.
New-Item -ItemType Directory -Force -Path (Join-Path $PWD "tools/a11y/node_modules") | Out-Null

$inner = 'if [ ! -d tools/a11y/node_modules/playwright ]; then if [ -f tools/a11y/package-lock.json ]; then (cd tools/a11y && npm ci --no-audit --no-fund); else (cd tools/a11y && npm install --no-audit --no-fund); fi; fi && node tools/a11y/check.mjs --root=build/dashboard-a11y --page=index.html && node tools/a11y/check.mjs --root=build/dashboard-a11y --page=apps.html'

docker run --rm `
    -v "${PWD}:/work:ro" `
    -v "${PWD}/${outDir}:/work/${outDir}" `
    -v rollcall-a11y-node-modules:/work/tools/a11y/node_modules `
    -w /work `
    $image `
    sh -c $inner

exit $LASTEXITCODE
