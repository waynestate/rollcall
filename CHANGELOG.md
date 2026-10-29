# Changelog

All notable changes to ROLLCALL. The format follows Keep a Changelog; versions follow SemVer.

## [Unreleased]

### Changed

- Include Pinstripe's raw PHPStan preset (`waynestate/pinstripe:^0.1` dev dependency) instead of carrying a hand
  copy: `phpstan.neon` now includes `vendor/waynestate/pinstripe/phpstan/raw.neon` and keeps only ROLLCALL's paths
  and PHP range. The preset adds deprecation checks, so four calls to the deprecated Symfony Console
  `Application::add()` became `addCommand()`. Pint keeps its own `pint.json`: Pint has no include mechanism for
  config files, and the file already uses the same `per` preset as Pinstripe's `pint/per.json`, plus this repo's
  exclude list.

## [0.1.0] - 2026-10-29

### Added

- Framework-free core: policy reader with state by date, Composer file reader, image-tag reader, PROCTOR report
  reader, scanner, snapshot store, report builder (counts, drift, progress, EOL burn-down).
- Source interfaces for repositories, images and PROCTOR reports, with fixture adapters and a read-only GitLab adapter
  (PSR-18 via `symfony/http-client`) covering `Link` pagination, `HEAD` change detection and `429 Retry-After`.
- CLI: `rollcall scan`, `rollcall report` (text, JSON, Prometheus), `rollcall drift`.
- Optional Prometheus metrics, off by default, with a token or allowlist guard, plus a Grafana dashboard.
- Raw-PHP front controller (`/metrics`, `/report.json`) and a Laravel adapter (dashboard, `rollcall:scan`).
- `composer ci` (manifest, PHPUnit, Pint, PHPStan, audit) on GitLab and GitHub.
- Snapshot retention: `ROLLCALL_RETENTION_DAYS` (default 90; 0 keeps everything), `rollcall scan --prune[=DAYS]`,
  and `rollcall prune [--days=N] [--dry-run]` (plus `php artisan rollcall:prune`). Pruning never deletes the newest
  snapshot, so the dashboard always has data.
