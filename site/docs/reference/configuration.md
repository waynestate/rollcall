---
title: "Configuration | Rollcall docs"
description: "Every Rollcall setting is an environment variable; the table is generated and the GitLab setup follows it."
url: https://rollcall.apps.wayne.edu/docs/reference/configuration.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Configuration[#](https://rollcall.apps.wayne.edu/docs/reference/configuration.html#configuration)

This page covers how Rollcall is configured, all through environment variables. Next: [run the collector in Docker](https://rollcall.apps.wayne.edu/docs/guides/collector-docker.html) with them.

Every setting is an environment variable. `.env.example` holds placeholders; never commit a real token.

| Variable | Meaning | Default | Read in |
| --- | --- | --- | --- |
| ROLLCALL_AUTH_PACKAGES | Comma-separated Composer packages that count as an auth package | the built-in list | config/rollcall.php |
| ROLLCALL_CITADEL_EXPORT | Citadel's saved registrations export, used instead of ROLLCALL_IMAGES_FILE (not both): base images plus forced upgrade dates. See the Citadel guide | none | config/rollcall.php |
| ROLLCALL_DASHBOARD_ENABLED | Laravel and raw PHP: serve the dashboard page | `false` | config/rollcall.php |
| ROLLCALL_DASHBOARD_PATH | Laravel: dashboard URL path | `rollcall` | config/rollcall.php |
| ROLLCALL_DIGEST_MAIL_DSN | Symfony Mailer DSN for sending, such as smtp://mail.example.test:25. A secret: never in git, never printed | none | src/Config.php |
| ROLLCALL_DIGEST_MAIL_ENABLED | Allow `rollcall digest --send` to mail | `false` | src/Config.php |
| ROLLCALL_DIGEST_MAIL_FROM | The from address of the digests | none | src/Config.php |
| ROLLCALL_GITLAB_GROUPS | Comma-separated group ids or paths; subgroups are included. Ignored when ROLLCALL_GITLAB_PROJECTS is set | none | config/rollcall.php |
| ROLLCALL_GITLAB_PROJECTS | Comma-separated full group/project paths; when set, only those projects are scanned and no group is listed | none | config/rollcall.php |
| ROLLCALL_GITLAB_TOKEN | A GitLab group or project access token (a bot), or a service account's token, with the read_api scope only, the Reporter role and an expiry. The stack's is the group token rollcall-scanner, rotated by the stack maintainer (docs/security.md). Never in git, never logged, never printed | none | config/rollcall.php |
| ROLLCALL_GITLAB_URL | https:// base URL of the GitLab instance | none | config/rollcall.php |
| ROLLCALL_IMAGES_FILE | Export of the app list: {"apps": \[{"project": "group/project", "image": "php:8.4-apache"}\]} | none | config/rollcall.php |
| ROLLCALL_INTERNAL_ENABLED | Laravel and raw PHP: serve the per-app page (Laravel at `<path>/apps` behind your sign-in middleware; raw PHP only where you call rollcall_dashboard(\['internal' => true\]) behind your sign-in) | `false` | config/rollcall.php |
| ROLLCALL_METRICS_ALLOW | Comma-separated scraper IP addresses (exact match) | none | config/rollcall.php |
| ROLLCALL_METRICS_ENABLED | Turn metrics on (CLI report --format=prometheus and /metrics) | `false` | config/rollcall.php |
| ROLLCALL_METRICS_TOKEN | Bearer token a scraper must present | none | config/rollcall.php |
| ROLLCALL_OWNERS_CONFIRM_DAYS | How long an owners-file entry's "confirmed" date counts; past it, `rollcall owners` calls that owner unknown and exits 1 | `365` (0 turns the check off) | src/Config.php |
| ROLLCALL_OWNERS_FILE | Owners file for the digests: who answers for which apps, and who opted in with "digest": true. Rollcall's own, for the stack's projects, is owners.json in the repo (roles only); keep another fleet's outside a public repo | none (no digests) | src/Config.php |
| ROLLCALL_POLICY_FILE | Path to CREED's policy.json | none (required for report, drift) | config/rollcall.php |
| ROLLCALL_PROCTOR_DIR | PROCTOR JSON reports, one per app: group/project is read from group\_\_project.json | none | config/rollcall.php |
| ROLLCALL_PUBLIC_REPORT_ENABLED | Serve GET /report.json (public counts only) | `false` | config/rollcall.php |
| ROLLCALL_REPOS_DIR | Fixture repositories as `<group>/<project>/composer.{json,lock}` | none | config/rollcall.php |
| ROLLCALL_RETENTION_DAYS | How many days of snapshots to keep; the newest is never deleted | `90` (0 keeps everything) | config/rollcall.php |
| ROLLCALL_SOURCE | directory (fixtures) or gitlab | `directory` | config/rollcall.php |
| ROLLCALL_STORE_DIR | Snapshot directory, the only place Rollcall writes | `var/snapshots` | config/rollcall.php |
| ROLLCALL_WAYNEUI_CSS | Laravel and raw PHP: https:// or absolute URL of WayneUI's stylesheet for the dashboard | none | config/rollcall.php |

### Auth packages[#](https://rollcall.apps.wayne.edu/docs/reference/configuration.html#auth-packages)

`ROLLCALL_AUTH_PACKAGES` is checked in list order, and the first entry that matches an app wins. An entry with a slash matches that package exactly; an entry without a slash is a bare package name and matches that package under any vendor, so `wayne-cas` matches `anyvendor/wayne-cas`. The report carries the real locked package name, vendor included. The built-in list is `waynestate/sesame`, `waynestate/wayne-cas`, `apereo/phpcas`, `jasig/phpcas`, `subfission/cas`, `wayne-cas` and `simplecas`; the exact `waynestate/wayne-cas` entry comes first, so it still wins over the bare `wayne-cas` entry.

To scan GitLab you need the token and either a group or the project allowlist. The narrow way is `ROLLCALL_GITLAB_PROJECTS`: name the exact `group/project` paths and Rollcall reads only those, never listing a group. Otherwise set `ROLLCALL_GITLAB_GROUPS`. The token is a GitLab bot or personal access token with `read_api` only and an expiry, whose user can read only the projects to scan; until it exists Rollcall runs on its fixtures. Set `ROLLCALL_SOURCE=gitlab` and the GitLab variables. The `rollcall-scan` job in `.gitlab-ci.yml` does this on a schedule or by hand. Rollcall sends only `GET` and `HEAD`, fetches the two Composer files without cloning, skips a file whose blob id has not changed, follows `Link` pagination only to the configured host, and waits out `429 Retry-After`.
