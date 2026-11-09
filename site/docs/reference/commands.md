---
title: "Commands | Rollcall docs"
description: "The rollcall commands, generated from the CLI, with the exit codes and what each one prints."
url: https://rollcall.apps.wayne.edu/docs/reference/commands.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Commands[#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#commands)

This page lists the commands (generated from the CLI) and what each one prints, plus the exit codes.

| Command | What it does |
| --- | --- |
| `rollcall advisories` | Check locked package versions against a saved Packagist security-advisories response. |
| `rollcall digest` | Print (or write, or send) each opted-in owner's digest of what changed in their apps. |
| `rollcall drift` | Compare policy.json with a saved endoflife.date v1 product file. |
| `rollcall owners` | List the scanned apps whose owner is unknown (no owners-file entry, or none confirmed within ROLLCALL_OWNERS_CONFIRM_DAYS); exit 1 when there is one. |
| `rollcall prune` | Delete snapshots older than the retention window; the newest is never deleted. |
| `rollcall report` | Print the fleet report from the stored snapshots. |
| `rollcall scan` | Scan the fleet read-only and store a snapshot. |

### `rollcall advisories` [#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#rollcall-advisories)

Check locked package versions against a saved Packagist security-advisories response.

Usage: `rollcall advisories [--today TODAY] [--feed FEED]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--feed=VALUE` | Path to the saved Packagist security-advisories JSON. |  |
| `--silent` | Do not output any message |  |

### `rollcall digest` [#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#rollcall-digest)

Print (or write, or send) each opted-in owner's digest of what changed in their apps.

Usage: `rollcall digest [--today TODAY] [--owner OWNER] [--format FORMAT] [--out OUT] [--send]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--owner=VALUE` | Only this contact from the owners file. |  |
| `--format=VALUE` | text or json. | `text` |
| `--out=VALUE` | Write one text file per digest into this directory instead of printing them. |  |
| `--send` | Mail the digests. Needs ROLLCALL_DIGEST_MAIL_ENABLED=true, ROLLCALL_DIGEST_MAIL_DSN and ROLLCALL_DIGEST_MAIL_FROM. |  |
| `--silent` | Do not output any message |  |

### `rollcall drift` [#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#rollcall-drift)

Compare policy.json with a saved endoflife.date v1 product file.

Usage: `rollcall drift [--today TODAY] [--feed FEED] [--component COMPONENT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--feed=VALUE` | Path to the saved endoflife.date JSON. |  |
| `--component=VALUE` | Policy component to compare. | `php` |
| `--silent` | Do not output any message |  |

### `rollcall owners` [#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#rollcall-owners)

List the scanned apps whose owner is unknown (no owners-file entry, or none confirmed within ROLLCALL_OWNERS_CONFIRM_DAYS); exit 1 when there is one.

Usage: `rollcall owners [--today TODAY] [--format FORMAT]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--format=VALUE` | text or json. | `text` |
| `--silent` | Do not output any message |  |

### `rollcall prune` [#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#rollcall-prune)

Delete snapshots older than the retention window; the newest is never deleted.

Usage: `rollcall prune [--today TODAY] [--days DAYS] [--dry-run]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--days=VALUE` | Retention window in days (default: ROLLCALL_RETENTION_DAYS, 90; 0 keeps everything). |  |
| `--dry-run` | List what would be deleted without deleting anything. |  |
| `--silent` | Do not output any message |  |

### `rollcall report` [#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#rollcall-report)

Print the fleet report from the stored snapshots.

Usage: `rollcall report [--today TODAY] [--format FORMAT] [--internal]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--format=VALUE` | text, json or prometheus. | `text` |
| `--internal` | Include the per-app list. Internal only: do not publish it. |  |
| `--silent` | Do not output any message |  |

### `rollcall scan` [#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#rollcall-scan)

Scan the fleet read-only and store a snapshot.

Usage: `rollcall scan [--today TODAY] [--prune [PRUNE]]`

| Option | Meaning | Default |
| --- | --- | --- |
| `--today=VALUE` | Override today's date (YYYY-MM-DD). |  |
| `--prune=VALUE` | After saving, delete snapshots older than this many days (default: ROLLCALL_RETENTION_DAYS, 90; 0 keeps everything). |  |
| `--silent` | Do not output any message |  |

### Options every command accepts[#](https://rollcall.apps.wayne.edu/docs/reference/commands.html#options-every-command-accepts)

| Option | Meaning | Default |
| --- | --- | --- |
| `-h`, `--help` | Display help for the given command. When no command is given display help for the list command |  |
| `-q`, `--quiet` | Only errors are displayed. All other output is suppressed |  |
| \`-v | -vv | -vvv`, `--verbose\` |
| `-V`, `--version` | Display this application version |  |
| `--ansi` | Force (or disable --no-ansi) ANSI output |  |
| `--no-ansi` | Negate the "--ansi" option |  |
| `-n`, `--no-interaction` | Do not ask any interactive question |  |

- `scan` scans once and stores `<ROLLCALL_STORE_DIR>/<date>.json`. A second scan on the same day replaces it. A gap in one source (a missing report, a bad image tag) becomes a note on that app, and the scan carries on. A refused GitLab token stops the scan loudly instead of producing a flattering partial answer. `--prune[=DAYS]` deletes snapshots older than that many days after saving (default: `ROLLCALL_RETENTION_DAYS`, 90; 0 keeps everything).
- `report` prints the latest snapshot against the policy as of today. `text` is a terminal table, `json` is the machine-readable form, `prometheus` is the metrics text (refused unless `ROLLCALL_METRICS_ENABLED=true`). `--internal` adds the per-app list; keep that output inside the project.
- `drift` compares `policy.json` with a saved endoflife.date v1 product page (for example `https://endoflife.date/api/v1/products/php/` saved to a file). A difference is a flag for a person to look at; the policy stays the source of truth. Rollcall does not fetch that page itself.
- `advisories` checks each app's locked `laravel/framework` and auth-package versions against a saved Packagist security-advisories response (for example `https://packagist.org/api/security-advisories/?packages[]=laravel/framework` saved to a file). One line per package and version, sorted by package then version: the status (`affected`, `clear` or `no data`), the count and the advisory ids, and how many apps hold that pair. A package with no advisory data prints `no data`, never `safe`: an absent row proves nothing about the package, and a version nobody can parse says `no data` too. Rollcall does not fetch that page itself; a person saves it and `--feed` points at the file. Output is package names and counts only: no app ids.
- `prune` deletes snapshots older than `--days` (default: `ROLLCALL_RETENTION_DAYS`, 90; 0 keeps everything). The newest snapshot is never deleted, even if it is older than the cut-off, so the dashboard always has data. `--dry-run` lists what would go without deleting anything.

Exit codes: `0` ok, `2` tool error (bad option, unreadable policy, a source that refused). Rollcall does not gate the fleet, so no command exits `1` over what the apps run. The one `1` is `rollcall owners`, while an app's owner is unknown: that is the register, not the fleet.
