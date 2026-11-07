---
title: Proof
description: "Rollcall's first scheduled scan of the stack on GitLab CI, and a rerun over 58 Wayne State applications on 2026-11-06: what ran, what held, what it caught in Rollcall, and what it does not prove yet."
---

# Proof

This page says how Rollcall was proven on real repositories: what ran, what held, what the runs caught in Rollcall itself, and what they do not prove yet. Next, see [What the report says](../guides/what-the-report-says.md) for how to read the output quoted here.

A register is only worth something on a day with real students in the room. Rollcall had two such days on 2026-11-06.

## Last run

| Check | Date | Commit | Result | Duration |
|---|---|---|---|---|
| `composer ci` | 2026-11-07 | `aed8dd9` | pass: manifest, 199 tests / 1220 assertions, style, generated files, static analysis, audit | 181.3 s |
| `rollcall scan` | 2026-11-07 | `aed8dd9` | pass: 4 fixture apps present, 2 notes (no image source, no PROCTOR reports) | 1.1 s |
| `rollcall report` | 2026-11-07 | `aed8dd9` | pass: text report, 4 apps | 1.0 s |
| `rollcall report --format=json` | 2026-11-07 | `aed8dd9` | pass: JSON report, 4 apps | 1.0 s |
| `rollcall report --format=json --internal` | 2026-11-07 | `aed8dd9` | pass: internal JSON, 4 apps | 1.0 s |
| `rollcall report --format=prometheus` | 2026-11-07 | `aed8dd9` | pass: Prometheus text, 4 apps | 1.2 s |
| `rollcall advisories` (saved feed) | 2026-11-07 | `aed8dd9` | pass: 4 package versions answered, 1 affected, 1 no data | 1.8 s |
| `rollcall drift --component=php` (saved feed) | 2026-11-07 | `aed8dd9` | pass: 1 PHP date compared, 1 difference between the feeds | 1.5 s |
| `rollcall prune --dry-run` | 2026-11-07 | `aed8dd9` | pass: nothing to prune | 1.2 s |
| PROCTOR check on `deploy/` | 2026-11-07 | `aed8dd9` | pass: 14 checks, 12 pass, 2 warn (php-floor, ci-pipelines) | 2.2 s |

The rollcall rows ran against the fixtures bundled under `tests/fixtures/` in one `composer:2.10.3` container, with no credentials; the PROCTOR row installed the deploy layer's committed lock first. To refresh this table, re-run `composer ci` and the commands above and rewrite the rows with the new date, commit and durations. The two runs the page describes, the scheduled GitLab CI scan and the 58-repository corpus rerun, need a live service account and the stack's proof corpus, so they are not repeated here; their results stand as recorded below.

## What ran

**1. The scheduled scan on GitLab CI.** The weekly `rollcall-scan` job (see [Configuration](configuration.md)) ran on its schedule for the first time, on the canonical GitLab, against the 13 stack projects. It used a read-only service account that can see those 13 projects and nothing else, with a `read_api` token held in a masked CI variable. Rollcall 0.1.9 from `main`, `composer:2.10.3`, no image export and no PROCTOR reports configured.

**2. A rerun over the stack's proof corpus.** The shared corpus is every PHP repository in the stack's census of Wayne State applications: 95 repositories, of which 58 have a `composer.json` (54 with a `composer.lock`). Rollcall's directory source reads exactly those two files, so only those two files were copied out of a Linux checkout of each repository. The rerun used the same Rollcall commit plus the change described below, CREED's released `policy.json`, and `--today=2026-11-06`, in one `composer:2.10.3` container with no network:

1. `rollcall scan`, then `rollcall report` as text, JSON, internal JSON and Prometheus text;
2. `rollcall advisories` against a saved Packagist security-advisories response for `laravel/framework` and the known auth packages;
3. `rollcall drift --component=php` against a saved endoflife.date page for PHP;
4. `rollcall prune --dry-run`.

The 13 stack projects were scanned the same way from their committed files, as a cross-check on the CI run.

A run **passes** when every command exits 0 and every repository with a `composer.json` gets a row. A **tool error** is Rollcall breaking: a crash, an exit 2, a repository silently missing from the snapshot, or a number that disagrees with the files.

## Results

| Run | Read | Rows | Tool errors | Time |
|---|---|---|---|---|
| CI schedule | 13 stack projects | 13 | 0 | 23 s job, 11 s scan |
| Rerun: stack | 13 stack projects | 13 | 0 | 1.3 s scan |
| Rerun: corpus | 58 repositories | 58 | 0 | 1.4 s scan |

The CI job's 23 seconds include installing Rollcall; the scan itself, 26 file reads over the GitLab API, took about 11. Each report over the corpus took under 4 seconds.

