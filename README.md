<div align="center">
  <a href="site/index.html"><img src="docs/readme/hero.jpg" width="100%" alt="Rollcall's front page: the headline 'Every app answers present.' beside a sheet of ruled register paper listing sample apps, each marked present, late or back row, over the green roll-book mark."></a>
</div>

# Rollcall

<p>
  <img src="docs/readme/badges/php.svg" alt="php ^8.3">
  <img src="docs/readme/badges/license.svg" alt="license MIT">
  <img src="docs/readme/badges/exit-codes.svg" alt="exit codes 0 ok, 2 error">
  <a href="https://github.com/waynestate/rollcall/actions/workflows/ci.yml"><img src="https://github.com/waynestate/rollcall/actions/workflows/ci.yml/badge.svg" alt="GitHub CI"></a>
</p>

Checks: `composer ci` (the same command runs on GitLab and GitHub).

> Every app answers "present". The ones on PHP 7.4 answer from the back row.

Front page: [`site/index.html`](site/index.html). It is static, with no build step (see `site/README.md`).
On Citadel, the raw-PHP deploy layer in [`deploy/`](deploy/) serves it: a Squire kit whose front controller puts `site/` behind HELMET's security headers. For local development, serve `site/` directly.

Rollcall is the fleet scanner for the Wayne State application stack. For each app it records the PHP image, the
lowest PHP its `composer.json` allows, the Laravel version, the auth package and its version, and the PROCTOR
result. Then it looks up each version by date in CREED's `policy.json`. It keeps one snapshot per scan, so you can
see drift and an EOL burn-down over time. It prunes old snapshots after the retention window (default 90 days; the
newest one always stays).

Rollcall enforces nothing. It never opens an issue, leaves a comment or deploys anything, and it exits `0` whatever
the fleet looks like. The public report has totals only: counts and progress, no app names, owners or rankings. The
per-app list is internal. Like a good attendance sheet, it is for noticing who is missing, not for shaming them.

<div align="center">
  <img src="docs/readme/register.svg" width="100%" alt="A page of Rollcall's roll book: seventeen sample apps called down the register one at a time, each answered with a stamp: seven present, five late, four back row and one inaudible.">
  <br><sub>One page of the roll book, called name by name. Sample data: the apps are fictional, and a real scan's per-app list stays internal.</sub>
</div>

## Where it sits in the stack

The parts of the Wayne State application stack, and their jobs. WELD is the Laravel starter app that has most of them installed:

| Part | Job |
|---|---|
| WayneUI (project 0) | The interface layer: tokens, components, four consumer paths |
| CREED | The standards and `policy.json`, the dates Rollcall reads |
| PROCTOR | Measures one app against the policy; Rollcall reads its JSON report and never re-runs a rule |
| Preflight | CI templates |
| SESAME | Protocol-neutral auth; Rollcall reports which auth package each app uses |
| HELMET | Baseline runtime and its own optional `/metrics` |
| Pinstripe | The coding standard this repo includes for its Pint and PHPStan settings |
| Sourdough | Starters |
| Rollcall | The fleet census and EOL burn-down |

## How it is built

A framework-free core (`src/`) plus thin entry points that hold no rules:

- `bin/rollcall`: the CLI (`scan`, `report`, `digest`, `drift`, `advisories`, `prune`). One PHP command, so it works the same on Windows, macOS and Linux.
- `public/index.php`: a raw-PHP front controller for `GET /metrics` and `GET /report.json`.
- `src/Laravel/`: a Laravel adapter with a dashboard page, a `/metrics` route and `php artisan rollcall:scan` and `rollcall:prune`.

CREED's policy reader lives under `src/Creed/` (namespace `Waynestate\Rollcall\Creed`) as a generated copy, made
by CREED's `bin/export.php`. CREED is on Packagist now, so the copy can become a `require` of `waynestate/creed`;
until Rollcall switches, the copy stays. To refresh it, run
`php <creed>/bin/export.php --namespace='Waynestate\Rollcall\Creed' --to=src/Creed` from a CREED checkout.
`composer creed-copy:check` proves the copy has not drifted (it reads the checkout from `CREED_PATH`); a hand edit
to `src/Creed/` fails that check.

