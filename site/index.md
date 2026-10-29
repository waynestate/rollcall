---
title: "Rollcall: Every app answers present."
description: "Rollcall scans Wayne State's apps and reports each one's PHP image, Laravel version, auth package and PROCTOR result, checked against the dates in CREED's policy. It keeps a snapshot per scan, so you can see drift and an EOL burn-down."
url: https://rollcall.apps.wayne.edu/
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

Fleet attendance, Wayne State University

# ROLLCALL

Every app answers “present”.

The ones on PHP 7.4 answer from the back row.

Rollcall is the stack's fleet scanner. For each app it records the PHP image, the lowest PHP its `composer.json` allows, the Laravel version, the auth package and the PROCTOR result. Then it looks up each version in CREED's `policy.json`. Every scan is kept as a snapshot, so you can see drift and how fast the EOL apps are going away.

`$ composer require waynestate/rollcall`

**Works alongside [WELD](https://weld.apps.wayne.edu/).** Rollcall doesn't go inside your app. You run it on its own to see what every app in the fleet is running, WELD apps included.

[Quick start](https://rollcall.apps.wayne.edu/#start-title) [Take the roll](https://rollcall.apps.wayne.edu/#roll-title)

Today's roll sample data

1. kiln-bookingPHP 8.5present
2. tuba-rentalPHP 8.4present
3. pigeon-postPHP 8.3late
4. lunch-ledgerPHP 8.3late
5. abacus-classicPHP 7.4back row

Answered 5 of 5

A sample roll

## Take the roll

Seventeen made-up apps, seated by PHP version with the newest at the front. As you scroll, Rollcall calls each name. The back row answers with a hand in the air.

Sample data · fictional apps · policy as of 2026-10-28

**Sample roll: seventeen fictional apps with their PHP image, Laravel version, auth package, PROCTOR result and the mark Rollcall gives them**

| No. | App | PHP image | Laravel | Auth | PROCTOR | Mark |
| --- | --- | --- | --- | --- | --- | --- |
| Front row PHP 8.5 and 8.4, active |  |  |  |  |  |  |
| 01 | kiln-booking | 8.5 | 13 | sesame | pass | present |
| 02 | tuba-rental | 8.4 | 13 | sesame | pass | present |
| 03 | moth-catalog | 8.4 | 12 | sesame | pass | present |
| 04 | quad-weather | 8.4 | 13 | none | pass | present |
| 05 | greenhouse-log | 8.4 | 12 | sesame | pass | present |
| 06 | sundial | 8.4 | 13 | sesame | pass | present |
| 07 | otter-tracker | 8.4 | 12 | sesame | pass | present |
| Middle rows PHP 8.3 and 8.2, security fixes only |  |  |  |  |  |  |
| 08 | pigeon-post | 8.3 | 12 | phpcas | pass | late |
| 09 | lunch-ledger | 8.3 | 12 | sesame | fail | late |
| 10 | chess-club | 8.3 | 11, EOL | phpcas | pass | late |
| 11 | lost-mittens | 8.3 | 12 | none | none | late |
| 12 | fog-index | 8.2 64 days left | 11, EOL | phpcas | pass | late |
| Back row past end of life |  |  |  |  |  |  |
| 13 | abacus-classic | 7.4 | 8, EOL | phpcas | none | back row |
| 14 | punch-card-pal | 7.4 | none | none | none | back row |
| 15 | dial-up-directory | 8.1 | 10, EOL | phpcas | fail | back row |
| 16 | floppy-archive | 8.0 | 9, EOL | sesame | pass | back row |
| 17 | ghost-kiosk | legacy-base no version in the tag | none | none | none | inaudible |

- **present**: 7
- **late**: 5
- **back row**: 4
- **inaudible**: 1
- **Answered**: 17

Nobody is marked absent. If one source has a gap, like a missing PROCTOR report or a bad image tag, that app gets a note and the scan carries on. The public report only shows totals like these. The per-app list is the internal view (`--internal`), and it stays inside the project.

Five answers per app

## What “present” means

Four of the answers are versions, and Rollcall looks each one up by date in CREED's `policy.json`. No support date is written into Rollcall's code. The fifth answer is PROCTOR's own result.

1. ### PHP image
   The image the app actually runs on, read from its tag in the app list.
2. ### PHP floor
   The lowest PHP its `composer.json` allows. `*` counts as no floor at all.
3. ### Laravel
   The version that's installed, read from `composer.lock`.
4. ### Auth package
   Which sign-in package is installed: SESAME, a CAS client, or none.
5. ### PROCTOR
   `pass`, `fail` or `none`, from PROCTOR's JSON report. Rollcall never re-runs a rule.

Key to the marks

- present `active`: fully supported
- late `security_only`: security fixes only, and the end date is set
- back row `eol`: past its security date, or older than anything the policy lists
- inaudible `unknown`: newer than the policy knows, or a tag nobody can read

Drift and the burn-down

## Watch the back row empty

Rollcall keeps one snapshot per scan day. Count the apps on an EOL image in each one and you get a line that should only go down.

Apps on an EOL PHP image, one scan a week Sample data

Grafana draws the same line from `/metrics` with the bundled dashboard, `grafana/rollcall-burn-down.json`.

### Drift, counted per kind

- `image_eol`runs on an EOL PHP image
- `image_unknown`an image tag nobody can read
- `image_below_floor`the image is older than the app allows
- `floor_allows_eol`the PHP floor still lets an EOL version in
- `floor_unbounded`no PHP floor declared at all
- `eol_past_grace`past the policy's grace days
- `laravel_eol`Laravel past its security date
- `proctor_missing`no PROCTOR report on file
- `proctor_failing`PROCTOR says fail
- `override_expired`a PROCTOR override ran out

`rollcall drift` also compares the policy with a saved endoflife.date page. A difference is a flag for a person; the policy stays the source of truth.

From a checkout, with the bundled fixtures

## Quick start

This runs on the fixtures in the repo, so you need no network and no token, just PHP 8.3 or newer. Run `composer install` once, then the commands below.

**Linux and macOS**

bash

```
export ROLLCALL_POLICY_FILE=tests/fixtures/policy.json
export ROLLCALL_REPOS_DIR=tests/fixtures/repos
export ROLLCALL_IMAGES_FILE=tests/fixtures/images.json
export ROLLCALL_PROCTOR_DIR=tests/fixtures/proctor
export ROLLCALL_STORE_DIR=var/snapshots
php bin/rollcall scan
php bin/rollcall report
```

**Windows**

PowerShell, no bash or WSL needed

```
$env:ROLLCALL_POLICY_FILE = "tests/fixtures/policy.json"
$env:ROLLCALL_REPOS_DIR = "tests/fixtures/repos"
$env:ROLLCALL_IMAGES_FILE = "tests/fixtures/images.json"
$env:ROLLCALL_PROCTOR_DIR = "tests/fixtures/proctor"
$env:ROLLCALL_STORE_DIR = "var/snapshots"
php bin/rollcall scan
php bin/rollcall report
```

What comes back real output, Rollcall 0.1, bundled fixtures (four demo apps)

```
Present: 4 apps. Snapshot 2026-10-28 saved to var/snapshots.

ROLLCALL 0.1.6 | scan 2026-10-28 | as of 2026-10-28 | public
Apps present: 4 (scan problems: 0)

PHP image
VERSION  APPS  STATE          SECURITY UNTIL  DAYS TO EOL
8.1      1     eol            2025-12-31      -301
8.3      1     security_only  2027-12-31      429
8.4      1     active         2028-12-31      795
unknown  1     unknown        -               -

EOL burn-down (state on each scan day)
DATE        APPS  EOL  SUPPORTED  UNKNOWN
2026-10-28  4     1    2          1
```

**exit 0**, however the fleet looks. There is no exit 1: Rollcall takes attendance, it does not hand out detention.

- **`rollcall scan`**: Scans once and saves `<date>.json`. A second scan on the same day replaces it.
- **`rollcall report`**: `--format=text|json|prometheus`. `--internal` adds the per-app list; keep that inside the project.
- **`rollcall drift`**: `--feed=<file>`, a saved endoflife.date page. Rollcall never fetches it itself.
- **In Laravel**: The package registers itself. Publish the config with `php artisan vendor:publish --tag=rollcall-config`, then schedule `php artisan rollcall:scan`.

Left on the desk for your agent

## Notes for the substitute

Your coding agent is the substitute teacher here: it has never met this class. Each note tells it where to read up on Rollcall and what it must leave alone, and asks it to check with you before it guesses. Copy one, or open it in OpenCode, Claude Code, Codex or ChatGPT.

### Get started with Rollcall

Call the sample roll from a checkout, with the bundled fixtures, and have the agent read the report back to you.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer try Rollcall, the fleet scanner of WELD, the Wayne State application stack. Rollcall reads each app's composer.json and composer.lock, its PHP image and its PROCTOR report, and checks every version against CREED's policy.json by date. It enforces nothing and always exits 0.

Read first:
- https://rollcall.apps.wayne.edu/llms.txt, the index of Rollcall's pages, each one as Markdown. Work from them, not from memory.
- ADVISOR, the stack's MCP server, if it is connected: get_project_status and read_project_docs with project rollcall, and policy_lookup for PHP. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt
- The README in this folder, if this folder is a Rollcall checkout. If it is not, ask me whether to clone https://github.com/waynestate/rollcall first.

Task:
1. Install the dependencies once with `composer install` (PHP 8.3 or newer). If the host has no PHP, use the Docker line in the README's Run the checks section.
2. Point Rollcall at the bundled fixtures: set ROLLCALL_POLICY_FILE, ROLLCALL_REPOS_DIR, ROLLCALL_IMAGES_FILE, ROLLCALL_PROCTOR_DIR and ROLLCALL_STORE_DIR the way the README's Quick start does.
3. Call the roll with `php bin/rollcall scan`, then print it with `php bin/rollcall report`.
4. Read the report back to me: each PHP image, the state CREED gives it, and the days left before end of life.

Constraints:
- Fixtures only: no network and no GitLab token. A real scan needs a read_api token that a person gives you; never invent one.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- The per-app list (report --internal) stays inside the project. Never paste app names anywhere public.
- Ask me before you guess, and before you touch anything outside this folder.

Output: the commands you ran, the report, then a short reading of it and your questions.
```

### Or leave the note for your kind of app

### Rollcall as a plain PHP tool

The CLI on a schedule, and the /metrics and /report.json front controller only if you ask for them.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent setting up Rollcall, the fleet scanner of WELD, the Wayne State application stack, as a plain PHP tool with no framework: a scan on a schedule from the command line and, only if I ask, the /metrics and /report.json endpoints.

Read first:
- https://rollcall.apps.wayne.edu/llms.txt, then the README it links: the Commands, Configuration and Metrics sections.
- ADVISOR, the stack's MCP server, if it is connected: list_stack_rules with permutation raw-php for the rules that apply, and policy_lookup for PHP. If it is not connected, say so and work from the pages.
- This project's composer.json, and how this host runs scheduled jobs.

Task:
1. Add Rollcall with `composer require waynestate/rollcall`. The CLI is `vendor/bin/rollcall`.
2. Write the settings as environment variables from the README's Configuration table: the policy file, the store folder and the source. Start with directory fixtures; switch to GitLab only once I give you a token and a group.
3. Schedule the scan subcommand once a day with --prune, and show me the report subcommand in text and in json.
4. Only if I ask for metrics: serve the package's public/index.php front controller, with ROLLCALL_METRICS_ENABLED and either ROLLCALL_METRICS_TOKEN or ROLLCALL_METRICS_ALLOW set.

Constraints:
- Rollcall is read-only toward every source and writes only its snapshot folder. Keep it that way.
- The GitLab token is a read_api token from the environment. Never commit it, log it or print it.
- Metrics and the public report stay off unless I turn them on.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ, schedules included.
- Ask me before you guess, and before you touch a deploy or a scheduler.

Output: the files you changed, the schedule entry for each OS, then your questions.
```

### Rollcall in a Laravel app

The scheduled scan and the dashboard of totals. The dashboard is the package's own Blade page, whatever your app renders with.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent adding Rollcall, the fleet scanner of WELD, the Wayne State application stack, to a Laravel app: the scheduled scan, the dashboard page of totals and, only if I ask, the internal per-app page.

Read first:
- https://rollcall.apps.wayne.edu/llms.txt, then the README it links: the Laravel, Configuration and Metrics sections.
- ADVISOR, the stack's MCP server, if it is connected: list_stack_rules with the permutation that fits this app (laravel-blade, laravel-blade-vue or laravel-inertia-vue), and policy_lookup for PHP and Laravel. If it is not connected, say so and work from the pages.
- composer.json, composer.lock, config/, routes/, bootstrap/app.php and .env.example.

Task:
1. Add Rollcall with `composer require waynestate/rollcall`. Its service provider registers itself.
2. Publish the config with `php artisan vendor:publish --tag=rollcall-config`, and add the settings to .env.example as placeholders.
3. Schedule `php artisan rollcall:scan` once a day.
4. Turn on the dashboard with ROLLCALL_DASHBOARD_ENABLED. It is the package's Blade page in WayneUI's class names, even in an Inertia app; give it WayneUI's stylesheet with ROLLCALL_WAYNEUI_CSS.
5. Leave the per-app page (ROLLCALL_INTERNAL_ENABLED) off unless I ask. It runs behind the web and auth.sesame middleware and fails closed without them.

Constraints:
- Meet each setting the Laravel way (config, the scheduler, middleware) before writing code of your own.
- If this app already serves /metrics (HELMET does), leave Rollcall's metrics off.
- Never commit a real token or a .env file.
- Ask me before you guess, and before you touch migrations, auth or anything that deploys.

Output: the files you changed, the scheduler entry, then your questions.
```

Your agent reads the same pages you do. [llms.txt](https://rollcall.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://rollcall.apps.wayne.edu/llms-full.txt) has all of them in one file, and this page has a [Markdown twin](https://rollcall.apps.wayne.edu/index.md). The buttons only copy text or open the tool you pick; nothing is sent anywhere.

Classroom rules

## For noticing who is missing, not for shaming them

1. ### It enforces nothing.
   It never opens an issue, leaves a comment or deploys anything, and it exits `0` whatever it finds.
2. ### The public report has totals only.
   Counts and progress. There are no app names, owners or rankings in it.
3. ### It only reads.
   `GET` and `HEAD` only, and a test fails if a write verb shows up in `src/`. The one thing it writes is its own snapshot folder.
4. ### Metrics stay off until you turn them on.
   Once they're on, `/metrics` needs a bearer token or an allowed address. If neither is set, nobody gets in.

Three sources and a policy

## Where the answers come from

Rollcall reads three sources plus CREED's policy, and writes one snapshot per scan day. Each source also has a fixture adapter, so you can try all of it without a live GitLab.

- Repositories`composer.json` and `.lock`, from GitLab or a folder
- App listthe PHP image each app runs
- [PROCTOR](https://proctor.apps.wayne.edu/) one JSON report per app
- [CREED](https://creed.apps.wayne.edu/) `policy.json`, the dates

Rollcall scans and compares

- Terminal`text` and `json` reports
- /metricsPrometheus text, off by default
- Grafanathe bundled burn-down dashboard
- Laravel pagea WayneUI dashboard of totals

Rollcall's place in the stack

## The class list

These are the parts of the Wayne State application stack, and what each one has to do with Rollcall. WELD is the Laravel starter app that has most of them installed.

1. [WayneUI](https://wayneui.apps.wayne.edu/)the interface; the dashboard wears its classes
2. [CREED](https://creed.apps.wayne.edu/)writes the dates Rollcall reads
3. [PROCTOR](https://proctor.apps.wayne.edu/)checks one app; Rollcall collects the results
4. [Preflight](https://preflight.apps.wayne.edu/)CI templates, where PROCTOR reports are born
5. [SESAME](https://sesame.apps.wayne.edu/)the auth package Rollcall counts
6. [HELMET](https://helmet.apps.wayne.edu/)baseline runtime, with its own `/metrics`
7. [Pinstripe](https://pinstripe.apps.wayne.edu/)the Pint and PHPStan rules this repo wears
8. [Sourdough](https://sourdough.apps.wayne.edu/)starters that begin in the front row
9. [WELD](https://weld.apps.wayne.edu/)the Laravel starter app, with the stack already set up
10. Rollcallyou are here: the fleet census and EOL burn-down
11. [Booster](https://booster.apps.wayne.edu/)moves the back row forward
12. [Squire](https://squire.apps.wayne.edu/)carries the release to Citadel
13. [Drawbridge](https://drawbridge.apps.wayne.edu/)the planned sign-in gateway for Citadel
14. [ADVISOR](https://advisor.apps.wayne.edu/)the MCP server your agent asks about the stack
