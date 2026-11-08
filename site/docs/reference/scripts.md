---
title: "Scripts on every OS | Rollcall docs"
description: "Which Rollcall commands are one PHP command for every OS, which ship as a PowerShell and bash pair, and which run only inside Citadel's container."
url: https://rollcall.apps.wayne.edu/docs/reference/scripts.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Scripts on every OS[#](https://rollcall.apps.wayne.edu/docs/reference/scripts.html#scripts-on-every-os)

This page lists every script Rollcall ships and how each OS runs it. Next: the [contract](https://rollcall.apps.wayne.edu/docs/reference/contract.html) its output keeps.

Every developer tool works on Windows, macOS and Linux. Where PHP fits, there is one PHP command and nothing to pair. Where a script drives Docker, it ships twice: a `.ps1` for Windows (PowerShell 5.1 and 7, never bash or WSL) and a `.sh` for Linux and macOS (written for macOS's bash 3.2 and BSD tools). `tests/ScriptPairsTest.php` fails if a script ships without its twin, if a `.sh` uses a bash 4 feature or a GNU-only flag, or if a `.ps1` calls a Unix shell on the host.

## One PHP command, every OS[#](https://rollcall.apps.wayne.edu/docs/reference/scripts.html#one-php-command-every-os)

| Command | What it does |
| --- | --- |
| `php bin/rollcall` (`vendor/bin/rollcall` in an install) | The CLI: `scan`, `report`, `digest`, `drift`, `advisories`, `prune` |
| `php bin/docs-site.php` (`composer docs`) | Builds the docs site from `docs/` |
| `php bin/agent-ready.php` (`composer site:agent-ready`) | Writes the Markdown twins, `llms.txt` and the sitemap |
| `php tools/versions-table.php` (`composer site:versions`) | Regenerates the front page versions table from CREED's policy |
| `php tools/creed-copy-check.php` (`composer creed-copy:check`) | Proves the CREED copy has not drifted |

## A pair per OS[#](https://rollcall.apps.wayne.edu/docs/reference/scripts.html#a-pair-per-os)

| Windows (PowerShell) | Linux and macOS (bash) | What they do |
| --- | --- | --- |
| `scripts/a11y.ps1` | `scripts/a11y.sh` | The front page accessibility gate: Preflight's a11y checker (vendored in `tools/a11y/check.mjs`, pinned by `tests/A11yCheckerPinTest.php`) in Docker, the same image and inputs as the `a11y-site` CI job |
| `scripts/dashboard-a11y.ps1` | `scripts/dashboard-a11y.sh` | The dashboard accessibility gate: renders the public and internal dashboard pages with PHPUnit, then runs the same Preflight checker on both, in Docker |
| `deploy/scripts/parity.ps1` | `deploy/scripts/parity.sh` | Runs the front page locally on the Citadel PHP image behind a proxy (Squire's) |

`scripts/readme-art` is a Node tool (`npm run build`), the same on every OS.

## Inside Citadel's container only[#](https://rollcall.apps.wayne.edu/docs/reference/scripts.html#inside-citadel-s-container-only)

These run on Citadel's Linux container, never on a developer machine, so they have no Windows twin. Squire writes and owns them (`deploy/.squire.json`):

| File | When Citadel runs it |
| --- | --- |
| `deploy/build.sh` | At image build time, as root, with `/bin/sh` |
| `deploy/deployments/001-first-release.sh` | Once, when the first release starts |
| `deploy/scripts/release.sh` | After every Quick Update (the post-script), and from the first-release hook |

## Inside GitLab CI only[#](https://rollcall.apps.wayne.edu/docs/reference/scripts.html#inside-gitlab-ci-only)

This runs in a GitLab CI job on the Linux composer image, never on a developer machine, so it has no Windows twin. It still keeps to POSIX sh and macOS-safe tools, like every `.sh` here.

| File | When GitLab runs it |
| --- | --- |
| `tools/creed-copy-refresh.sh` | The weekly `creed-copy-refresh` job (or a web pipeline on main): re-exports the CREED copy from CREED main, rebuilds the versions table, docs and agent-ready files, runs `composer creed-copy:check` and `composer ci`, and pushes to main with the job token only when both pass |

Before a release, a maintainer runs the smoke test on all three OSes; the checklist is in [MAINTAINING.md](https://github.com/waynestate/rollcall/blob/main/MAINTAINING.md), "Smoke test on every OS".
