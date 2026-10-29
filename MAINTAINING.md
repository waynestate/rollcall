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
- It reads repositories through a GitLab API token with read-only scope. The token is configuration, never committed.

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

The README's "Quick start" runs `scan` and `report` against fixtures on Windows (PowerShell `$env:ROLLCALL_*`
settings) and on Linux and macOS (`export ROLLCALL_*`).

With only Docker:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

`composer ci` runs `composer validate --strict`, PHPUnit, Pint, PHPStan and `composer audit`. It does not run the
CREED copy check, which needs a CREED checkout (it exits 2 without `CREED_PATH`).

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

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, scan
access): ask Enterprise Applications.
