---
title: "What the report says | Rollcall docs"
description: "The sections of a Rollcall report: versions, states, drift kinds, progress and the EOL burn-down."
url: https://rollcall.apps.wayne.edu/docs/guides/what-the-report-says.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# What the report says[#](https://rollcall.apps.wayne.edu/docs/guides/what-the-report-says.html#what-the-report-says)

This page explains each part of the report. Next: [run the collector in Docker](https://rollcall.apps.wayne.edu/docs/guides/collector-docker.html) if you scan a real GitLab.

- **PHP image, declared PHP floor, Laravel**: apps per version, the state from the policy (`active`, `security_only`, `eol`, `unknown`), the date security support ends and the days left (negative once past).
- **Auth package and PROCTOR**: apps per auth package, and per PROCTOR result (`pass`, `fail`, `none`).
- **Drift**, counted per kind: `image_eol`, `image_unknown`, `image_below_floor`, `floor_allows_eol`, `floor_unbounded`, `eol_past_grace` (past the policy's grace days), `laravel_eol`, `proctor_missing`, `proctor_failing`, `override_expired`.
- **Progress**: apps that moved since the previous scan and apps that went from an EOL image to a supported one. In the internal view each upgraded app carries the day it landed.
- **EOL burn-down**: for each scan day, how many apps ran an EOL image on that day.

State is never decided in code: it is derived from the dates in `policy.json` by CREED's reader. A version the policy does not list is `unknown`.
