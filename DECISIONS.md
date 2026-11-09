# Rollcall decisions

The decisions that shape Rollcall, one line each, with where the full record lives. A decision with a context worth
keeping gets an ADR in [ADR/](ADR/); a question nobody has settled yet lives in
[OPEN_QUESTIONS.md](OPEN_QUESTIONS.md) until it is, then moves here. Roll call for the rules: each one answers present.

## Recorded as ADRs

| ADR | Decision | Date |
|---|---|---|
| [ADR-0001](ADR/ADR-0001-saved-files-never-a-live-call.md) | Apart from GitLab, read saved files, never a live service: no Citadel account, no unattended endoflife.date or Packagist call | 2026-10-28 |
| [ADR-0002](ADR/ADR-0002-public-means-counts.md) | Public output is counts only; the per-app list is internal and off by default; no report carries an owner; owners live in an owners file read only by the digest (Rollcall's own, `owners.json`, in the repo with roles only, since 2026-11-08) | 2026-10-28 |
| [ADR-0003](ADR/ADR-0003-proctor-owns-overrides.md) | PROCTOR is the one source of truth for overrides; Rollcall shows them and stores none | 2026-11-03 |
| [ADR-0004](ADR/ADR-0004-the-mark-is-the-png.md) | The generated PNG is the mark; nobody redraws it; a vector would be traced | 2026-11-03 |
| [ADR-0005](ADR/ADR-0005-advisor-module-not-rollcall-mcp.md) | Coding agents use the ADVISOR module (three read-only tools), not a `rollcall mcp` command | 2026-11-03 |
| [ADR-0006](ADR/ADR-0006-opt-in-digests-print-first.md) | Owner digests are opt-in, print by default and mail only behind `--send` plus three settings | 2026-11-07 |
| [ADR-0007](ADR/ADR-0007-one-image-source-citadel-is-one-of-them.md) | One image source per scan: the portable image list or Citadel's registrations export, never both | 2026-11-07 |
| [ADR-0008](ADR/ADR-0008-the-contract-and-semver.md) | A JSON Schema contract, proven on real output, versioned by SemVer from 1.0 | 2026-11-07 |

## Smaller decisions, recorded where they apply

| Decision | Where |
|---|---|
| CREED owns the version policy; Rollcall carries a generated copy of its reader in `src/Creed/`, checked by `composer creed-copy:check` | README, "How it is built" |
| State is derived from `policy.json` by date, never decided in code; endoflife.date is a drift check only | `BEST_PRACTICES.md`, "Take version states from one source" |
| The scanner token is a GitLab group access token with `read_api` only, Reporter role and an expiry; a role, the stack maintainer, rotates it, not a named person (2026-11-08) | `docs/security.md`, "The scanner token and how to rotate it"; question 4 |
| Snapshots are kept 90 days by default; the newest is never deleted | `ROLLCALL_RETENTION_DAYS`; question 9 |
| An owner counts as confirmed for 365 days after its `"confirmed"` date by default, then `rollcall owners` calls it unknown and fails the weekly scan job | `ROLLCALL_OWNERS_CONFIRM_DAYS`; question 12 |
| Metrics are off by default, in Prometheus format, behind a token or an allowlist | `docs/guides/metrics.md` |
| A bill of materials, when one is produced, is CycloneDX | Question 10 |
| Every developer script ships as a `.sh` and `.ps1` pair (bash 3.2, no host bash on Windows) | `docs/reference/scripts.md`, `tests/ScriptPairsTest.php` |
| git.wayne.edu is the canonical repository; GitHub is a read-only push mirror | README, "Where the code lives" |
