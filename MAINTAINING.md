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
- It reads repositories through a GitLab token with `read_api` only, the Reporter role and an expiry. The stack's is
  the group access token `rollcall-scanner` on `enterprise-apps`, kept in the CI/CD variable
  `ROLLCALL_GITLAB_TOKEN`, expiring 2027-11-06. The token is configuration, never committed; without it Rollcall runs
  on its fixtures and the scan job skips green.

## Where it lives

Development happens on git.wayne.edu, the canonical repository ([enterprise-apps/rollcall](https://git.wayne.edu/enterprise-apps/rollcall)). Changes, issues and merge requests go there, and tags are made there, by the maintainer, with an approver. GitHub ([waynestate/rollcall](https://github.com/waynestate/rollcall)) is a read-only push mirror of git.wayne.edu: `main` and tags are copied automatically, nobody pushes to it, and Packagist reads from it.

## Build and test

| Task | Command |
|---|---|
| Install | `composer install` |
| All checks (what CI runs) | `composer ci` |
| Fix code style | `composer fix` |
| Check the CREED copy, Windows | `$env:CREED_PATH = "..\creed"; composer creed-copy:check` |
| Check the CREED copy, Linux and macOS | `CREED_PATH=../creed composer creed-copy:check` |
| Regenerate the front page versions table | `composer site:versions`; it also regenerates the quick-start printout's policy values in `site/index.html` (between the `quickstart-versions` markers), and `composer site:versions-check` fails when either the versions table or the quick-start printout is stale |
| Front page accessibility gate | `scripts/a11y.sh` (Linux and macOS) or `scripts/a11y.ps1` (Windows); both run in Docker |
| Dashboard accessibility gate | `scripts/dashboard-a11y.sh` (Linux and macOS) or `scripts/dashboard-a11y.ps1` (Windows); both run in Docker and write the rendered pages to `build/dashboard-a11y/` |

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

**Weekly CREED copy refresh.** The `creed-copy-refresh` job (`tools/creed-copy-refresh.sh`) does this without a
person every Monday at 07:03 America/Detroit, one hour after CREED's policy robot. It clones CREED main with the job
token, re-exports the copy, reruns `composer site:versions`, the docs builder and the agent-ready builder, then runs
`composer creed-copy:check` and the full `composer ci`. Only when both pass does it push one commit to main, as
"CREED copy bot". No change in the copy (a moved version stamp in `manifest.json` does not count) means no commit. A
red run pushes nothing, so main stays as it was and next week's run tries again. The schedule is a GitLab setting
(CI/CD > Schedules, weekly, variable `ROLLCALL_JOB=creed-copy`); to run it now, start a pipeline on main from the
web. GitLab starts no pipeline for a job-token push, which is why the job runs the whole gate before it pushes. Use
the hand steps above only when a refresh cannot wait for Monday.

## Rotating the scanner token

The stack maintainer rotates `rollcall-scanner`: a role, not a named person, so the job survives a change of
people. Do it before 2027-11-06; `scan` starts printing `Note: the GitLab token expires on ...` on 2027-10-23, so a
reminder a month ahead keeps it calm. In short: make a replacement token (same name, Reporter, `read_api`, at most a
year), paste it into the `ROLLCALL_GITLAB_TOKEN` variable, run the scan by hand and read the log, revoke the old
token, then write the new date into the docs. The full runbook, with where each setting lives and what a good log
says, is "The scanner token and how to rotate it" in [docs/security.md](docs/security.md). A leaked token is
rotated at once, the same way.

## Owners file

`owners.json` is Rollcall's own owners file: who answers for each of the 13 projects the scan job names (today the
stack maintainer, for all of them). When a project joins the scan job's list, add it here in the same change;
`OwnersFileTest` fails until you do. The repo is public, so contacts are roles or teams, never a person.

Each entry carries a `"confirmed"` date. The weekly scan job's `rollcall owners` step fails once that date is more
than 365 days old (`ROLLCALL_OWNERS_CONFIRM_DAYS`), so once a year, at the latest when that job goes red, ask the
owner whether it still answers for its projects and write the new date in the same change. The scanner token
expires on a yearly cycle too, so one sitting can do both.

## Public output stays anonymous

The per-app list a scan produces is internal data. Nothing in this repo (fixtures, docs, screenshots) may name a real
app, owner or finding, and a test refuses `rank`, `owner` or `score` fields in the JSON. Exit codes are 0 or 2, never
1, so a scan never looks like a failed build.

## Smoke test on every OS

Before a release, one maintainer runs this list on Linux, macOS and Windows (PowerShell) and ticks each box. Each
item is one command, what a good run prints or does, and what to do when it does not. Run the items in order from a
clean clone.

1. **Clone and install with no PHP on the host.** From a fresh clone, run the Docker line from the README
   "Run the checks" section:

   ```
   docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
   ```

   It should finish installing the dependencies inside the container, so the host never needs PHP. If it cannot
   start or mount the repo, fix the local Docker setup and the mount path before going on.

2. **`composer ci` through Docker.** The same line above ends with `composer ci`. Expect EXIT 0. If it stops early,
   read the failing check (PHPUnit, Pint, PHPStan, audit or the versions table) and fix it before the release.

3. **Build the collector image and check its version.** From the repo root:

   ```
   docker build -t rollcall-collector .
   docker run --rm rollcall-collector --version
   ```

   The second command should print the Rollcall version. If the build fails, read the Dockerfile output; if the
   version is missing or wrong, check `ReportBuilder::VERSION` and the image tag.

4. **Scan the fixtures in the container.** Mount the repo read-only and leave only `var/` writable, so the scan can
   save its snapshot but cannot touch the source. The fixture paths are `tests/fixtures/repos` and
   `tests/fixtures/policy.json`.

   Bash (Linux and macOS; in Git Bash use `$(pwd -W)` and set `MSYS_NO_PATHCONV=1`):

   ```
   REPO="$PWD"
   if [ -n "${MSYSTEM:-}" ]; then REPO="$(pwd -W)"; fi
   docker run --rm -e ROLLCALL_SOURCE=directory -e ROLLCALL_REPOS_DIR=tests/fixtures/repos -e ROLLCALL_POLICY_FILE=tests/fixtures/policy.json -e ROLLCALL_STORE_DIR=var/snapshots -v "${REPO}:/app:ro" -v "${REPO}/var:/app/var" -w /app rollcall-collector scan
   ```

   PowerShell (Windows):

   ```
   docker run --rm -e ROLLCALL_SOURCE=directory -e ROLLCALL_REPOS_DIR=tests/fixtures/repos -e ROLLCALL_POLICY_FILE=tests/fixtures/policy.json -e ROLLCALL_STORE_DIR=var/snapshots -v "${PWD}:/app:ro" -v "${PWD}/var:/app/var" -w /app rollcall-collector scan
   ```

   Expect a snapshot file under `var/snapshots`. If there is no file, the store mount is wrong or the scan stopped
   early; check the mount paths and the scan output.

5. **Read the report back.** In the same mounts, run `report` instead of `scan`:

   ```
   docker run --rm -e ROLLCALL_SOURCE=directory -e ROLLCALL_REPOS_DIR=tests/fixtures/repos -e ROLLCALL_POLICY_FILE=tests/fixtures/policy.json -e ROLLCALL_STORE_DIR=var/snapshots -v "${PWD}:/app:ro" -v "${PWD}/var:/app/var" -w /app rollcall-collector report
   ```

   It should print the counts and progress, with no per-app owner, rank or score. If any of those words appear,
   stop and fix the report before the release.

6. **Run the parity stack.** `deploy/scripts/parity.sh up` on Linux and macOS, or `deploy/scripts/parity.ps1 up` on
   Windows, as each script's header says (add `--fallback` or `-Fallback` when the real Citadel image is not on the
   machine). Expect the app to come up on the printed address. If it does not, read the logs the script points at,
   then bring the stack down with `down`.

