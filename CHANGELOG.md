# Changelog

All notable changes to Rollcall. The format follows Keep a Changelog; versions follow SemVer.

## [Unreleased]

- Added: a contract. `contract/` holds JSON Schemas (draft 2020-12) for the JSON report (public and internal), the stored snapshot and the image list export, and `tests/ContractTest.php` validates real output from the real commands and `GET /report.json` against them, using `opis/json-schema` (a dev dependency). Rollcall's own objects are closed, so a new field is a contract change in the same commit. Docs: Reference > Contract.
- Added: per-version history. Each point of the report's `burn_down` carries the same roll three ways, counts only: `states` (per support state that day: `active`, `security_only`, `eol`, `unknown`), `php_image` (per PHP minor of the image, such as `{"8.1": 3, "8.4": 9}`) and `laravel` (per Laravel major), each in version order with `unknown` last. The existing `date`, `apps`, `eol`, `supported` and `unknown` fields are unchanged, so the change is additive. The breakdown is worked out from each stored snapshot when the report is built, so snapshots written by 0.1.9 and older get one too and the stored snapshot format stays at `schema_version` 1; it reaches back as far as `rollcall prune` keeps snapshots. ADVISOR's `rollcall_burn_down` tool returns the new fields. A reader of a 0.1.9 report should treat burn-down points without these keys as having no breakdown.

- Changed: The canonical repository is git.wayne.edu (ADR-0021); docs, links and package metadata no longer point at the archived nougat copy.
- Added: The Proof page records its last run.
- Security: the front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).
- Changed: docs sections carry the front page's folios. Each numbered section of a docs page opens with a red Caslon "§ 1", "§ 2" in front of its heading, as every chapter of the front page does (Related, the index, stays unnumbered); the mark is decoration, so the heading's accessible name is unchanged. With reduced motion off, each folio soaks into the paper as its section scrolls into view, in browsers with scroll-driven animations. Source: `docs/theme/rollcall.css`.
- Added: a Proof docs page (Reference > Proof). The first scheduled `rollcall-scan` on GitLab CI read the 13 stack projects (13 rows, blob ids matching `main`, 23 s job), and a rerun over 58 Wayne State applications from the stack's proof corpus gave every repository a row with 0 tool errors; it confirms the in-house auth package fix, explains the pipeline's one warning (PROCTOR on `deploy/`), and lists what is not proven yet (no image export, no PROCTOR reports, one snapshot).
- Changed: the text report's first line says `scan notes: N` instead of `scan problems: N`. The count is the notes a scan prints and carries on past (today: a source that is not configured), and the 2026-11-03 proof run had mistaken those two notes for unexplained errors. The JSON key `totals.scan_errors` and the metric `rollcall_scan_errors` keep their names. What the report says (README and docs) now explains the count, and that the EOL burn-down stays `unknown` until an image export is configured.
- Fixed: docs tables no longer scroll sideways in a narrow column. On a phone (or a tablet beside the sidebar) the settings table on the Configuration page turns each variable into an index card with Meaning, Default and Read in labeled in the margin, and the other tables stack each row under its name; at every width a long name in prose breaks instead of widening the table. The markup is unchanged, so screen readers still get a table with its column heads. Source: `docs/theme/rollcall.css`.
- Changed: the docs pages wear the front page's look, so the front page and the docs read as one roll book: the same masthead and main menu (Docs marked current with the highlighter), ruled paper and the red margin rule, Caslon titles, Courier Prime type, carbon-copy code blocks with a stamped Copy button, register-style tables, callouts as stamped index cards, code tabs as ledger tabs, stamped previous and next buttons and the green cloth back cover. Dark mode is derived (an ink-blue night); motion only plays when reduced motion is off. Source: `docs/_header.html` and `docs/theme/rollcall.css`, wired in `docs/docs.json`.
- Changed: synced Squire's unified docs builder (theme hook, callouts, code tabs, task lists, keyboard-reachable scrolling code blocks, a Copy button that no longer reports a failed copy after a good one). The docs site is rebuilt.
- Changed: an entry in `ROLLCALL_AUTH_PACKAGES` without a slash is a bare package name and matches that package under any vendor (`wayne-cas` matches `anyvendor/wayne-cas`); an entry with a slash still matches exactly, and the first match in list order wins. The built-in list gains `wayne-cas` and `simplecas` after the existing five, and the report carries the real locked package name, vendor included. The 2026-11-03 proof run over the real fleet found the gap: every app counted as having no auth package, although about half use an in-house CAS client the public-only list missed.
- Added: the dashboard announces what it shows (a status line) and has a rendered-HTML accessibility test.
- Changed: the front page has a main menu (sections plus Docs), so the docs site is one click from the front page.
- Changed: synced Squire's docs builder and agent-ready kit (bin/docs-site.php, bin/agent-ready.php, site/assets/agent-ready.js): table cells strip Symfony console style tags, readme images are copied byte for byte into site/docs/, and the kit JS uses bindingless catch blocks. The command reference and docs site are rebuilt.
- Docs: the front-page sample report shows ROLLCALL 0.1.9.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Docs: OPEN_QUESTIONS records that override storage is closed (PROCTOR is the source of truth), that the front page is deployed, and that the git.wayne.edu mirror exists.
- Added: a test that the public files name no per-app finding, internal hostname or IP address.

## [0.1.9] - 2026-11-03

- Added: ROLLCALL_GITLAB_PROJECTS, a project allowlist for the GitLab source (only those projects are read; no group is listed).
- Added: a rollcall-scan CI job (weekly schedule with ROLLCALL_JOB=scan, or run by hand) that scans the 13 stack projects read-only and keeps the snapshot as a 30 day artifact; without ROLLCALL_GITLAB_TOKEN it skips green.
- Changed: backlog items ROLLCALL-6 (SVG brand mark) and ROLLCALL-33 (`rollcall mcp` command) are closed as decided on 2026-11-03. The PNG is the mark, and ADVISOR is a stand-alone MCP server that loads the three read-only tools of the Rollcall module, so neither is needed.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.
- Added: docs site built from docs/ with generated command and environment reference

## [0.1.8] - 2026-11-03

- Added: a three-OS smoke test checklist in MAINTAINING.md.
- Added: an accessibility gate for the front page (axe-core in Playwright, Docker), run by hand before a release.
- Added: a Dockerfile that runs the collector as a non-root user, settings from environment variables.
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.

## [0.1.7] - 2026-11-02

- Added: an ADVISOR module (waynestate/advisor) with three read-only tools over the public counts.
- Added: the front page versions table is generated from policy.json, and composer ci fails when it is stale.
- Changed: docs say the read_api token is a GitLab bot token requested from the GitLab administrator.
- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).

## [0.1.6] - 2026-10-29

- Fixed: stale capability claims in the site and README.
- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.

## [0.1.5] - 2026-10-29

- Changed: project names written as words except acronyms.
- Fixed: Rollcall reported 0.1.0 after later releases.

## [0.1.4] - 2026-10-29

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
