# Changelog

All notable changes to Rollcall. The format follows Keep a Changelog; versions follow SemVer.

## [Unreleased]

- Changed: the deploy layer requires PROCTOR ^0.12 (locked 0.12.0, was ^0.11): two new warning-only checks, nothing breaks.
- Site: the front page's stale facts are current (the class list calls Drawbridge the sign-in gateway for Citadel, not the planned one; 0.6.1 is released).
- Changed: the deploy kit is Squire 0.10.7 (was 0.10.6): it ships a `.gitattributes` that pins line endings, so the deploy layer clears PROCTOR's line-ending warnings.
- Changed: the PROCTOR job on the deploy layer blocks; it was allowed to fail, but PROCTOR passes deploy/ (its notes are warnings, which never fail the job).
- Fixed: the public-surface test allows Citadel pre-production hosts (*.appstest.wayne.edu), which the site README names; composer ci failed on them.
- Changed: the docs, agent-ready and deploy kits are Squire 0.10.6 (was 0.10.5): the docs builder and agent-ready generator see every file on Docker Desktop mounts.
- Site: `assets/stack-env.js` knows Parity, so a pre-production page links Parity's pre-production site and a dev page links its local `site/` folder. Until now Parity, of all parts, was the one link that ignored its environment.
- Site: Parity is out; the party list links its site.
- Deploy kit refreshed to Squire 0.10.5; requires waynestate/advisor ^0.3.

## [1.1.1] - 2026-11-09

- Changed: `ReportBuilder::VERSION`, the fallback a dev checkout prints, is 1.1.1; the README, Install and the front page say 1.1.1.
- Changed: CI pins the Preflight components to 0.5.0 (was 0.4.0), and the composer-audit job now takes the component's own image instead of naming one; the a11y pin test expects 0.5.0 (the a11y template is the same in both).
- Docs: Parity joins the party. The README cast and "Where it sits in the stack" table, the docs overview's table and cast, and the front page's class list carry Parity next to Squire, with its spirit-level mark: the stack's new tool that runs Citadel on your computer (its PHP image, its proxy and MySQL 5.6). 0.1.0 is coming and it has no site yet, so its entries link nowhere. Present, and level.
- Changed: refreshed version mentions. The "Where it sits in the stack" table in the README and the docs overview calls the whole class now, in the cast's order (Squire, Booster, Drawbridge, ADVISOR and WELD were absent without a note); MAINTAINING names Preflight 0.5.0, the pin CI uses; the front page notes say the printout is Rollcall 1.1.0's, as the page shows. The dev dependencies move to ADVISOR ^0.2 and Pinstripe ^0.3 (Pinstripe 0.3.0 bans joined SQL and turned-off TLS checks); the module test hands ADVISOR 0.2.0's `ModuleContext` its new sixth argument, the Playbook `Settings`. The module itself is unchanged.
- Changed: CI and the documented Docker lines run Composer 2.11.1, pinned by digest (was 2.10.3, no longer maintained; 2.11.1 fixes GHSA-c968-33g3-wh7p and GHSA-fm2w-xgf2-f9fh): the `wayneui-copies`, `composer-ci`, `dashboard-render`, `rollcall-scan` and `creed-copy-refresh` jobs, and `scripts/dashboard-a11y.sh` and `.ps1`. The `composer-audit` component's image input is left alone; Preflight moves its own pin.
- Changed: the deploy kit is Squire 0.10.2 (was 0.10.1): the fallback and portable images name every base image in full (`docker.io/library/...`, so Buildah and Podman accept them) with Composer as its own build argument, the session cookie is `SameSite=Lax`, and the portable image drops `node_modules/` after the build. The front page is static, so nothing it serves changes.
- Changed: the front page deploy layer requires HELMET ^1.3 (locked 1.3.0, was ^1.0 at 1.0.1) and CREED ^0.1.8 (locked 0.1.8, was ^0.1 at 0.1.7); PROCTOR stays ^0.11 at 0.11.0. The dev dependency Pinstripe is ^0.1.4 (locked 0.1.4, was ^0.1 at 0.1.0).
- Docs: the exit codes say all three. The README badge read "0 ok, 2 error" and now reads "0 ok, 1 owner unknown, 2 error"; `scripts/readme-art` builds it from the README's sentence, which (with Commands) now lists `1` for `rollcall owners` and says an unknown command or option is the console library's usage error, exit 1, before Rollcall runs. `2` is a bad option value, an unreadable policy or a refused source.

