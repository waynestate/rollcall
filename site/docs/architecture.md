---
title: "How it is built | Rollcall docs"
description: "A framework-free core plus thin entry points, a generated copy of CREED's policy reader, three data sources and the decisions behind them."
url: https://rollcall.apps.wayne.edu/docs/architecture.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# How it is built[#](https://rollcall.apps.wayne.edu/docs/architecture.html#how-it-is-built)

This page shows the moving parts. Next: [security notes](https://rollcall.apps.wayne.edu/docs/security.html).

A framework-free core (`src/`) plus thin entry points that hold no rules:

- `bin/rollcall`: the CLI (`scan`, `report`, `digest`, `drift`, `advisories`, `prune`). One PHP command, so it works the same on Windows, macOS and Linux.
- `public/index.php`: a raw-PHP front controller for `GET /metrics` and `GET /report.json`.
- `rollcall_dashboard()`: the dashboard page for a raw PHP app, one call in a file you serve. It prints the same renderer (`src/Dashboard/DashboardPage.php`) as the Laravel page, so the two cannot drift.
- `resources/js/RollcallDashboard.vue`: the same dashboard as a Vue component for an Inertia app's own page. The Laravel adapter hands it the same report as props, and the CI job `dashboard-vue` fails it on any difference from the PHP renderer's markup.
- `src/Laravel/`: a Laravel adapter with a dashboard page, a `/metrics` route and `php artisan rollcall:scan` and `rollcall:prune`.

CREED's policy reader lives under `src/Creed/` (namespace `Waynestate\Rollcall\Creed`) as a generated copy, made by CREED's `bin/export.php`. CREED is on Packagist now, so the copy can become a `require` of `waynestate/creed`; until Rollcall switches, the copy stays. To refresh it, run `php <creed>/bin/export.php --namespace='Waynestate\Rollcall\Creed' --to=src/Creed` from a CREED checkout. `composer creed-copy:check` proves the copy has not drifted (it reads the checkout from `CREED_PATH`); a hand edit to `src/Creed/` fails that check.

Weekly CREED copy refresh: a scheduled GitLab job (`creed-copy-refresh`, Mondays, an hour after CREED's policy robot) re-exports the copy from CREED main, rebuilds the versions table and docs, runs `composer creed-copy:check` and `composer ci`, and pushes to main only when both pass. A red run leaves main untouched. The steps are in [Scripts on every OS](https://rollcall.apps.wayne.edu/docs/reference/scripts.html#inside-gitlab-ci-only).

Data comes in through three interfaces. Each has a fixture adapter, so you can try everything without a live host:

| Source | Interface | Adapters |
| --- | --- | --- |
| Repositories (`composer.json`, `composer.lock`) | `RepositorySource` | a directory of fixtures; GitLab over PSR-18, tested against recorded responses |
| Runtime image per app | `ImageSource` | a JSON export of the app list from any platform, or Citadel's own registrations export (which adds forced upgrade dates) |
| PROCTOR result | `ProctorReportSource` | a directory of PROCTOR `--format=json` reports (the default); or, with `ROLLCALL_PROCTOR_SOURCE=gitlab`, each project's proctor job artifact in GitLab CI |

### Where PROCTOR results come from[#](https://rollcall.apps.wayne.edu/docs/architecture.html#where-proctor-results-come-from)

Rollcall never runs PROCTOR. It reads the JSON report PROCTOR already wrote (`proctor check --format=json`), from one of two places, chosen by `ROLLCALL_PROCTOR_SOURCE`:

- `directory` (the default): `ROLLCALL_PROCTOR_DIR`, one file per app, `group/project` read from `group__project.json`. The fixtures and any shared folder a pipeline copies reports into use this.
- `gitlab`: the report straight from CI, with the same `read_api` token and GitLab adapter as the scan (`ROLLCALL_GITLAB_URL` and the groups or projects must be set even when the repositories come from a directory). For each project Rollcall lists the newest finished pipelines on the default branch, takes the newest one that ran the `proctor` job (`ROLLCALL_PROCTOR_JOB`), whether that job passed or failed, and reads `proctor.json` (`ROLLCALL_PROCTOR_ARTIFACT`) from the job's artifact by job id. Three reads per project, usually; every call is listed in [security notes](https://rollcall.apps.wayne.edu/docs/security.html#every-call-rollcall-makes).

GitLab's one-call "artifact from the latest successful pipeline" endpoint is not used on purpose: a failing PROCTOR job fails its pipeline, so that endpoint would show a failing app's last passing report, or none.

In both readers a missing report is no report: the app's PROCTOR result is `none` and the scan carries on. Over CI that covers no pipeline yet, no proctor job in the newest five finished pipelines, a 404 for the file (never kept, or the artifact expired) and CI/CD turned off for the project (a 403 on its pipeline list). A file that is not a PROCTOR report becomes the note "PROCTOR report could not be read" for that app. Any other GitLab error is handled like every other call: a refused token stops the scan, 429 waits, anything else fails it.

For the CI reader to find a report, the app's pipeline must keep PROCTOR's JSON report as a file artifact (`artifacts: paths`, not only `artifacts: reports: codequality`, which this endpoint cannot read), with `when: always` so a failing job still keeps it, and an `expire_in` longer than the gap between scans.

Rollcall only reads from every source. It writes its own snapshot directory, and digest files only when `rollcall digest --out` asks for them.

The decisions behind this shape (saved files over live calls, counts in public, one image source, the contract) are recorded in [DECISIONS.md](https://github.com/waynestate/rollcall/blob/main/DECISIONS.md) and [ADR/](https://github.com/waynestate/rollcall/tree/main/ADR).
