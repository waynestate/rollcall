# ROLLCALL open questions

What ROLLCALL 0.1 could not settle without a person, and the default it was built with. Roles, not names.

## Questions

| # | Question | Who can answer | What it blocks | Default built |
|---|---|---|---|---|
| 1 | Where are PROCTOR JSON reports stored (CI artifacts, a shared directory, an object store)? | PROCTOR/PREFLIGHT maintainer | Reading real results | A directory of reports, one file per app named `group__project.json`. `ProctorReportSource` is an interface, so an artifact or bucket reader drops in |
| 2 | How do we get the Citadel app list, image tag and any forced-upgrade date: a read-only account or an export? And how does an app in that list map to a GitLab project? | Citadel platform team | Real image data; apps that are not in GitLab | A JSON export `{"apps":[{"project":"group/project","image":"..."}]}` joined on the repository path. ROLLCALL never calls Citadel |
| 3 | Which GitLab tier and version does git.wayne.edu run? | GitLab administrator | Leaked-token response, whether `HEAD` blob ids and `simple=true` behave as documented | Assumes the documented API on any tier; nothing needs Premium or Ultimate. The token-expiry warning uses `personal_access_tokens/self`, which exists on the 19.x series |
| 4 | Who owns and rotates the `read_api` scanner token, and how long does it live? | Platform/security owner | Scanning the real fleet | The token is an env var; a 401/403 stops the scan loudly. `scan` prints a note 14 days before the token expires (and when it has already expired); rotating the token is still the owner's job |
| 5 | What is the lowest project role that can read the two Composer files with `read_api`? | GitLab administrator | Choosing the service account's role | Not tested on the instance; documented as an open check |
| 6 | Which Composer packages count as an "auth package" (the old CAS client, the CAS wrapper, SESAME)? | Auth/SESAME maintainer | Correct auth counts | `waynestate/sesame`, `waynestate/wayne-cas`, `apereo/phpcas`, `jasig/phpcas`, `subfission/cas`, first match wins; `ROLLCALL_AUTH_PACKAGES` overrides. The names other than SESAME are guesses |
| 7 | Who may see the per-app list, and how is that decided? | Project owner / security owner | Turning on the internal page | Off. The Laravel per-app route needs `ROLLCALL_INTERNAL_ENABLED=true` and runs behind `auth.sesame`; no per-app access control beyond that |
| 8 | May the public counts (`/report.json`, the dashboard) be published, and to whom? | Project owner | Serving them | `/report.json` is off by default; the Laravel dashboard is off by default and sends `noindex` |
| 9 | How long are snapshots and per-app detail kept? | Project owner / records owner | Disk growth, privacy | Pruning is built: `ROLLCALL_RETENTION_DAYS` (default 90; 0 keeps forever), `rollcall scan --prune` and `rollcall prune [--dry-run]`. The newest snapshot is never deleted. The default window is still the owner's call |
| 10 | CycloneDX or SPDX for a bill of materials, and is Dependency-Track wanted? | Security owner | The BOM feature | CycloneDX where a BOM is produced (lead decision); none is produced in 0.1 |
| 11 | Should ROLLCALL fetch endoflife.date itself? | Project owner | Automatic drift flags | No. `rollcall drift` reads a saved page, so no unattended outbound call exists |
| 12 | Who confirms an app's owner, and where is that recorded? | Project owner | "Owner unknown" tracking | Not built; the report carries no owner field at all |
| 13 | Does the dashboard need WayneUI's `app-shell` (public or internal template)? | WayneUI maintainer | Matching WayneUI exactly and the a11y gate | The Laravel page is plain HTML with WayneUI class names (`wsu-table`, `wsu-stat`, skip link) and links `ROLLCALL_WAYNEUI_CSS`. Swap to `<x-wsu::app-shell>` once WayneUI installs as a Composer dependency |
| 14 | Who runs a Prometheus server for `/metrics`, and whether the Grafana instance imports the bundled dashboard as is | Grafana owner | Live burn-down | Dashboard JSON and endpoint are built and tested; nothing depends on a server |
| 15 | Does the snapshot-per-day cadence suit the burn-down, and who schedules `rollcall scan`? | Project owner | Burn-down resolution | One snapshot per day; scheduling is the deployer's (cron, a Laravel schedule or a CI schedule) |

