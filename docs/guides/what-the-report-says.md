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
- **MySQL 5.6 CI job**: apps by the state of PROCTOR's `mysql56-ci` finding (CREED's `databases.md` rule says an app's
  migrations must run in CI against MySQL 5.6): `held` when the report carries a `held` finding, `missing` when it
  carries a `no-job` finding, `not_applicable` when it carries neither and came from PROCTOR 0.15.1 or newer (the check
  ran and had nothing to say: no migrations), and `unknown` for an app with no report, one that could not be read, or a
  report from PROCTOR below 0.15.1, which cannot tell `held` from `not_applicable`. The dashboard shows the four
  counts as panels under "Apps present" (held, missing, not applicable, unknown); `missing` wears a red badge with
  the word in it while any app is missing the job, and the internal page's table has each app's state in a
  "MySQL 5.6 CI" column beside PROCTOR's.
- **Booster hop record**: apps by how their last committed Booster run ended, from the `booster-hop.json` Booster
  writes at the repository root after a real upgrade run: `pass`, `fail` (a hop's gate or checks failed), and `none`
  for an app with no record or one that could not be read (that one also gets a scan note). In the internal view
  each app carries its `booster` result, the `booster_version` that wrote the record and `booster_at`, when the run
  finished. A pass says the app's last hop landed, not that the app is current: read it beside the PHP image. The
  dashboard shows the three counts as panels after the MySQL 5.6 ones (pass, fail, no record); `fail` wears a red
  badge with the word in it while any app's last hop failed, and the internal page's table has each app's result in
  a "Booster" column before Drift.
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
  The same counts reach [metrics](metrics.md) and ADVISOR's `rollcall_fleet_summary` ([ADVISOR](advisor.md)). The
  internal per-app list carries each app's `upgrade_due`. All three are zero with any other image source. The
  dashboard shows the three as panels under "Apps present" (apps with an upgrade pending, due today or earlier, the
  next date ahead); "Due today or earlier" wears a red badge with the words in it while any app's date has come, and
  the internal page's table has each app's date in an "Upgrade due" column beside its image state, in the same badge
  once the report's day has reached it ("2026-11-01, date has come").

State is never decided in code: it is derived from the dates in `policy.json` by CREED's reader. A version the
policy does not list is `unknown`.
