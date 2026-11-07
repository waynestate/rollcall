# ADR-0002: Public means counts; per-app data and owners stay internal

- **Status:** Accepted
- **Date:** 2026-10-28 (owners file added 2026-11-07)
- **Deciders:** Adam Perkins (Enterprise Applications), project owner. Recorded retroactively from `OPEN_QUESTIONS.md`
  (questions 7, 8 and 12), `BEST_PRACTICES.md` and the code.
- **Resolves:** what the public report may say, and where owners are recorded.

## Context

A fleet census can turn into a league table. Naming the apps on an end-of-life PHP version in public tells an
attacker where to look, and ranking teams makes people hide apps instead of upgrading them. Owners still need to
hear about their own apps.

## Decision

1. **Public output is counts only.** The public JSON report, `GET /report.json`, `/metrics`, the dashboard's public
   page and the ADVISOR tools carry counts per version, state, drift kind and day. No app id, no owner, no rank,
   no score.
2. **The per-app list is internal and off by default.** `report --internal` prints it; the Laravel per-app page needs
   `ROLLCALL_INTERNAL_ENABLED=true` and runs behind sign-in middleware, failing closed without it.
3. **No report carries an owner field.** Owners live in an owners file (`ROLLCALL_OWNERS_FILE`), kept outside git by
   the people who run Rollcall, and read only by `rollcall digest`. No owner is ever guessed from commit authors.
4. **Digests are opt-in.** An owner gets one only with `"digest": true`, about only their apps, only when something
   changed. They print by default; mail goes out only with `--send` and three settings (ADR-0006).

## Consequences

- The contract enforces 1 and 3: `report.schema.json` refuses `apps` or `scan_errors` in a public report and has no
  owner field anywhere, and `ContractTest` proves a report that grew one is refused.
- `PublicSurfaceTest` fails if a fixture app path appears in the docs, the site or the top-level files.
- A people source (Rolodex) or a scheduled ownership check can replace the owners file later without touching the
  report.