## Missing configuration

| Item | Where it comes from | Placeholder used |
|---|---|---|
| `ROLLCALL_GITLAB_URL` | The instance base URL, `https://` only | `https://gitlab.example.test` in `.env.example` |
| `ROLLCALL_GITLAB_TOKEN` | A `read_api` token on a group or service account, from the GitLab administrator | `replace-with-a-read-api-token` |
| `ROLLCALL_GITLAB_GROUPS` | The groups that hold the fleet, from the platform owner | `group-or-path` |
| `ROLLCALL_POLICY_FILE` | The released CREED `policy.json` | `./policy.json`; a snapshot copy sits in `tests/fixtures/` for tests only |
| `ROLLCALL_IMAGES_FILE` | The Citadel team's app-list export | `tests/fixtures/images.json` (demo data) |
| `ROLLCALL_PROCTOR_DIR` | Wherever PROCTOR reports land | `tests/fixtures/proctor` (demo data) |
| `ROLLCALL_METRICS_TOKEN` or `ROLLCALL_METRICS_ALLOW` | The Prometheus operator: a token for the scrape job, or its addresses | empty (metrics stay off) |
| `ROLLCALL_STORE_DIR` | A writable, persistent directory on the host. Needed on OpenShift, Coolify or Docker as a mounted volume | `var/snapshots` |
| `ROLLCALL_WAYNEUI_CSS` | WayneUI's built stylesheet URL | empty |
| Deploy host and hostname `rollcall.apps.wayne.edu` | Citadel/platform team | none; no deploy is built |
| A git.wayne.edu instance runner | Platform owner | none; CI runs on the GitLab runners and GitHub |

## Not built yet

| Item | Why it is left for 0.2+ |
|---|---|
| Citadel read-only adapter and the forced-upgrade date | No access path yet (question 2); `ImageSource` is ready for it |
| PROCTOR reports from CI artifacts | Storage not decided (question 1); the reader and interface exist |
| Host census importer for apps not in GitLab | Needs the Citadel export format (question 2) |
| Packagist advisories lookup with "no data" for absent packages | Needs a live-host decision; no call is made in 0.1 |
| endoflife.date client | Only the saved-file drift check is built (question 11) |
| Override storage owned by ROLLCALL | 0.1 shows the overrides PROCTOR reports, with reason and expiry; ROLLCALL keeps none of its own |
| Ownership verification on a schedule | Question 12 |
| CycloneDX bill of materials and Dependency-Track | Question 10; an ADR is needed |
| ADVISOR/MCP tool module | Waits on the stack MCP server |
| Marketing front page, brand site, quick-start and versions table generated from `policy.json`, a11y gate and `--check` for stale pages | The front page is built separately; the dashboard page here has not been run through WayneUI's a11y gate |
| Raw-PHP and Vue dashboards | The dashboard is Laravel only; a framework-free page would call the same report builder |
| Snapshot pruning, retention | Question 9 |
| Secret-scan hooks | Question 3 |
| Tags `0.1.0` and `1.0.0`, the git.wayne.edu mirror | Release steps for the lead and the user |
| A live trial against git.wayne.edu | Forbidden for the build; the GitLab adapter has only been run against recorded responses. Confirm `default_branch` is returned by `simple=true`, that `HEAD` returns `X-Gitlab-Blob-Id`, and the rate limit |

## Choices worth a second look

- **State boundaries.** `active_until` and `security_until` are inclusive: the app is still in that state on the date.
- **Versions the policy does not list.** Older than the oldest listed version means EOL; newer means `unknown`.
- **Floor.** The "declared PHP floor" is the `major.minor` of the constraint's lower bound; `*` and `>=0` give no floor and
  are counted as `floor_unbounded`.
- **Image tag parsing.** A tag must contain a dotted PHP version (`8.4`) or `php84`; anything else is `unknown`, never a guess.
- **Test dependency.** The Laravel adapter's tests pull Testbench (Laravel 12 or 13); the core installs without Laravel.
- **`/metrics` path.** The Laravel adapter registers `/metrics`; an app that already serves `/metrics` (HELMET) should run ROLLCALL separately.