Data comes in through three interfaces. Each has a fixture adapter, so you can try everything without a live host:

| Source | Interface | Adapters |
|---|---|---|
| Repositories (`composer.json`, `composer.lock`) | `RepositorySource` | a directory of fixtures; GitLab over PSR-18, tested against recorded responses |
| Runtime image per app | `ImageSource` | a JSON export of the app list from any platform, or Citadel's own registrations export (which adds forced upgrade dates) |
| PROCTOR result | `ProctorReportSource` | a directory of PROCTOR `--format=json` reports |

Rollcall only reads from every source. It writes its own snapshot directory, and digest files only when
`rollcall digest --out` asks for them.

The decisions behind this shape (saved files over live calls, counts in public, one image source, the contract) are
recorded in [DECISIONS.md](DECISIONS.md) and [ADR/](ADR/).

## Install

```
composer require waynestate/rollcall
```

Rollcall 0.1.9 is on Packagist. No extra repository needed. Requires PHP `^8.3`. The CLI is `vendor/bin/rollcall`.

## Quick start

This runs on the fixtures in the repo, so you need no network and no token. Run `composer install` once
(or the Docker line under "Run the checks"), then the commands below.

Linux and macOS (bash):

```
export ROLLCALL_POLICY_FILE=tests/fixtures/policy.json
export ROLLCALL_REPOS_DIR=tests/fixtures/repos
export ROLLCALL_IMAGES_FILE=tests/fixtures/images.json
export ROLLCALL_PROCTOR_DIR=tests/fixtures/proctor
export ROLLCALL_STORE_DIR=var/snapshots
php bin/rollcall scan
php bin/rollcall report
```

Windows (PowerShell, no bash or WSL needed):

```
$env:ROLLCALL_POLICY_FILE = "tests/fixtures/policy.json"
$env:ROLLCALL_REPOS_DIR = "tests/fixtures/repos"
$env:ROLLCALL_IMAGES_FILE = "tests/fixtures/images.json"
$env:ROLLCALL_PROCTOR_DIR = "tests/fixtures/proctor"
$env:ROLLCALL_STORE_DIR = "var/snapshots"
php bin/rollcall scan
php bin/rollcall report
```

`policy.json` is CREED's file; the copy under `tests/fixtures/` is a snapshot for tests. Use the released one in real
runs.

## Commands

```
rollcall scan       [--today=YYYY-MM-DD] [--prune[=DAYS]]
rollcall report     [--format=text|json|prometheus] [--internal] [--today=YYYY-MM-DD]
rollcall digest     [--owner=CONTACT] [--format=text|json] [--out=DIR] [--send] [--today=YYYY-MM-DD]
rollcall drift      --feed=<file> [--component=php] [--today=YYYY-MM-DD]
rollcall advisories --feed=<file> [--today=YYYY-MM-DD]
rollcall prune      [--days=N] [--dry-run] [--today=YYYY-MM-DD]
```

- `scan` scans once and stores `<ROLLCALL_STORE_DIR>/<date>.json`. A second scan on the same day replaces it. A
   gap in one source (a missing report, a bad image tag) becomes a note on that app, and the scan carries on. A
   refused GitLab token stops the scan loudly instead of producing a flattering partial answer.
  `--prune[=DAYS]` deletes snapshots older than that many days after saving (default: `ROLLCALL_RETENTION_DAYS`,
  90; 0 keeps everything).
- `report` prints the latest snapshot against the policy as of today. `text` is a terminal table, `json` is the
  machine-readable form, `prometheus` is the metrics text (refused unless `ROLLCALL_METRICS_ENABLED=true`).
  `--internal` adds the per-app list; keep that output inside the project.
- `digest` builds the opt-in owner digests (see "Owner digests" below): plain words about only one owner's apps,
  only when something changed since the previous scan. It prints them; `--out=DIR` writes one text file each;
  `--send` mails them, and only when sending is switched on too.
- `drift` compares `policy.json` with a saved endoflife.date v1 product page (for example
  `https://endoflife.date/api/v1/products/php/` saved to a file). A difference is a flag for a person to look at; the
   policy stays the source of truth. Rollcall does not fetch that page itself.
