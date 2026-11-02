# MAINTAINING: taking attendance of the attendance-taker

How to build, test, release and keep Rollcall current. Rollcall is the fleet census: every app answers "present" with
its PHP image, Laravel and auth-package versions and its PROCTOR result, checked against CREED's policy, and the
report shows the burn-down of unsupported versions. Somebody has to make sure the roll book itself is up to date.
That somebody is you.

## What Rollcall is and what it depends on

- `waynestate/rollcall` on Packagist, with the CLI `bin/rollcall` (`scan`, `report`) and an optional Laravel
  provider. Metrics in Prometheus format, off by default.
- Runtime dependencies: `symfony/console`, `symfony/http-client`, `composer/semver`, `nyholm/psr7`,
  `promphp/prometheus_client_php`, PSR interfaces. No other stack package at runtime (WayneUI is suggested for the
  dashboard).
- **Its policy comes from CREED.** `src/Creed/` is a generated copy of CREED's reader and `policy.json`
  (namespace `Waynestate\Rollcall\Creed`). Never hand-edit it. `tests/fixtures/policy.json` is a separate snapshot used
  only by tests.
- It reads repositories through a GitLab bot token (project or group bot, `read_api` only, with an expiry) requested
  from the GitLab administrator. The token is configuration, never committed; until it exists Rollcall runs on its
  fixtures.

## Where it lives

Development happens on Wayne State's GitLab. This GitHub repository is a read-only release mirror: `main` and tags are
copied here automatically, and changes, issues and merge requests go to GitLab first. Nobody pushes to GitHub
directly.

## Build and test

| Task | Command |
|---|---|
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Fix code style | `composer fix` |
| Check the CREED copy, Windows | `$env:CREED_PATH = "..\creed"; composer creed-copy:check` |
| Check the CREED copy, Linux and macOS | `CREED_PATH=../creed composer creed-copy:check` |
| Regenerate the front page versions table | `composer site:versions` |
| Front page accessibility gate | `scripts/a11y.sh` (Linux and macOS) or `scripts/a11y.ps1` (Windows); both run in Docker |

The README's "Quick start" runs `scan` and `report` against fixtures on Windows (PowerShell `$env:ROLLCALL_*`
settings) and on Linux and macOS (`export ROLLCALL_*`).

With only Docker:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

`composer ci` runs `composer validate --strict`, PHPUnit, Pint, PHPStan, `composer audit` and the front page
versions table check. It does not run the CREED copy check, which needs a CREED checkout (it exits 2 without
`CREED_PATH`).

## CI

- **GitLab:** Preflight's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, plus a `composer-ci` job.
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`.
- Green means `composer ci` passed on all three PHP versions on both.

## Refreshing the CREED copy

Whenever CREED's policy or reader changes:

```
php ../creed/bin/export.php --namespace='Waynestate\Rollcall\Creed' --to=src/Creed
```

Then run the copy check and `composer ci`, and commit with the CREED commit in the message.

## Public output stays anonymous

The per-app list a scan produces is internal data. Nothing in this repo (fixtures, docs, screenshots) may name a real
app, owner or finding, and a test refuses `rank`, `owner` or `score` fields in the JSON. Exit codes are 0 or 2, never
1, so a scan never looks like a failed build.

## Releasing

1. Move the lines under `## [Unreleased]` in `CHANGELOG.md` (Keep a Changelog) to a new `## [X.Y.Z] - YYYY-MM-DD`
   heading.
2. Bump `ReportBuilder::VERSION` in the release commit.
3. `composer ci` and the CREED copy check green locally, then both pipelines green for that commit.
4. Run a secrets scan of the history and check that nothing internal (server names, people, app names, IDs) is in the
   tree.
5. Tag `X.Y.Z` (no `v`) on GitLab. The mirror carries the tag to GitHub, and Packagist picks it up from there.
6. A release needs a maintainer's approval. Never move or delete a published tag.

## The front page

`site/` is the static front page, for `https://rollcall.apps.wayne.edu/` on Citadel, served through a small raw-PHP
`deploy/` layer generated with Squire. Run Rollcall's own `/metrics` separately from HELMET's; the routes collide.

The supported-versions table on the front page is generated data. It sits between the `versions-table` markers in
`site/index.html` and `site/index.md`; every row comes from CREED's bundled `policy.json`, judged by
`src/Creed/Policy.php`, so no support date is typed by hand. When the bundled policy changes, regenerate with
`composer site:versions` (which also rewrites the agent-ready twins) and commit the result.

`composer ci` runs the matching check, `composer site:versions-check`. The generation date is stored in the start
marker, and the check re-judges the rows as of that stored date, so CI goes red only when the policy or the table
itself drifts, not because a day passed. The Squire agent-ready kit strips HTML comments from the Markdown twin,
so the committed `site/index.md` carries the table without markers; the check accepts both forms.

### Accessibility gate

The front page must pass an accessibility gate: axe-core with the WCAG 2.2 AA tag set (`wcag2a`, `wcag2aa`,
`wcag21a`, `wcag21aa`, `wcag22aa`), run in a real Chromium browser by Playwright. The check lives in `tools/a11y/`
and runs in Docker (image `mcr.microsoft.com/playwright:v1.49.1-noble`), so no host browser is needed:

- Linux and macOS: `scripts/a11y.sh`
- Windows: `scripts/a11y.ps1` (PowerShell 5.1 and 7)

It serves `site/` on a local port and analyzes `index.html` in light mode and in dark mode at 360 px and 1280 px
wide; any violation fails the run (exit 1, exit 2 on a tool error). Run it after any change under `site/` and
before every release. It is not part of `composer ci`: CI has no browser, so the gate is run by hand. The Laravel
dashboard page (Blade) is not covered; only the static front page is gated.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, scan
access): ask Enterprise Applications.
