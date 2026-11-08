---
title: "Use Rollcall from your coding agent (ADVISOR) | Rollcall docs"
description: "Rollcall ships an ADVISOR module with three read-only tools that answer public counts only."
url: https://rollcall.apps.wayne.edu/docs/guides/advisor.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Use Rollcall from your coding agent (ADVISOR)[#](https://rollcall.apps.wayne.edu/docs/guides/advisor.html#use-rollcall-from-your-coding-agent-advisor)

This page installs the ADVISOR module and names the three tools. Next: [run the checks](https://rollcall.apps.wayne.edu/docs/checks.html).

Rollcall ships a module for [ADVISOR](https://packagist.org/packages/waynestate/advisor), the stack's MCP server: three read-only tools, and a small creed goes with them: every app answers present, the agent may ask, and nobody gets ranked.

Install both packages where the MCP server runs:

```
composer require waynestate/advisor waynestate/rollcall
```

Then name the module in your own manifest file. Rollcall ships none on purpose, so nothing loads by accident:

```json
{
    "modules": [
        "Waynestate\\Rollcall\\Advisor\\RollcallModule"
    ]
}
```

and point `ADVISOR_MODULES_FILE` at that file. The tools read the snapshot store behind `ROLLCALL_STORE_DIR`; with an empty store each one says so in plain words and names `rollcall scan`.

| Tool | What it answers |
| --- | --- |
| `rollcall_fleet_summary` | Counts from the latest snapshot: apps by PHP image, declared floor, Laravel line, auth package and PROCTOR result, drift per kind, progress since the previous scan, forced image upgrades (`forced_upgrades`: pending, overdue, next due), and the snapshot date |
| `rollcall_burn_down` | The dated EOL series, one point per scan day, each with its counts per support state, PHP minor and Laravel major; optional `days` (an integer, 1 to 365) keeps only the most recent days |
| `rollcall_supported_versions` | Every version in CREED's policy, grouped by what it says today: active, security only, or end of life |

The tools take no path, command or URL, make no network call, and answer public counts only: the same fields `/report.json` serves. The per-app list stays internal and never leaves through a tool. Each answer names its source and its dates.