- `advisories` checks each app's locked `laravel/framework` and auth-package versions against a saved Packagist
  security-advisories response (for example
  `https://packagist.org/api/security-advisories/?packages[]=laravel/framework` saved to a file). One line per
  package and version, sorted by package then version: the status (`affected`, `clear` or `no data`), the count and
  the advisory ids, and how many apps hold that pair. A package with no advisory data prints `no data`, never
  `safe`: an absent row proves nothing about the package, and a version nobody can parse says `no data` too.
     Rollcall does not fetch that page itself; a person saves it and `--feed` points at the file. Output is package
  names and counts only: no app ids.
- `prune` deletes snapshots older than `--days` (default: `ROLLCALL_RETENTION_DAYS`, 90; 0 keeps everything). The
  newest snapshot is never deleted, even if it is older than the cut-off, so the dashboard always has data.
  `--dry-run` lists what would go without deleting anything.

Exit codes: `0` ok, `2` tool error (bad option, unreadable policy, a source that refused). There is no `1`: Rollcall
does not gate anything.

## What the report says

- **Apps present and scan notes**: the apps the scan read, then the notes it printed and carried on past
  (`totals.scan_errors` in JSON). Today a note means a source is not configured (no image export, no PROCTOR
  reports). A note is not a failed scan: a source that refuses stops the scan instead.
- **PHP image, declared PHP floor, Laravel**: apps per version, the state from the policy (`active`, `security_only`,
  `eol`, `unknown`), the date security support ends and the days left (negative once past).
