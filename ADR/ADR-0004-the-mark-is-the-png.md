# ADR-0004: The mark is the generated PNG; nobody redraws it

- **Status:** Accepted
- **Date:** 2026-11-03 (the mark itself is from 2026-10-28)
- **Deciders:** Adam Perkins (Enterprise Applications), project owner. Recorded retroactively from the CHANGELOG
  (backlog ROLLCALL-6 closed) and `site/brand/README.md`.
- **Resolves:** whether Rollcall needs an SVG mark.

## Context

Rollcall's mark (an open roll book in cream with one gold accent on the green `#0C5449` tile) came from ChatGPT with
its brand board. The backlog asked for an SVG version. A hand-drawn or code-drawn vector would be a new mark that
looks almost like the old one, which is worse than either.

## Decision

The PNGs in `site/brand/` are the mark, at every size the sites and GitLab need. Nobody draws it and nobody edits its
geometry. If a vector is ever needed, it is traced from the PNG (vtracer or potrace), never redrawn.

## Consequences

- Backlog ROLLCALL-6 (SVG mark) is closed as decided.
- The front page, docs masthead, favicons and README art all use the PNG set.
