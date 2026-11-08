---
title: "Citadel's registrations export | Rollcall docs"
description: "Read base images and forced upgrade dates from a saved Citadel export, in Citadel's own field names. Rollcall never calls Citadel."
url: https://rollcall.apps.wayne.edu/docs/guides/citadel.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Citadel's registrations export[#](https://rollcall.apps.wayne.edu/docs/guides/citadel.html#citadel-s-registrations-export)

This page shows how Rollcall reads the runtime image of each app, and any forced upgrade date, from a file Citadel produces. Next: [run the collector in Docker](https://rollcall.apps.wayne.edu/docs/guides/collector-docker.html).

Citadel is one source among others. On OpenShift, Coolify or plain Docker, keep using the image list (`ROLLCALL_IMAGES_FILE`), which any platform can produce. Nothing in Rollcall needs Citadel.

## 1. Rollcall never calls Citadel[#](https://rollcall.apps.wayne.edu/docs/guides/citadel.html#1-rollcall-never-calls-citadel)

Rollcall reads a saved file. It holds no Citadel account, opens no connection to Citadel, and sends nothing back. A person or a scheduled job saves the export, and `rollcall scan` reads it, the same way it reads PROCTOR's reports and the endoflife.date page. If Citadel offers a read-only endpoint that answers with this JSON, save its answer to the file: the shape is the same.

## 2. The shape[#](https://rollcall.apps.wayne.edu/docs/guides/citadel.html#2-the-shape)

The export uses Citadel's own field names, so the people who keep Citadel can produce it without a mapping table. The schema is `contract/citadel-export.schema.json`; extra fields are ignored, so an export may carry more.

```json
{
    "applications": [
        {"repository": "group/project", "base_image": "citadel/php-apache:8.4r5"},
        {"repository": "group/old-project", "base_image": "citadel/php-apache:8.1r2"}
    ],
    "base_image_upgrades": [
        {"old_image": "citadel/php-apache:8.1r2", "new_image": "citadel/php-apache:8.4r5", "due_date": "2026-12-15", "completed": false}
    ]
}
```

| Field | What it is in Citadel | What Rollcall does with it |
| --- | --- | --- |
| `applications[].repository` | The GitLab `group/project` an application deploys from | Joins the app to the repository the scan read |
| `applications[].base_image` | The base image of the application's active deployment | Reads the PHP version (`8.4r5` is PHP 8.4) |
| `base_image_upgrades[].old_image` | The image being retired | Every app on that image gets the due date |
| `base_image_upgrades[].due_date` | The day Citadel forces the upgrade, `YYYY-MM-DD` | The earliest pending date per image counts |
| `base_image_upgrades[].completed` | Whether that upgrade is done | Only `false` counts; a done upgrade is ignored |

Two fields are optional: `applications[].php_version` (the PHP version in the running container, such as `8.3.14`) and `applications[].httpd_version` (the Apache httpd version, such as `2.4.62`). We have asked the Citadel team to send them. When they arrive, Rollcall keeps them in the snapshot and shows them per app in the internal report as "PHP (running)" and "httpd (running)", blank until Citadel sends them; an export without them changes nothing.

Entries Rollcall cannot trust are skipped, never guessed: an application with no repository, an upgrade with no real date (`"soon"`, `2026-02-30`) or without `"completed": false`. An app registered on Citadel but not in the scan is not invented either: the census starts from the repositories.

## 3. Point Rollcall at it[#](https://rollcall.apps.wayne.edu/docs/guides/citadel.html#3-point-rollcall-at-it)

macOS and Linux

```sh
export ROLLCALL_CITADEL_EXPORT=/path/to/citadel-export.json
php bin/rollcall scan
php bin/rollcall report
```

Windows

```powershell
$env:ROLLCALL_CITADEL_EXPORT = "C:\path\to\citadel-export.json"
php bin/rollcall scan
php bin/rollcall report
```

Set `ROLLCALL_CITADEL_EXPORT` or `ROLLCALL_IMAGES_FILE`, not both: each is a complete image source, and the scan stops with a plain message if both are set. In Laravel, the setting is `rollcall.citadel_export`.

## 4. What the report gains[#](https://rollcall.apps.wayne.edu/docs/guides/citadel.html#4-what-the-report-gains)

- **Forced image upgrades**: the text report prints `Forced image upgrades pending: 2 (1 due today or earlier), next due 2026-12-15.` and the JSON report carries `forced_upgrades` with `pending`, `overdue` (due today or earlier) and `next_due` (the next future date, or `null`). Counts only, so it is public. With the image list, or no image source, all three are zero.
- **Metrics and ADVISOR**: the same counts reach `/metrics` as `rollcall_forced_upgrades_pending_apps`, `rollcall_forced_upgrades_overdue_apps` and `rollcall_forced_upgrades_next_due_timestamp_seconds` (see [metrics](https://rollcall.apps.wayne.edu/docs/guides/metrics.html)), and ADVISOR's `rollcall_fleet_summary` tool answers with `forced_upgrades` (see [ADVISOR](https://rollcall.apps.wayne.edu/docs/guides/advisor.html)).
- **Per app, internally**: the internal report's per-app list carries `upgrade_due`, and the stored snapshot keeps it. Snapshots written before it existed load as `null`.
- **Owner digests**: a new forced upgrade date is news, and so is one that went away. See [owner digests](https://rollcall.apps.wayne.edu/docs/guides/digest.html).

The export names applications, so keep it where you keep the snapshots: on the host, outside git.
