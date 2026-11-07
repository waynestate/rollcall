---
title: How it is built
description: A framework-free core plus thin entry points, a generated copy of CREED's policy reader, and three data sources.
---

# How it is built

This page shows the moving parts. Next: [security notes](security.md).

A framework-free core (`src/`) plus thin entry points that hold no rules:

- `bin/rollcall`: the CLI (`scan`, `report`, `drift`, `advisories`, `prune`). One PHP command, so it works the same on Windows, macOS and Linux.
- `public/index.php`: a raw-PHP front controller for `GET /metrics` and `GET /report.json`.
- `src/Laravel/`: a Laravel adapter with a dashboard page, a `/metrics` route and `php artisan rollcall:scan` and `rollcall:prune`.

CREED's policy reader lives under `src/Creed/` (namespace `Waynestate\Rollcall\Creed`) as a generated copy, made
by CREED's `bin/export.php`. CREED is on Packagist now, so the copy can become a `require` of `waynestate/creed`;
until Rollcall switches, the copy stays. To refresh it, run
`php <creed>/bin/export.php --namespace='Waynestate\Rollcall\Creed' --to=src/Creed` from a CREED checkout.
`composer creed-copy:check` proves the copy has not drifted (it reads the checkout from `CREED_PATH`); a hand edit
to `src/Creed/` fails that check.

Data comes in through three interfaces. Each has a fixture adapter, so you can try everything without a live host:

| Source | Interface | 0.1 adapters |
|---|---|---|
| Repositories (`composer.json`, `composer.lock`) | `RepositorySource` | a directory of fixtures; GitLab over PSR-18, tested against recorded responses |
| Runtime image per app | `ImageSource` | a JSON export of the app list from any platform, or Citadel's own registrations export (which adds forced upgrade dates) |
| PROCTOR result | `ProctorReportSource` | a directory of PROCTOR `--format=json` reports |

Rollcall only reads from every source. The one thing it writes is its own snapshot directory.
