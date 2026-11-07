---
title: What the report says
description: The sections of a Rollcall report: versions, states, drift kinds, progress, the EOL burn-down and forced upgrades.
---

# What the report says

This page explains each part of the report. Next: [Citadel's registrations export](citadel.md) if your apps run on Citadel.

- **Apps present and scan notes**: the first line counts the apps the scan read, then the notes it printed and carried
  on past (`totals.scan_errors` in JSON, `rollcall_scan_errors` in metrics). Today a note means a source is not
  configured: with no image export the PHP image column is `unknown`, with no PROCTOR reports every app is `none`.
  `scan` prints each note as it saves the snapshot, and `report --format=json --internal` lists them. A note is not a failed scan; a
  source that refuses (a bad token) stops the scan instead.
- **PHP image, declared PHP floor, Laravel**: apps per version, the state from the policy (`active`, `security_only`,
  `eol`, `unknown`), the date security support ends and the days left (negative once past).
- **Auth package and PROCTOR**: apps per auth package, and per PROCTOR result (`pass`, `fail`, `none`).
- **Drift**, counted per kind: `image_eol`, `image_unknown`, `image_below_floor`, `floor_allows_eol`,
  `floor_unbounded`, `eol_past_grace` (past the policy's grace days), `laravel_eol`, `proctor_missing`,
  `proctor_failing`, `override_expired`.
- **Progress**: apps that moved since the previous scan and apps that went from an EOL image to a supported one. In
  the internal view each upgraded app carries the day it landed.
- **EOL burn-down**: for each scan day, how many apps ran an EOL image on that day. It reads the image, not the
  declared floor, so until an image export is configured every app is `unknown` here even when the floor table
  already shows EOL versions.
- **The roll per version, scan after scan**: each burn-down day also carries the same apps counted three ways, so a
  dashboard can chart which lines the fleet is leaving and which it is joining. Counts only, never an app name:
  - `states`: per support state on that day (`active`, `security_only`, `eol`, `unknown`); `supported` is `active`
    plus `security_only`.
  - `php_image`: per PHP minor of the image, in version order with `unknown` last, such as
    `{"8.1": 3, "8.4": 9, "unknown": 2}`.
  - `laravel`: per Laravel major, the same way. An app with no Laravel, or one Rollcall could not read, is `unknown`.

  The breakdown is worked out from each stored snapshot when the report is built, so snapshots written before it
  existed (Rollcall 0.1.9 and older) get one too, and the stored snapshot format is unchanged (`schema_version` 1).
  It reaches back as far as `rollcall prune` keeps snapshots (`ROLLCALL_RETENTION_DAYS`). A report written by 0.1.9
  or older has no `states`, `php_image` or `laravel` on its burn-down days: read that as "no breakdown", not as zero
  apps.

- **Forced image upgrades**: with [Citadel's export](citadel.md) as the image source, how many apps have a forced
  base-image upgrade pending, how many are due today or earlier, and the next date (`forced_upgrades`, counts only).
  The internal per-app list carries each app's `upgrade_due`. All three are zero with any other image source.

State is never decided in code: it is derived from the dates in `policy.json` by CREED's reader. A version the
policy does not list is `unknown`.