- **Auth package and PROCTOR**: apps per auth package, and per PROCTOR result (`pass`, `fail`, `none`).
- **Drift**, counted per kind: `image_eol`, `image_unknown`, `image_below_floor`, `floor_allows_eol`,
  `floor_unbounded`, `eol_past_grace` (past the policy's grace days), `laravel_eol`, `proctor_missing`,
  `proctor_failing`, `override_expired`.
- **Progress**: apps that moved since the previous scan and apps that went from an EOL image to a supported one. In
  the internal view each upgraded app carries the day it landed.
- **EOL burn-down**: for each scan day, how many apps ran an EOL image on that day. It reads the image, not the
  declared floor, so it stays `unknown` until an image export is configured. Each day also carries the same roll
  three ways, counts only: per support state (`states`), per PHP minor of the image (`php_image`, such as
  `{"8.1": 3, "8.4": 9}`) and per Laravel major (`laravel`). Every stored snapshot gets the breakdown, including the
  ones written before it existed, so the history reaches back as far as `rollcall prune` keeps snapshots.

- **Forced image upgrades**: with Citadel's export as the image source, how many apps have a forced base-image
  upgrade pending, how many are due today or earlier, and the next date (`forced_upgrades`). The internal per-app
  list carries each app's `upgrade_due`. Zero with any other image source. See
  [docs/guides/citadel.md](docs/guides/citadel.md).

State is never decided in code: it is derived from the dates in `policy.json` by CREED's reader. A version the
policy does not list is `unknown`.

## Configuration

Every setting is an environment variable. `.env.example` holds placeholders; never commit a real token.

| Variable | Meaning | Default |
|---|---|---|
| `ROLLCALL_POLICY_FILE` | Path to CREED's `policy.json` | none (required for `report`, `drift`) |
| `ROLLCALL_STORE_DIR` | Snapshot directory, the only place Rollcall writes | `var/snapshots` |
| `ROLLCALL_SOURCE` | `directory` (fixtures) or `gitlab` | `directory` |
| `ROLLCALL_REPOS_DIR` | Fixture repositories as `<group>/<project>/composer.{json,lock}` | none |
| `ROLLCALL_GITLAB_URL` | `https://` base URL of the GitLab instance | none |
| `ROLLCALL_GITLAB_TOKEN` | A GitLab bot or personal access token with the read_api scope only and an expiry, whose user can read only the projects to scan. Never in git, never logged, never printed | none |
| `ROLLCALL_GITLAB_GROUPS` | Comma-separated group ids or paths; subgroups are included. Ignored when ROLLCALL_GITLAB_PROJECTS is set | none |
| `ROLLCALL_GITLAB_PROJECTS` | Comma-separated full `group/project` paths; when set, only those projects are scanned and no group is listed | none |
| `ROLLCALL_IMAGES_FILE` | Export of the app list: `{"apps": [{"project": "group/project", "image": "php:8.4-apache"}]}` | none |
| `ROLLCALL_CITADEL_EXPORT` | Citadel's saved registrations export, used instead of `ROLLCALL_IMAGES_FILE` (not both): base images plus forced upgrade dates. See [docs/guides/citadel.md](docs/guides/citadel.md) | none |
| `ROLLCALL_PROCTOR_DIR` | PROCTOR JSON reports, one per app: `group/project` is read from `group__project.json` | none |
| `ROLLCALL_AUTH_PACKAGES` | Comma-separated Composer packages that count as an auth package. An entry without a slash matches that package under any vendor (`wayne-cas` matches `anyvendor/wayne-cas`); an entry with a slash matches exactly. First match in list order wins | `waynestate/sesame`, `waynestate/wayne-cas`, `apereo/phpcas`, `jasig/phpcas`, `subfission/cas`, `wayne-cas`, `simplecas` |
| `ROLLCALL_RETENTION_DAYS` | How many days of snapshots to keep; the newest is never deleted | `90` (0 keeps everything) |
| `ROLLCALL_OWNERS_FILE` | Owners file for the digests: `{"owners": [{"contact": "team@example.test", "digest": true, "projects": ["group/project"]}]}`. Internal; never in git | none (no digests) |
| `ROLLCALL_DIGEST_MAIL_ENABLED` | Allow `rollcall digest --send` to mail | `false` |
| `ROLLCALL_DIGEST_MAIL_DSN` | Symfony Mailer DSN for sending, such as `smtp://mail.example.test:25`. A secret: never in git, never printed | none |
| `ROLLCALL_DIGEST_MAIL_FROM` | The from address of the digests | none |
| `ROLLCALL_METRICS_ENABLED` | Turn metrics on (`CLI report --format=prometheus` and `/metrics`) | `false` |
| `ROLLCALL_METRICS_TOKEN` | Bearer token a scraper must present | none |
| `ROLLCALL_METRICS_ALLOW` | Comma-separated scraper IP addresses (exact match) | none |
| `ROLLCALL_PUBLIC_REPORT_ENABLED` | Serve `GET /report.json` (public counts only) | `false` |
| `ROLLCALL_DASHBOARD_ENABLED` | Laravel: serve the dashboard page | `false` |
| `ROLLCALL_DASHBOARD_PATH` | Laravel: dashboard URL path | `rollcall` |
| `ROLLCALL_INTERNAL_ENABLED` | Laravel: serve the per-app page at `<path>/apps` (needs your sign-in middleware) | `false` |
| `ROLLCALL_WAYNEUI_CSS` | Laravel: `https://` or absolute URL of WayneUI's stylesheet for the dashboard | none |

To scan GitLab you need the token and either a group or the project allowlist. The narrow way is
`ROLLCALL_GITLAB_PROJECTS`: name the exact `group/project` paths and Rollcall reads only those, never listing a
group. Otherwise set `ROLLCALL_GITLAB_GROUPS`. The token is a GitLab bot or personal access token with `read_api`
only and an expiry, whose user can read only the projects to scan; until it exists Rollcall runs on its fixtures.
Set `ROLLCALL_SOURCE=gitlab` and the GitLab variables. The `rollcall-scan` job in `.gitlab-ci.yml` does this on a
schedule or by hand (see below). Rollcall sends only `GET` and `HEAD`, fetches the two Composer files without
cloning, skips a file whose blob id has not changed, follows `Link` pagination only to the configured host, and
waits out `429 Retry-After`.

### Run the collector in Docker

No PHP on the machine? The repo ships a `Dockerfile` that builds a small image of the collector. It runs as a
non-root user, writes only to its snapshot folder, and takes every setting from the environment at run time.
Never put a token in the image: the build stays clean, and the token travels with `-e` (or `--env-file`) each
time you run. Two lines, and every app answers present.

Build it, from the repo root (again whenever the code changes):

```
docker build -t rollcall-collector .
```

That reads the `Dockerfile` next to it and tags the result `rollcall-collector`. Then run a scan. Each `-e`
flag is a setting from the table above; for the narrow scan add
`-e ROLLCALL_GITLAB_PROJECTS=group/project,other-group/other-project` and only those projects are read, no group
is listed. The two `-v` flags mount your policy file and snapshot folder so the answer survives the container:

```
docker run --rm -e ROLLCALL_SOURCE=gitlab -e ROLLCALL_GITLAB_URL=https://gitlab.example.test -e ROLLCALL_GITLAB_TOKEN="$ROLLCALL_GITLAB_TOKEN" -e ROLLCALL_GITLAB_GROUPS=your-group -e ROLLCALL_POLICY_FILE=/app/policy.json -v "$PWD/policy.json:/app/policy.json" -v "$PWD/var:/app/var" rollcall-collector scan
```

Next: after a good scan, `var/snapshots/<today>.json` holds the day's attendance, and the same run with
`report` instead of `scan` prints it. On Windows PowerShell, set `$env:ROLLCALL_GITLAB_TOKEN` first and use
`${PWD}` for the mount paths.

### Scheduled scan on GitLab CI

Rollcall's own repository (on git.wayne.edu, its canonical home) carries a `rollcall-scan` job in `.gitlab-ci.yml`. It
runs `scan` over the 13 stack projects read-only and keeps the snapshot in `var/snapshots/` as a 30 day artifact.
It runs when a GitLab schedule says so, or when a person starts it by hand from the web; without a token it skips
green. To make it run, create three things in GitLab:

1. A token: a bot or personal access token for a user who is a member (role Reporter) of only the 13 projects the
   job names in `.gitlab-ci.yml`, with the `read_api` scope only and an expiry (90 days, then rotate; Rollcall
   prints a note 14 days before it expires).
2. A CI/CD variable `ROLLCALL_GITLAB_TOKEN` on the ROLLCALL project: masked and hidden, protected (the schedule
   runs on the protected default branch), and not a file.
3. A schedule on `main`: CI/CD > Schedules, weekly (Monday), with the variable `ROLLCALL_JOB` set to `scan`.

Why a per-project token: a group token on enterprise-apps would see other teams' repositories, which the scan must
not read. The allowlist `ROLLCALL_GITLAB_PROJECTS` limits what Rollcall asks for (it never lists the group and
never asks for another project), but it does not limit what the token can read; only the token's membership does.
So the token's user must be a member of just those projects.

Exactly which scope, and why each other scope stays off: `read_api` covers all five calls Rollcall makes (listed
one by one, with the role and the expiry rules, under "The GitLab token, exactly" in
[docs/security.md](docs/security.md)). Not `api`, not `read_repository` (Rollcall never clones), not `read_user`,
not `self_rotate` (a person rotates the token, never Rollcall).

## Owner digests (opt-in)

An owner who asks can get a short digest of only their own apps: what changed since the previous scan (an image
upgrade, a new drift flag, one cleared, a PROCTOR result that moved), then where each app stands. Nothing changed,
no digest. Nobody gets one by default, and no owner is ever guessed from commit authors.

1. Keep an owners file outside git and point `ROLLCALL_OWNERS_FILE` at it. An owner opts in with `"digest": true`;
   leaving the key out means no.

   ```json
   {
       "owners": [
           {"contact": "team@example.test", "digest": true, "projects": ["group/project", "group/other-project"]}
       ]
   }
   ```

2. After a scan, `rollcall digest` prints each digest with news (`--format=json` for a script, `--owner=CONTACT`
   for one owner). `--out=DIR` writes them as `<date>-<contact>.txt` instead, for a mail tool you already have.
3. To have Rollcall mail them itself, install `symfony/mailer`, set `ROLLCALL_DIGEST_MAIL_ENABLED=true`,
   `ROLLCALL_DIGEST_MAIL_DSN` and `ROLLCALL_DIGEST_MAIL_FROM`, and run `rollcall digest --send`. Without all four, it
   sends nothing. Every contact must be a mail address, or nothing goes out at all.

A digest names apps, so it is internal like the per-app list. It uses the report's own states and drift flags, so a
digest and the report never disagree. Schedule it after `rollcall scan` (weekly suits the burn-down).

## WSU values (Playbook)

`ROLLCALL_GITLAB_URL` is a Wayne State value, so it may come from Playbook (`waynestate/playbook`) when the
environment has none. The environment always wins; Playbook only fills what the environment leaves out. The GitLab
token never comes through Playbook and stays an environment variable.

| Setting | Value |
|---|---|
| `ROLLCALL_GITLAB_URL` | The `https://` base URL of the GitLab instance |

Playbook is private and lives on git.wayne.edu. Getting it into your app:

Composer: add the repository to your `composer.json`, then require the package. Composer authenticates to
git.wayne.edu with the token in `auth.json`.

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://git.wayne.edu/enterprise-apps/playbook.git"}
    ]
}
```

```
composer require waynestate/playbook
```

curl (Linux, macOS):

```
curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -o .env.playbook
```

PowerShell (Windows):

```
Invoke-WebRequest -Headers @{ 'PRIVATE-TOKEN' = $env:GITLAB_TOKEN } -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -OutFile .env.playbook
```

Clone:

```
git clone https://git.wayne.edu/enterprise-apps/playbook.git
```

The token is your own: `read_api` for curl, `read_repository` for clone and Composer.

Playbook is private on git.wayne.edu. If you get a 404, ask the Enterprise Applications team for read access.

## Metrics

Metrics are off by default (the stack's metrics rule). With `ROLLCALL_METRICS_ENABLED=true`, `/metrics` serves
Prometheus text built from the latest snapshot (a request never triggers a scan). It needs `ROLLCALL_METRICS_TOKEN` as a bearer token or an address in
`ROLLCALL_METRICS_ALLOW`; with neither set, nobody gets in. Labels are version numbers, state names, drift kinds and
public package names: no app names or personal data. The dashboard JSON is in `grafana/rollcall-burn-down.json`; import
it into Grafana once a Prometheus server scrapes the endpoint. Run `rollcall scan`
on a schedule so the series moves.

## Laravel

The package registers itself (`extra.laravel.providers`). Publish the config with
`php artisan vendor:publish --tag=rollcall-config`, set the variables above, and schedule `rollcall:scan`. The
dashboard shows counts and progress; the per-app page is a second route that stays off until you enable it, and it
runs behind `rollcall.dashboard.internal_middleware` (default `web` and `auth.sesame`), so an app with no such
middleware fails closed. The page uses WayneUI's class names and takes its stylesheet from `ROLLCALL_WAYNEUI_CSS`.
The `/metrics` route is registered on its own path; if your app already serves `/metrics` (HELMET does), run Rollcall's
on a separate app or leave metrics off.

## Use Rollcall from your coding agent (ADVISOR)

Rollcall ships a module for [ADVISOR](https://packagist.org/packages/waynestate/advisor), the stack's MCP server: three
read-only tools, and a small creed goes with them: every app answers present, the agent may ask, and nobody gets ranked.

Install both packages where the MCP server runs:

```
composer require waynestate/advisor waynestate/rollcall
```

Then name the module in your own manifest file. Rollcall ships none on purpose, so nothing loads by accident:

```json
{
    "modules": [
        "Waynestate\\Rollcall\\Advisor\\RollcallModule"
    ]
}
```

and point `ADVISOR_MODULES_FILE` at that file. The tools read the snapshot store behind `ROLLCALL_STORE_DIR`; with an
empty store each one says so in plain words and names `rollcall scan`.

| Tool | What it answers |
|---|---|
| `rollcall_fleet_summary` | Counts from the latest snapshot: apps by PHP image, declared floor, Laravel line, auth package and PROCTOR result, drift per kind, progress since the previous scan, and the snapshot date |
| `rollcall_burn_down` | The dated EOL series, one point per scan day, each with its counts per support state, PHP minor and Laravel major; optional `days` (an integer, 1 to 365) keeps only the most recent days |
| `rollcall_supported_versions` | Every version in CREED's policy, grouped by what it says today: active, security only, or end of life |

The tools take no path, command or URL, make no network call, and answer public counts only: the same fields
`/report.json` serves. The per-app list stays internal and never leaves through a tool. Each answer names its source
and its dates.

## Proof

Rollcall's first scheduled scan of the stack on GitLab CI and a rerun over 58 Wayne State applications
(2026-11-06): what ran, what held, what it caught in Rollcall and what it does not prove yet, on the
[Proof](docs/reference/proof.md) docs page.

## Docs

The full documentation lives at [/docs/](/docs/) on the project site: install, guides, reference and checks, built
from `docs/` by Squire's docs builder. Two tables are generated so they cannot drift: the command reference comes
from `php bin/rollcall list --format=json`, and the environment table is scanned out of `src/`, `bin/` and
`config/`.

- `composer docs` rebuilds `site/docs/` from `docs/`; run it after editing a page.
- `composer docs:check` fails when the built site is stale or a generated table has drifted; `composer ci` runs it.

The pages are committed because the deploy server serves `site/` without building. Every page links back to its
Markdown, and the footer stamps the version the docs describe. Roll call: the docs answer present too.

The docs pages wear the front page's look: the same masthead and menu, ruled paper, Caslon titles, the front
page's red section folios (each section of a page opens with its own "§ 1") and the green back cover. To change it, edit `docs/_header.html` (the masthead, links written as if from `site/`) and
`docs/theme/rollcall.css` (the front page's tokens mapped onto the kit's `--dk-*`, plus a derived dark mode), then
run `composer docs`. Never edit `site/assets/docs.css` or `docs.js`: they are Squire's kit and a sync overwrites them.

## Run the checks

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](MAINTAINING.md).
Why it is built this way is in [DECISIONS.md](DECISIONS.md) and the ADRs under [ADR/](ADR/).

`composer ci` runs every check: manifest, tests (the contract suite among them), Pint, PHPStan, `composer audit`, a
check that the front page versions table still matches CREED's policy, and a check that the docs site is current.
The contract itself is JSON Schema in [`contract/`](contract/README.md): the report, the stored snapshot and the
image list export. `tests/ContractTest.php` runs the real commands on the fixtures and validates their output
against it, so a field cannot appear or vanish without the contract changing in the same commit. PHP is not needed on the host:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

(On Windows PowerShell `${PWD}` works as written; in Git Bash use `$(pwd -W)` and set `MSYS_NO_PATHCONV=1`.)

The GitLab pipeline (`.gitlab-ci.yml`) and the GitHub workflow (`.github/workflows/ci.yml`) both wrap `composer ci`,
on PHP 8.3, 8.4 and 8.5. git.wayne.edu now has an instance runner, and the stack's CI runs on it. Every product in
the stack still strongly recommends a git.wayne.edu instance runner, so that capacity stays there.

The front page has its own accessibility gate (axe-core in Playwright, run in Docker): `scripts/a11y.sh` on Linux
and macOS, `scripts/a11y.ps1` on Windows; MAINTAINING.md says when to run it.

## Security notes

- Read-only toward GitLab and everything else: one gate in the GitLab adapter allows only `GET` and `HEAD`, and a test
  fails if a write verb appears in `src/`.
- The GitLab token is a bot or personal access token with the `read_api` scope only and an expiry, whose user can
  read only the projects to scan. It goes in the environment as `ROLLCALL_GITLAB_TOKEN`, never in git, is sent only
  to the configured `https` host, and is hidden from debug output and error messages. `ROLLCALL_GITLAB_PROJECTS`
  limits what Rollcall asks for, not what the token can read; only the token's membership does that, so the token's
  user stays a member of just the listed projects. When the source is GitLab, `scan` reads the token's own record
  (`GET /api/v4/personal_access_tokens/self`) and prints a note 14 days before it expires, or when it has already
  expired. The note names the date; the owner rotates the token before its expiry, never Rollcall. The check is
  advisory: a refused, unreachable or unparseable answer prints nothing and never fails the scan, and the token
  value never appears in output.
- Composer files are parsed as data. No `composer install`, script or plugin from a scanned repository ever runs.
- PROCTOR reports, image tags and app ids are outside input: shapes are validated, text is bounded, and terminal output
  strips control characters.
- Every GitLab call, the scope that covers it, and the scopes the token must not have are tabled in
  [docs/security.md](docs/security.md); a test fails if the adapter calls a path that table does not list.

<details>
<summary>Watch the back row empty (the burn-down, sample data)</summary>

<br>

<img src="docs/readme/burn-down.jpg" width="100%" alt="Rollcall's sample burn-down: hatched bars of apps on an end-of-life PHP image falling from 19 on August 12 to 4 on October 28, with a red line over the top and the last bar filled green.">

<sub>The same line Grafana draws from <code>/metrics</code>. Made-up apps, real arithmetic.</sub>

</details>

<details>
<summary>The brand board (the cover of the book)</summary>

<br>

<img src="docs/readme/board.jpg" width="100%" alt="Rollcall's brand board: the open roll-book mark with a gold tick on a green tile, lockups, the green, gold and cream palette, type, favicons, and do and don't panels.">

</details>

## Where the code lives

The canonical repository is [enterprise-apps/rollcall](https://git.wayne.edu/enterprise-apps/rollcall) on git.wayne.edu. Clone it over SSH as `git@git.wayne.edu:enterprise-apps/rollcall.git` or over HTTPS as `https://git.wayne.edu/enterprise-apps/rollcall.git`; open issues and merge requests there, and tags are made there, by the maintainer, with an approver. GitHub ([waynestate/rollcall](https://github.com/waynestate/rollcall)) is a read-only push mirror of git.wayne.edu: nobody pushes to it, and Packagist reads from it. Roll call: git.wayne.edu? Present. GitHub? A copy of the sheet.

