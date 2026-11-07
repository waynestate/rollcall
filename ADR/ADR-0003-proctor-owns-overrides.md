# ADR-0003: PROCTOR is the one source of truth for overrides

- **Status:** Accepted
- **Date:** 2026-11-03
- **Deciders:** Adam Perkins (Enterprise Applications), project owner. Recorded retroactively from `OPEN_QUESTIONS.md`
  ("Not built yet", override storage) and the CHANGELOG.
- **Resolves:** whether Rollcall stores overrides of its own.

## Context

An app sometimes runs past a rule on purpose: an approved exception with a reason and an expiry. PROCTOR already
records those overrides and reports them in its JSON. A second store in Rollcall would let the two disagree about
whether an exception exists, and the one that is wrong would be the one people read.

## Decision

Rollcall keeps no override storage. It reads the overrides PROCTOR reports and shows each one with its reason and
expiry; an expired override stays in view, marked expired (`override_expired` drift). Rollcall never re-runs a
PROCTOR rule either: it reads the result.

## Consequences

- To grant or end an exception, change it in PROCTOR. Rollcall shows the change on the next scan.
- The backlog item for override storage is closed as decided, not as missing work.
- If PROCTOR's report shape changes, `ProctorReportReader` and its fixtures change with it.