## [1.1.0] - 2026-11-09

- Added: PROCTOR reports straight from CI. `ROLLCALL_PROCTOR_SOURCE=gitlab` (the default stays `directory`) reads each project's report with the scan's own GitLab adapter and `read_api` token: the newest finished pipelines on the default branch, the newest one that ran the `proctor` job (`ROLLCALL_PROCTOR_JOB`), passed or failed, and the `proctor.json` file (`ROLLCALL_PROCTOR_ARTIFACT`) from that job's artifact by job id (`GitLabArtifactProctorReportSource`, `GitLabRepositorySource::jobArtifact()`). GitLab's "latest successful pipeline" artifact endpoint is not used, because a failing PROCTOR job fails its pipeline and that endpoint would show the last passing report. No pipeline, no proctor job in the newest five, a 404 for the file (never kept or expired), a file over 1 MiB and CI turned off for a project (403 on its pipeline list) all mean no report for that app, never a failed scan; a body that is not a report becomes the usual "PROCTOR report could not be read" note; other GitLab errors stop the scan as every other call does. The three new calls are in `docs/security.md`'s token table (`TokenScopeDocsTest` holds it). It shows real results only for apps whose CI keeps PROCTOR's JSON report as `proctor.json` under `artifacts: paths`: Preflight 0.5.0 or later, or a proctor job of the app's own (OPEN_QUESTIONS question 1).
- Added: the dashboard as one of an Inertia app's own Vue pages, inside its own layout (WELD's shape). `resources/js/RollcallDashboard.vue` ships with the Composer package, and `php artisan vendor:publish --tag=rollcall-inertia` writes a short page, `resources/js/pages/Rollcall/Dashboard.vue`, that imports it from `vendor/` and wraps it in the app's `AppLayout`, so `composer update` keeps the markup in step and no npm package is involved. With `ROLLCALL_DASHBOARD_INERTIA=true` the same routes and switches (`ROLLCALL_DASHBOARD_ENABLED`, `ROLLCALL_INTERNAL_ENABLED`, the internal middleware) answer with `Inertia::render('Rollcall/Dashboard')` and the report `DashboardPage::report()` builds as props, plus `X-Robots-Tag: noindex`; off (the default), the Blade page is unchanged, and `inertiajs/inertia-laravel` is needed only when it is on (a dev dependency here). The component has no style, no style attribute and no script of its own, so it works under HELMET's CSP. New CI job `dashboard-vue` (`tools/vue-dashboard/parity.mjs`, Vue and parse5 pinned exactly, in the a11y component's image) server-renders the component with the props the route sends (`DashboardRenderTest` saves them as `inertia*.json`) and fails on any element, attribute or text that differs from the Blade page, normalizing only whitespace, attribute order and Vue's hydration comments; `dashboard-a11y` now checks nine pages, the three Vue ones included, in light and dark at 320 and 1280 px, and `scripts/dashboard-a11y.sh` and `.ps1` run the same steps. `DashboardInertiaTest` and `DashboardVueGateTest` hold the props, the switches and the wiring.
- Changed: the deploy kit is Squire 0.10.0 (was 0.7.1): release.sh keeps Composer and npm download caches in the app folder and the gate takes SQUIRE_PHP_RUNTIME; the compose file, health.php and .env.squire.example follow the template, and the portable image files are added.
- Changed: the deploy kit is Squire 0.10.1 (was 0.10.0): the fallback image copies Composer 2.11.1 (was 2.10.3, no longer maintained). The front page deploy layer requires PROCTOR ^0.11 (locked 0.11.0, was ^0.8 at 0.8.0).
- Added: the dashboard for a raw PHP app. `rollcall_dashboard()` is one call in a file under the app's served folder (after `helmet_boot()`, passing its nonce): the same page the Laravel adapter serves, from the same report, because both now print one renderer (`src/Dashboard/DashboardPage.php`); the Laravel view is a one-line call to it. It answers 404 until `ROLLCALL_DASHBOARD_ENABLED` is true, sends `X-Robots-Tag: noindex`, has no script, and shows the per-app page only with `ROLLCALL_INTERNAL_ENABLED` and `'internal' => true` from a file behind the app's sign-in. `RawDashboard::response()` returns the same answer as data. Its empty state names `vendor/bin/rollcall scan` where the Laravel page names `php artisan rollcall:scan`; otherwise `DashboardParityTest` holds the two pages byte for byte the same. `DashboardRenderTest` writes the raw-PHP pages too, and `dashboard-a11y` (CI and the local scripts) checks all six pages in light and dark at 320 and 1280 px. `Config::truthy()` is public, so every entry point reads an on/off setting one way.
- Fixed: `scripts/a11y.ps1` and `scripts/dashboard-a11y.ps1` stopped at "expecting fi" on a Windows checkout: the file is checked out with CRLF, so the inner `sh` script carried a carriage return on each line. They strip it now.
- Added: the front page quick-start printout's policy values are generated, not typed. `bin/quickstart-versions.php` writes the PHP image table and the EOL burn-down of the sample report from CREED's `policy.json` (judged by `src/Creed/Policy.php` for the bundled fixtures) between `quickstart-versions` markers in `site/index.html`: the start marker sits on the first line inside the pre and the end marker just after `</pre>`, so the page and its Markdown twin render exactly as before. The rest of the printout stays hand-written: the ROLLCALL version line is the release process's, and the lede's PHP floor is Rollcall's own requirement, not the policy's. `composer site:versions` regenerates the region, and `composer site:versions-check` (part of `composer ci`) fails when it drifts; the generation date is stored in the start marker, so a passing day does not turn CI red.
- Fixed: a checkout of 1.0.0 printed `ROLLCALL 0.1.10` at the top of the report (the quick start runs from a checkout). The fallback version in `ReportBuilder::VERSION` is 1.0.0; a Packagist install already printed its installed version. `VersionTest` missed it because the 1.0.0 entry sat below 0.1.10 in this file.
- Docs: Rollcall reads as a 1.0 product. The 1.0.0 entry moves above 0.1.10 in this file, so the docs builder stamps 1.0.0 on the front page and the docs (they said 0.1.10); the front page gains a PROMOTED stamp under the install line that links the contract's SemVer promise, and its quick-start printout says Rollcall 1.0; Install (README and docs) says what 1.x promises; OPEN_QUESTIONS closes the 1.0.0 tag row and drops its "0.2+" and "0.1" wording.
- Docs: a truth pass against the code. `rollcall owners` exits 1 while an owner is unknown, so the pages no longer say Rollcall never exits 1 (README, Commands, MAINTAINING, the front page); the CLI lists name `owners`; the scan reads `booster-hop.json` too (README, How it is built, Configuration, Security's call table); `read_api` covers every call, not "five"; `composer ci`'s list of checks is complete; Security says which 403 does not stop the scan; the Laravel guide gets back its "The dashboard in a raw PHP app" heading; Owner digests lists a forced upgrade date as news; OPEN_QUESTIONS records what the first scheduled scan on git.wayne.edu answered; the CI PROCTOR reader is documented as needing Preflight 0.5.0 or later; and the front page's PROCTOR card says a report comes from a folder or CI.

## [1.0.0] - 2026-11-09

- Added: ownership is verified on a schedule (ROLLCALL-17). An owners-file entry may carry `"confirmed": "YYYY-MM-DD"`, the day its contact last said it still answers for those apps, and `rollcall owners` now calls an app's owner unknown when no entry lists it or none that lists it was confirmed in the last `ROLLCALL_OWNERS_CONFIRM_DAYS` days (default 365; 0 turns the check off; a date after today is not trusted). It still exits 1 while any owner is unknown, so the weekly scan job goes red until someone re-confirms; `--format=json` gains `confirm_days`, `unconfirmed` and `owner_unknown`. An existing owners file without dates fails that job until each entry is dated. Rollcall's own `owners.json` is confirmed as of 2026-11-08. Digests and reports are unchanged.
- Added: `contract/owners-check.schema.json` for `rollcall owners --format=json`, and the contract suite now gives every schema teeth (a snapshot of an unknown version, an image entry without its image, an owner without a contact, a digest without `no_owner_on_file` and more are refused). `ContractTest` fails if a schema in `contract/` is not validated against real output or has no case it must refuse (ROLLCALL-25).
- Changed: CI pins the Preflight components to 0.4.0 (the proctor job clones the full history, so the override-approver check can read git blame).
- Added: the dashboard accessibility gate also checks the empty state, and renders every page against the bundled WayneUI stylesheet so axe checks the real colors in light and dark.
- Changed: the deploy layer runs Squire's kit 0.7.1 (was 0.6.0): `.squire/php.ini` no longer sets the deprecated `session.sid_*` lines, web requests get a `disable_functions` list and Apache hardening (`.squire/php-web.ini`, `.squire/apache-hardening.conf`), and opcache gets production settings with `SQUIRE_OPCACHE_VALIDATE` as the one switch.
- Changed: the front page deploy layer requires HELMET ^1.0 (lock at 1.0.1, was 0.4.0), like the rest of the stack; its shared front controller already runs on HELMET 1.0 in Squire, Sourdough and Pinstripe.
- Changed: the local a11y scripts run Preflight's own checker (0.3.1, axe-core 4.14.0, 320 and 1280 px, reflow), pinned by SHA-256 in tests/A11yCheckerPinTest.php, so a page that passes locally passes CI.
- Fixed: the dashboard's tables scroll inside their own region at 320 px even when no WayneUI stylesheet is linked, so the page reflows (WCAG 1.4.10; caught by the dashboard-a11y CI job).
- Changed: CI pins the Preflight components to 0.3.1 (fixed cache keys, and caches saved even when a job fails; D-072).
- Changed: CI saves its Composer and npm caches even when a job fails, so a retry after a dropped download fetches only what is missing (D-072).
- Added: CI runs axe-core (WCAG 2.2 AA) on the front page and docs (a11y-site) and on the rendered dashboard pages (dashboard-render, dashboard-a11y), with Preflight's a11y component.
- Changed: CI caches Composer and npm downloads under a fixed key, so a lock change downloads only what changed (D-072).
- Added: scripts/dashboard-a11y.sh and .ps1 run axe-core against the rendered public and internal dashboard pages.
- Added: DashboardRenderTest writes the two rendered dashboard pages to ROLLCALL_DASHBOARD_OUT, for the dashboard accessibility gate.
- Added: `rollcall owners` lists the scanned apps with no owner on file (exit 1 when there is one), and the weekly scan job runs it (ROLLCALL-17).
- Fixed: the docs menu closes with Escape and hands focus back to its button (docs kit synced from Squire).
- Fixed: the docs kit's copy button no longer covers the first line of code when it says Copied or Failed: over the code it keeps its resting width and shows a check or a cross when the word does not fit (docs kit synced from Squire).
- Fixed: the front page's version chip shows the full version the docs show (Register 0.1.10, was Register 0.1). It carries `data-docs-version` now, so the docs builder (synced from Squire) rewrites it at every release and `composer docs:check` fails while it is stale.
- Changed: the deploy layer requires PROCTOR ^0.8 (locked 0.8.0).
- Fixed: on a phone the front page's versions table ran past the page edge, and the page clips sideways, so the end-of-support dates could not be seen. Below 560 px the table is set a size smaller, keeps each date on one line and lets the long state name wrap at its underscore.
- Added: a weekly `creed-copy-refresh` CI job (`tools/creed-copy-refresh.sh`; schedule variable `ROLLCALL_JOB=creed-copy`, or by hand from the web on main) re-exports the generated CREED copy in `src/Creed` from CREED main, rebuilds the versions table, docs and agent-ready files, runs `composer creed-copy:check` and `composer ci`, and pushes to main with the job token only when both pass; a red run leaves main untouched.
- Added: Rollcall keeps its own owners file, `owners.json` in the repo, because Rolodex is paused (the project owner's 1.0 decision). It maps the 13 stack projects the scheduled scan reads to their owner, the stack maintainer, and names roles and teams only, never a person, because the repo is public. Same format as any owners file (`contract/owners.schema.json`, which now accepts an optional `"$schema"` line for editors); point `ROLLCALL_OWNERS_FILE` at it, or at another fleet's file kept outside a public repo. A new test fails if a project the scan job names has no owner there, or if a contact looks like a mail address.
- Added: a scanned app that no entry of the owners file lists is no longer quietly left out of every digest. `rollcall digest` names each one on stderr as "no owner on file" on every run (text, JSON and `--out`), and `--format=json` lists them under `no_owner_on_file` (a new required field in `contract/digest.schema.json`). An owner who has not opted in still counts as the app's owner. Reports still never read the owners file.
- Docs: the read token is rotated by a role, the stack maintainer, not a named person (the project owner's 1.0 decision). The README, Security page, MAINTAINING, OPEN_QUESTIONS (question 4), BEST_PRACTICES, DECISIONS, the Configuration reference and the front page's agent prompts now say so, and describe the stack's token as it is: the group access token `rollcall-scanner` on `enterprise-apps` (Reporter, `read_api` only, group reach by the project owner's choice), in the masked, hidden, protected CI/CD variable `ROLLCALL_GITLAB_TOKEN`, expiring 2027-11-06. They no longer say the GitLab administrator issues or rotates it, or that the stack's token is per project. A new section of the Security page, "The scanner token and how to rotate it", is the runbook: where it lives, when it expires, who rotates it, five steps (make the replacement, update the variable, run the scan by hand and read the log, revoke the old one, record the new date) and what a leaked token changes. MAINTAINING gains a short version and an "Owners file" section.
- Added: the forced image upgrades reach metrics and ADVISOR (the project owner's 1.0 decision). `/metrics` and `report --format=prometheus` gain three gauges from the report's `forced_upgrades`, counts only: `rollcall_forced_upgrades_pending_apps`, `rollcall_forced_upgrades_overdue_apps` and, while a date is still ahead, `rollcall_forced_upgrades_next_due_timestamp_seconds`; the two counts are 0 with any image source other than Citadel's export. The Grafana dashboard gains two stat panels, pending and overdue. ADVISOR's `rollcall_fleet_summary` tool now answers with `forced_upgrades` (`pending`, `overdue`, `next_due`) too. ADVISOR itself needs no change: the tool lives in Rollcall's module.
- Changed: the pipeline pins every Preflight CI component to 0.3.0 (preflight@0.3.0); component image inputs are plain image names, since Preflight adds the Dependency Proxy prefix itself.
- Added: each app's `booster-hop.json` (Booster's hop record) is read from its default branch; the snapshot keeps `booster_result`, `booster_version` and `booster_at` (BOOSTER-24).
- Added: the Citadel export may carry php_version and httpd_version per app; Rollcall keeps them and shows "PHP (running)" and "httpd (running)" columns, blank until Citadel sends them.
- Changed: the generated CREED copy in `src/Creed` is refreshed from CREED 0.1.7-21 (was 0.1.2): newer policy versions (Laravel 12.71.0 and 13.40.0 from the first policy-bot refresh), the `sessions` block, and the reader gains `requires()`, `phpRange()` and `abandonedPackages()`; `composer creed-copy:check` passes again.
- Fixed: the pipeline's PROCTOR jobs no longer pass `--policy`, which PROCTOR 0.7 refuses in CI; PROCTOR checks against the CREED policy it bundles.
- Changed: the GitLab pipeline pins the Preflight components to 0.2.0 (was 0.1.4).
- Changed: the front page's deploy layer (`deploy/`) runs Squire's kit 0.6.0 (was 0.5.0): `scripts/release.sh` asks PROCTOR's deploy gate for a go or no-go on each release (a report, not a stop, unless `SQUIRE_PROCTOR=enforce`), and `.env.squire.example` lists the new setting. The layer's pins move to HELMET `^0.4` (locked 0.4.0) and PROCTOR `^0.7` (locked 0.7.0).
- Changed: CI caches Composer downloads per lock file (`.composer-cache`, keyed on `composer.lock` and `deploy/composer.lock`), so a pipeline stops fetching every package from Packagist on every run. Images already come through the Dependency Proxy.
- Docs: the decisions are written down. `ADR/` holds eight ADRs (saved files over live calls, public means counts and owners stay internal, PROCTOR owns overrides, the PNG is the mark, the ADVISOR module instead of `rollcall mcp`, opt-in digests that print first, one image source per scan, the contract and SemVer from 1.0), and `DECISIONS.md` indexes them with the smaller decisions and where each is recorded. `BEST_PRACTICES.md` gains the contract, digest, image-source and script-pair rules, and the token scope rule is now Checked by `TokenScopeDocsTest`. How it is built, Install and the front page say the CLI also has `digest` and that digest files are the one other thing Rollcall writes, only when asked. The public-surface test now reads the ADRs and `DECISIONS.md` too.
- Added: a Citadel source. `ROLLCALL_CITADEL_EXPORT` points at a saved Citadel registrations export, in Citadel's own field names (`applications[].repository` and `base_image`, `base_image_upgrades[].old_image`, `due_date` and `completed`), and is used instead of the image list: set one or the other, not both. Rollcall never calls Citadel; a person or a job saves the file, and a read-only endpoint answering with the same JSON fits as is. Besides the PHP version of each base image, it brings Citadel's forced upgrade dates: the report gains `forced_upgrades` (`pending`, `overdue`, `next_due`; counts only, zero with any other image source), the internal per-app list and the stored snapshot gain `upgrade_due` (older snapshots load as `null`, `schema_version` stays 1), the text report prints a line when one is pending, and an owner digest calls a new or cleared date news. Entries it cannot trust (no repository, no real date) are skipped, never guessed. The image list stays the portable source for OpenShift, Coolify and Docker. Contract: `citadel-export.schema.json`; tests use fixtures only. Docs: Guides > Citadel's registrations export.
- Added: opt-in owner digests, `rollcall digest`. An owners file (`ROLLCALL_OWNERS_FILE`, internal, never in git) says who answers for which apps; an owner opts in with `"digest": true`, and leaving it out means no. Each opted-in owner gets plain words about only their own apps: what changed since the previous scan (image, floor, Laravel, PROCTOR, drift flags appearing or clearing), then where each app stands, built from the internal report so it never disagrees with it. No change, no digest. It prints by default (`--format=json`, `--owner=CONTACT`), `--out=DIR` writes one text file each, and `--send` mails them only with `ROLLCALL_DIGEST_MAIL_ENABLED=true`, `ROLLCALL_DIGEST_MAIL_DSN` (a secret) and `ROLLCALL_DIGEST_MAIL_FROM` set and `symfony/mailer` installed (suggested, not required). Contract: `owners.schema.json` and `digest.schema.json`. Docs: Guides > Owner digests.
- Added: a guard for the per-OS rule. `tests/ScriptPairsTest.php` fails if a developer script ships as a `.sh` without its `.ps1` twin (or the other way round), if a `.sh` uses a bash 4 feature or a GNU-only flag that macOS rejects, or if a `.ps1` calls bash or WSL on the host. The three Squire files that run only inside Citadel's container are listed by name. Docs: Reference > Scripts on every OS lists every script, its twin and where it runs.
- Docs: the GitLab token, exactly (Security page). Every API call Rollcall makes, when, why and the scope that covers it (`read_api` for all of them), the scopes the token must not have and why (`api`, `write_repository`, `read_repository`, `read_user`, the registry scopes, `self_rotate`), the Reporter role, the membership fence, the expiry and who rotates it. A new test fails if the GitLab adapter calls an API path the table does not list.
- Added: a contract. `contract/` holds JSON Schemas (draft 2020-12) for the JSON report (public and internal), the stored snapshot and the image list export, and `tests/ContractTest.php` validates real output from the real commands and `GET /report.json` against them, using `opis/json-schema` (a dev dependency). Rollcall's own objects are closed, so a new field is a contract change in the same commit. Docs: Reference > Contract.

## [0.1.10] - 2026-11-07

- Added: per-version history. Each point of the report's `burn_down` carries the same roll three ways, counts only: `states` (per support state that day: `active`, `security_only`, `eol`, `unknown`), `php_image` (per PHP minor of the image, such as `{"8.1": 3, "8.4": 9}`) and `laravel` (per Laravel major), each in version order with `unknown` last. The existing `date`, `apps`, `eol`, `supported` and `unknown` fields are unchanged, so the change is additive. The breakdown is worked out from each stored snapshot when the report is built, so snapshots written by 0.1.9 and older get one too and the stored snapshot format stays at `schema_version` 1; it reaches back as far as `rollcall prune` keeps snapshots. ADVISOR's `rollcall_burn_down` tool returns the new fields. A reader of a 0.1.9 report should treat burn-down points without these keys as having no breakdown.

- Changed: The canonical repository is git.wayne.edu (ADR-0021); docs, links and package metadata no longer point at the archived nougat copy.
- Added: The Proof page records its last run.
- Security: the front-page deploy layer moves to Symfony 7.4.23 (symfony/http-foundation and symfony/yaml advisories, including CVE-2026-118514).
- Changed: docs sections carry the front page's folios. Each numbered section of a docs page opens with a red Caslon "§ 1", "§ 2" in front of its heading, as every chapter of the front page does (Related, the index, stays unnumbered); the mark is decoration, so the heading's accessible name is unchanged. With reduced motion off, each folio soaks into the paper as its section scrolls into view, in browsers with scroll-driven animations. Source: `docs/theme/rollcall.css`.
- Added: a Proof docs page (Reference > Proof). The first scheduled `rollcall-scan` on GitLab CI read the 13 stack projects (13 rows, blob ids matching `main`, 23 s job), and a rerun over 58 Wayne State applications from the stack's proof corpus gave every repository a row with 0 tool errors; it confirms the in-house auth package fix, explains the pipeline's one warning (PROCTOR on `deploy/`), and lists what is not proven yet (no image export, no PROCTOR reports, one snapshot).
- Changed: the text report's first line says `scan notes: N` instead of `scan problems: N`. The count is the notes a scan prints and carries on past (today: a source that is not configured), and the 2026-11-03 proof run had mistaken those two notes for unexplained errors. The JSON key `totals.scan_errors` and the metric `rollcall_scan_errors` keep their names. What the report says (README and docs) now explains the count, and that the EOL burn-down stays `unknown` until an image export is configured.
- Fixed: docs tables no longer scroll sideways in a narrow column. On a phone (or a tablet beside the sidebar) the settings table on the Configuration page turns each variable into an index card with Meaning, Default and Read in labeled in the margin, and the other tables stack each row under its name; at every width a long name in prose breaks instead of widening the table. The markup is unchanged, so screen readers still get a table with its column heads. Source: `docs/theme/rollcall.css`.
- Changed: the docs pages wear the front page's look, so the front page and the docs read as one roll book: the same masthead and main menu (Docs marked current with the highlighter), ruled paper and the red margin rule, Caslon titles, Courier Prime type, carbon-copy code blocks with a stamped Copy button, register-style tables, callouts as stamped index cards, code tabs as ledger tabs, stamped previous and next buttons and the green cloth back cover. Dark mode is derived (an ink-blue night); motion only plays when reduced motion is off. Source: `docs/_header.html` and `docs/theme/rollcall.css`, wired in `docs/docs.json`.
- Changed: synced Squire's unified docs builder (theme hook, callouts, code tabs, task lists, keyboard-reachable scrolling code blocks, a Copy button that no longer reports a failed copy after a good one). The docs site is rebuilt.
- Changed: an entry in `ROLLCALL_AUTH_PACKAGES` without a slash is a bare package name and matches that package under any vendor (`wayne-cas` matches `anyvendor/wayne-cas`); an entry with a slash still matches exactly, and the first match in list order wins. The built-in list gains `wayne-cas` and `simplecas` after the existing five, and the report carries the real locked package name, vendor included. The 2026-11-03 proof run over the real fleet found the gap: every app counted as having no auth package, although about half use an in-house CAS client the public-only list missed.
- Added: the dashboard announces what it shows (a status line) and has a rendered-HTML accessibility test.
- Changed: the front page has a main menu (sections plus Docs), so the docs site is one click from the front page.
- Changed: synced Squire's docs builder and agent-ready kit (bin/docs-site.php, bin/agent-ready.php, site/assets/agent-ready.js): table cells strip Symfony console style tags, readme images are copied byte for byte into site/docs/, and the kit JS uses bindingless catch blocks. The command reference and docs site are rebuilt.
- Docs: the front-page sample report shows ROLLCALL 0.1.9.
- Changed: the front-page deploy layer pins HELMET ^0.3, PROCTOR ^0.4 and CREED ^0.1 (lock: HELMET 0.3.0, PROCTOR 0.4.0, CREED 0.1.6).
- Changed: CI pins Preflight 0.1.4 (GitLab component includes and the GitHub reusable workflow at its tag commit); the deploy layer sets minimum-stability to stable.
- Docs: OPEN_QUESTIONS records that override storage is closed (PROCTOR is the source of truth), that the front page is deployed, and that the git.wayne.edu mirror exists.
- Added: a test that the public files name no per-app finding, internal hostname or IP address.

## [0.1.9] - 2026-11-03

- Added: ROLLCALL_GITLAB_PROJECTS, a project allowlist for the GitLab source (only those projects are read; no group is listed).
- Added: a rollcall-scan CI job (weekly schedule with ROLLCALL_JOB=scan, or run by hand) that scans the 13 stack projects read-only and keeps the snapshot as a 30 day artifact; without ROLLCALL_GITLAB_TOKEN it skips green.
- Changed: backlog items ROLLCALL-6 (SVG brand mark) and ROLLCALL-33 (`rollcall mcp` command) are closed as decided on 2026-11-03. The PNG is the mark, and ADVISOR is a stand-alone MCP server that loads the three read-only tools of the Rollcall module, so neither is needed.
- Changed: docs and package metadata name nougat as the canonical repository, with git.wayne.edu and GitHub as read-only mirrors, until git.wayne.edu has CI runners.
- Added: docs site built from docs/ with generated command and environment reference

## [0.1.8] - 2026-11-03

- Added: a three-OS smoke test checklist in MAINTAINING.md.
- Added: an accessibility gate for the front page (axe-core in Playwright, Docker), run by hand before a release.
- Added: a Dockerfile that runs the collector as a non-root user, settings from environment variables.
- Changed: CI pulls its Docker Hub images through the GitLab Dependency Proxy (the group's CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX), so pipelines no longer hit Docker Hub's anonymous pull limit.

## [0.1.7] - 2026-11-02

- Added: an ADVISOR module (waynestate/advisor) with three read-only tools over the public counts.
- Added: the front page versions table is generated from policy.json, and composer ci fails when it is stale.
- Changed: docs say the read_api token is a GitLab bot token requested from the GitLab administrator.
- Changed: the deploy layer's Squire kit is refreshed to 0.3.3 (release options can now live in ./.env.squire).
- Changed: the deploy layer's Squire kit is refreshed to 0.3.4 (the local .env.squire file is now git-ignored).

## [0.1.6] - 2026-10-29

- Fixed: stale capability claims in the site and README.
- Added: agent-ready site (prompt blocks, Markdown twins, llms.txt), from Squire's kit.

## [0.1.5] - 2026-10-29

- Changed: project names written as words except acronyms.
- Fixed: Rollcall reported 0.1.0 after later releases.

## [0.1.4] - 2026-10-29

### Added

- `ROLLCALL_GITLAB_URL` may now come from PLAYBOOK (`waynestate/playbook`, private on git.wayne.edu) when the
  environment has none: the environment wins, PLAYBOOK fills the gap, and the missing-setting error now points at
  the README. The GitLab token stays environment-only.

## [0.1.3] - 2026-10-29

- CREED reader re-exported from CREED 0.1.2: versions older than the oldest listed line are now end of life instead of refused.

## [0.1.2] - 2026-10-29

### Added

- `rollcall advisories --feed=<file>`: checks each app's locked `laravel/framework` and auth-package versions
  against a saved Packagist security-advisories response, one line per package and version (status, count, advisory
  ids, app count). A package with no advisory data prints `no data`, never `safe`; an unparseable version or range
  also says `no data`. ROLLCALL does not fetch the feed itself, and the output carries no app ids.

## [0.1.1] - 2026-10-29

### Added

- Token-expiry warning in `scan`: the GitLab source reads the calling token's own record
  (`GET /api/v4/personal_access_tokens/self`) and prints a note 14 days before it expires, or when it has already
  expired. The check is advisory: a refused, unreachable or unparseable answer prints nothing and never fails the
  scan, and the token value never appears in output. Rotating the token stays the owner's job.

### Changed

- Include Pinstripe's raw PHPStan preset (`waynestate/pinstripe:^0.1` dev dependency) instead of carrying a hand
  copy: `phpstan.neon` now includes `vendor/waynestate/pinstripe/phpstan/raw.neon` and keeps only ROLLCALL's paths
  and PHP range. The preset adds deprecation checks, so four calls to the deprecated Symfony Console
  `Application::add()` became `addCommand()`. Pint keeps its own `pint.json`: Pint has no include mechanism for
  config files, and the file already uses the same `per` preset as Pinstripe's `pint/per.json`, plus this repo's
  exclude list.

## [0.1.0] - 2026-10-29

### Added

- Framework-free core: policy reader with state by date, Composer file reader, image-tag reader, PROCTOR report
  reader, scanner, snapshot store, report builder (counts, drift, progress, EOL burn-down).
- Source interfaces for repositories, images and PROCTOR reports, with fixture adapters and a read-only GitLab adapter
  (PSR-18 via `symfony/http-client`) covering `Link` pagination, `HEAD` change detection and `429 Retry-After`.
- CLI: `rollcall scan`, `rollcall report` (text, JSON, Prometheus), `rollcall drift`.
- Optional Prometheus metrics, off by default, with a token or allowlist guard, plus a Grafana dashboard.
- Raw-PHP front controller (`/metrics`, `/report.json`) and a Laravel adapter (dashboard, `rollcall:scan`).
- `composer ci` (manifest, PHPUnit, Pint, PHPStan, audit) on GitLab and GitHub.
- Snapshot retention: `ROLLCALL_RETENTION_DAYS` (default 90; 0 keeps everything), `rollcall scan --prune[=DAYS]`,
  and `rollcall prune [--days=N] [--dry-run]` (plus `php artisan rollcall:prune`). Pruning never deletes the newest
  snapshot, so the dashboard always has data.
