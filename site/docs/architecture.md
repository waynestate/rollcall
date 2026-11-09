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
- `src/Laravel/`: a Laravel adapter with a dashboard page, a `/metrics` route and `php artisan rollcall:scan` and `rollcall:prune`.

CREED's policy reader lives under `src/Creed/` (namespace `Waynestate\Rollcall\Creed`) as a generated copy, made by CREED's `bin/export.php`. CREED is on Packagist now, so the copy can become a `require` of `waynestate/creed`; until Rollcall switches, the copy stays. To refresh it, run `php <creed>/bin/export.php --namespace='Waynestate\Rollcall\Creed' --to=src/Creed` from a CREED checkout. `composer creed-copy:check` proves the copy has not drifted (it reads the checkout from `CREED_PATH`); a hand edit to `src/Creed/` fails that check.

Weekly CREED copy refresh: a scheduled GitLab job (`creed-copy-refresh`, Mondays, an hour after CREED's policy robot) re-exports the copy from CREED main, rebuilds the versions table and docs, runs `composer creed-copy:check` and `composer ci`, and pushes to main only when both pass. A red run leaves main untouched. The steps are in [Scripts on every OS](https://rollcall.apps.wayne.edu/docs/reference/scripts.html#inside-gitlab-ci-only).

Data comes in through three interfaces. Each has a fixture adapter, so you can try everything without a live host:

| Source | Interface | Adapters |
| --- | --- | --- |
| Repositories (`composer.json`, `composer.lock`) | `RepositorySource` | a directory of fixtures; GitLab over PSR-18, tested against recorded responses |
| Runtime image per app | `ImageSource` | a JSON export of the app list from any platform, or Citadel's own registrations export (which adds forced upgrade dates) |
| PROCTOR result | `ProctorReportSource` | a directory of PROCTOR `--format=json` reports |

Rollcall only reads from every source. It writes its own snapshot directory, and digest files only when `rollcall digest --out` asks for them.

The decisions behind this shape (saved files over live calls, counts in public, one image source, the contract) are recorded in [DECISIONS.md](https://github.com/waynestate/rollcall/blob/main/DECISIONS.md) and [ADR/](https://github.com/waynestate/rollcall/tree/main/ADR).
