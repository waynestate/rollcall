# Changelog

All notable changes to ROLLCALL. The format follows Keep a Changelog; versions follow SemVer.

## [Unreleased]

### Added

- `ROLLCALL_GITLAB_URL` may now come from PLAYBOOK (`waynestate/playbook`, private on git.wayne.edu) when the
  environment has none: the environment wins, PLAYBOOK fills the gap, and the missing-setting error now points at
  the README. The GitLab token stays environment-only.

## [0.1.3] - 2026-10-29

- CREED reader re-exported from CREED 0.1.2: versions older than the oldest listed line are now end of life instead of refused.

## [0.1.2] - 2026-10-29

### Added

- `rollcall advisories --feed=<file>`: checks each app's locked `laravel/framework` and auth-package versions
  against a saved Packagist security-advisories response, one line per package and version (status, count, advisory
  ids, app count). A package with no advisory data prints `no data`, never `safe`; an unparseable version or range
  also says `no data`. ROLLCALL does not fetch the feed itself, and the output carries no app ids.

## [0.1.1] - 2026-10-29

### Added

- Token-expiry warning in `scan`: the GitLab source reads the calling token's own record
  (`GET /api/v4/personal_access_tokens/self`) and prints a note 14 days before it expires, or when it has already
  expired. The check is advisory: a refused, unreachable or unparseable answer prints nothing and never fails the
  scan, and the token value never appears in output. Rotating the token stays the owner's job.

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