## Contributing

Issues and merge requests go to git.wayne.edu; see Where the code lives above.

License: MIT, Copyright Wayne State University.

<div align="center"><b>The whole class</b><br><sub>WayneUI and the stack. Everyone is here today; the ones in the back row know who they are.</sub></div>

<div align="center">
  <a href="https://github.com/waynestate/wayne-ui" title="WayneUI"><img src="docs/readme/cast/wayneui.png" width="40" alt="WayneUI"></a>
  <a href="https://github.com/waynestate/creed" title="CREED"><img src="docs/readme/cast/creed.png" width="40" alt="CREED"></a>
  <a href="https://github.com/waynestate/proctor" title="PROCTOR"><img src="docs/readme/cast/proctor.png" width="40" alt="PROCTOR"></a>
  <a href="https://github.com/waynestate/sesame" title="SESAME"><img src="docs/readme/cast/sesame.png" width="40" alt="SESAME"></a>
  <a href="https://github.com/waynestate/helmet" title="HELMET"><img src="docs/readme/cast/helmet.png" width="40" alt="HELMET"></a>
  <a href="https://github.com/waynestate/sourdough" title="Sourdough"><img src="docs/readme/cast/sourdough.png" width="40" alt="Sourdough"></a>
  <a href="https://github.com/waynestate/squire" title="Squire"><img src="docs/readme/cast/squire.png" width="40" alt="Squire"></a>
  <a href="https://github.com/waynestate/preflight" title="Preflight"><img src="docs/readme/cast/preflight.png" width="40" alt="Preflight"></a>
  <a href="https://github.com/waynestate/pinstripe" title="Pinstripe"><img src="docs/readme/cast/pinstripe.png" width="40" alt="Pinstripe"></a>
  <a href="https://github.com/waynestate/booster" title="Booster"><img src="docs/readme/cast/booster.png" width="40" alt="Booster"></a>
  <a href="https://github.com/waynestate/rollcall" title="Rollcall"><img src="docs/readme/cast/rollcall.png" width="40" alt="Rollcall"></a>
  <a href="https://git.wayne.edu/enterprise-apps/drawbridge" title="Drawbridge"><img src="docs/readme/cast/drawbridge.png" width="40" alt="Drawbridge"></a>
  <a href="https://github.com/waynestate/advisor" title="ADVISOR"><img src="docs/readme/cast/advisor.png" width="40" alt="ADVISOR"></a>
  <a href="https://github.com/waynestate/weld" title="WELD"><img src="docs/readme/cast/weld.png" width="40" alt="WELD"></a>
</div>

<div align="center"><sub>Images are regenerated from the front page by <a href="scripts/readme-art/README.md"><code>scripts/readme-art</code></a>. Present.</sub></div>