- **The CI scan read exactly what is committed.** The snapshot records the Git blob id of every file it read, and each id matched `main` of its project. The local rerun of the same 13 projects gave the same answer: every project declares a PHP floor of 8.3, and one of them (WELD) locks Laravel 13 and SESAME as its auth package. That is the fleet the stack wants every app to look like: one row, one floor, no back row.
- **Every corpus repository answered.** All 58 got a row with its declared PHP floor, Laravel line (18 of them run Laravel, from 5.8 to 13) and auth package. The ones with no PHP constraint at all are counted as unbounded, not guessed.
- **Advisories answered for every Laravel version** in the corpus from the saved feed, and said `no data` for the in-house packages Packagist has never heard of, including two locked to a development branch. That is the documented behavior: an absent row is never reported as safe.
- **Drift:** CREED's policy and endoflife.date agree on every PHP date they both state.
- **Prune** found nothing to prune, as it should with one fresh snapshot.
- The fleet tallies themselves (how many apps sit on which version) stay with the stack team, the same way Rollcall keeps its own per-app list internal.

## The warning on the scheduled pipeline

The pipeline that ran the scan finished green with one warning. It was not the scan: it was the allowed-to-fail PROCTOR job that checks the front page's own deploy layer (`deploy/`). Run again locally it says:

- `composer-audit` **fail**: the deploy layer's lock held `symfony/http-foundation` and `symfony/yaml` 7.4.22, with five security advisories between them. Symfony 7.4.23 fixes them, and the deploy lock moved to it the same night.
- `ci-pipelines` **warn**: `deploy/` has no `.gitlab-ci.yml`. True and harmless: the pipeline lives at the repository root.
- `php-floor` **warn**: PHP 8.3 is in security-only support until 2027-12-31.

So the one warning on Rollcall's first real attendance day was Rollcall's own homework, found by PROCTOR, which is the system working.

## What it caught in Rollcall

A run that only finds praise was not looking hard enough. Every finding here is about Rollcall, not the apps:

- **Confirmed fixed: in-house auth packages.** The first corpus run, on 2026-11-03, counted no auth package for any app, because the built-in list only knew public package names and most of the fleet uses an in-house CAS client under a private vendor name. A bare name in `ROLLCALL_AUTH_PACKAGES` now matches under any vendor. This rerun recognized an auth package in 37 of the 58 repositories, as three packages under two vendor names, and named each with its real vendor.
- **Fixed: "scan problems" that were not problems.** The text report's first line said `scan problems: 2` on every run that had no image export and no PROCTOR reports. The 2026-11-03 run took those for two unexplained scan errors and logged them for a look. They are the two notes `scan` prints when a source is not configured. The line now says `scan notes`, and [What the report says](../guides/what-the-report-says.md) explains the count. The JSON key and the metric keep their names, so nothing that reads them breaks.
- **Documented: the burn-down needs the image export.** The EOL burn-down counts apps by the PHP image they run. With no image export every app is `unknown` in it, even while the declared-floor table already shows end-of-life versions. That is the design (a floor is a promise, an image is a fact), and the guide now says so.

## What this does not prove

- **No PHP image column yet.** No export of the runtime image per app exists yet, so the image table, the image drift kinds and the burn-down were all `unknown`. Rollcall's image source is tested on fixtures only.
- **No PROCTOR column yet.** No PROCTOR reports were fed in, so every app shows `none`. The report source is tested on fixtures only.
- **The CI scan covers the stack, not the fleet.** The service account sees the 13 stack projects on purpose. The corpus run covers the fleet, but from copied files, not through the GitLab API.
- **One week, one snapshot.** Drift between scans, upgrade credit and pruning only show over weeks of scheduled runs. They are covered by tests, not yet by a real history.
- **The advisory and endoflife.date feeds were saved on 2026-11-06.** Their answers are only as fresh as the file a person saved.

## Run it on your own repositories

On a folder of repositories laid out as `<group>/<project>/composer.{json,lock}`:

```sh tab="Linux / macOS"
export ROLLCALL_POLICY_FILE=/path/to/creed/policy.json
export ROLLCALL_REPOS_DIR=/path/to/repos
php bin/rollcall scan
php bin/rollcall report
```

```powershell tab="Windows"
$env:ROLLCALL_POLICY_FILE = "C:\path\to\creed\policy.json"
$env:ROLLCALL_REPOS_DIR = "C:\path\to\repos"
php bin/rollcall scan
php bin/rollcall report
```

To scan your GitLab on a schedule instead, follow [Configuration](configuration.md) and the scheduled scan section of the README. Keep `report --internal` output inside your team.

## Related

- [What the report says](../guides/what-the-report-says.md)
- [Commands](commands.md)
- [Configuration](configuration.md)
- [Run the collector in Docker](../guides/collector-docker.md)