7. **Run the accessibility gates.** `scripts/a11y.sh` (front page) and `scripts/dashboard-a11y.sh` (dashboard)
   on Linux and macOS, or `scripts/a11y.ps1` and `scripts/dashboard-a11y.ps1` on Windows. Expect zero axe
   violations. If any violation is reported, fix the page and run the gate again until it is clean.

When the list is done, record the date, the OS and the result of each item in the release merge request.

Which script runs where (one PHP command, a `.ps1` and `.sh` pair, or only inside Citadel's container) is on the
docs page Reference > Scripts on every OS (`docs/reference/scripts.md`). `tests/ScriptPairsTest.php` fails if a
developer script ships without its twin.

## Releasing

1. Move the lines under `## [Unreleased]` in `CHANGELOG.md` (Keep a Changelog) to a new `## [X.Y.Z] - YYYY-MM-DD`
   heading.
2. Bump `ReportBuilder::VERSION` in the release commit.
3. `composer ci` and the CREED copy check green locally, then both pipelines green for that commit.
4. Run a secrets scan of the history and check that nothing internal (server names, people, app names, IDs) is in the
   tree.
5. Tag `X.Y.Z` (no `v`) on git.wayne.edu. The mirror carries the tag to GitHub, and Packagist picks it up from there.
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

The front page and the dashboard must pass an accessibility gate: Preflight's own a11y checker, vendored byte
for byte into `tools/a11y/check.mjs` from Preflight 0.4.0 and pinned by `tests/A11yCheckerPinTest.php`, run in
Docker with the same digest-pinned Playwright image, WCAG 2.2 AA tag set (`wcag2a`, `wcag2aa`, `wcag21a`,
`wcag21aa`, `wcag22aa`), color schemes (light and dark) and widths (320 px and 1280 px) as the CI jobs, so a
page that passes locally passes CI. No host browser is needed:

- Front page: `scripts/a11y.sh` on Linux and macOS, or `scripts/a11y.ps1` on Windows (PowerShell 5.1 and 7);
  checks every HTML page under `site/`.
- Dashboard: `scripts/dashboard-a11y.sh` or `scripts/dashboard-a11y.ps1`; renders the public, internal and
  empty-state dashboard pages from Laravel and from the raw-PHP entry point first (six pages), then checks all six.

At 320 px the checker also fails a page whose content overflows its viewport (reflow, violation id
`preflight-reflow`). Any violation, or a page that does not load, fails the run (exit 1; exit 2 on a tool or
configuration error). The reports (`gl-a11y-junit.xml`, `gl-a11y-report.json`) land in `build/a11y/` and
`build/dashboard-a11y/` (gitignored). The checker's npm packages live in a named Docker volume
(`rollcall-a11y-node-modules`), installed once and reused, so the first run is slow and later ones are fast.

Run the gates after any change under `site/`, the dashboard view or its renderer (`src/Dashboard/`), and before every release. They mirror the
`a11y-site` and `dashboard-a11y` CI jobs, which run the same checker from the Preflight component.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, tokens, mirrors, scan
access): ask Enterprise Applications.
