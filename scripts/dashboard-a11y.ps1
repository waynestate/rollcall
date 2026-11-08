# Dashboard accessibility gate: Preflight's a11y checker (vendored in
# tools/a11y/check.mjs from Preflight 0.3.1, pinned by tests/A11yCheckerPinTest.php),
# run in Docker so no host browser is needed. Renders the public and internal
# dashboard pages with PHPUnit, then checks both rendered pages with the same
# image, tags, color schemes and widths as the dashboard-a11y CI job, so a
# page that passes here passes CI. Needs only Docker. Windows PowerShell 5.1
# and PowerShell 7; on Linux and macOS use scripts/dashboard-a11y.sh.
#
# The render step copies the repo into a container workdir (never the host
# checkout) and writes the two pages to build\dashboard-a11y on the host; the
# check step reuses the same image and named volume as scripts/a11y.ps1, so
# npm installs once and later runs reuse it. The reports (gl-a11y-junit.xml,
# gl-a11y-report.json) land next to the rendered pages, in
# build\dashboard-a11y (gitignored). Exit 0 clean, 1 a violation or a page
# that did not load, 2 a tool or configuration error.
#
# The substitute takes the register twice, once for the class and once for
# the office, and marks everybody present on both rolls.
$ErrorActionPreference = "Stop"

Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)

$composerImage = "composer:2.10.3"
# The a11y component's image default, digest included (templates/a11y/template.yml
# of Preflight 0.3.1). Its tag sets the playwright npm version, so the package
# and the image's browsers always match.
$image = "mcr.microsoft.com/playwright:v1.65.0-noble@sha256:6e3fb709361521eb27161606e6da6d2674e5e89fa16516cb37d6fdd91372273d"
$pw = ([regex]::Match($image, 'playwright:v(\d+\.\d+\.\d+)')).Groups[1].Value
if (-not $pw) { throw "cannot read the Playwright version from the image tag $image" }
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
# it over the read-only repo. $outDir is mounted read-write, which also gives
# the checker a place for its reports, next to the pages it checks.
New-Item -ItemType Directory -Force -Path (Join-Path $PWD "tools/a11y/node_modules") | Out-Null

# The dashboard-a11y CI job's inputs, as JSON for the checker.
$config = '{"siteDir": "build/dashboard-a11y", "pages": ["index.html", "apps.html"], "tags": ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"], "colorSchemes": ["light", "dark"], "widths": [320, 1280], "axeCoreVersion": "4.14.0"}'

# The checker reads its inputs from a JSON file next to its reports.
[System.IO.File]::WriteAllText((Join-Path $PWD "$outDir/config.json"), $config, (New-Object System.Text.UTF8Encoding($false)))

# Install the checker's packages when they are missing or when the installed
# playwright stops matching the image tag; the installed version is stamped in
# node_modules/.a11y-pw-stamp after each install. Exact versions from
# tools/a11y/package.json, no install scripts: the image already carries
# Chromium for this playwright release. The inner code carries no quotes and
# no backslashes: PowerShell 5.1 mangles both when it builds docker's command
# line, and the .ps1 twin passes this very text through.
$inner = 'if [ ! -f tools/a11y/node_modules/.a11y-pw-stamp ] || [ $(cat tools/a11y/node_modules/.a11y-pw-stamp) != $A11Y_PW ]; then (cd tools/a11y && npm ci --ignore-scripts --no-audit --no-fund && printf %s $A11Y_PW > node_modules/.a11y-pw-stamp); fi
A11Y_REPORT_DIR=/work/build/dashboard-a11y node tools/a11y/check.mjs /work/build/dashboard-a11y/config.json'

docker run --rm `
    -v "${PWD}:/work:ro" `
    -v "${PWD}/${outDir}:/work/${outDir}" `
    -v rollcall-a11y-node-modules:/work/tools/a11y/node_modules `
    -v stack-npm-cache:/npm-cache `
    -e npm_config_cache=/npm-cache `
    -e "A11Y_PW=$pw" `
    -w /work `
    $image `
    sh -c $inner

exit $LASTEXITCODE
