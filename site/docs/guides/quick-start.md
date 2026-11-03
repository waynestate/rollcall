---
title: "Quick start | Rollcall docs"
description: "Scan and report on the fixtures in the repo, with no network and no token."
url: https://rollcall.apps.wayne.edu/docs/guides/quick-start.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Quick start[#](https://rollcall.apps.wayne.edu/docs/guides/quick-start.html#quick-start)

This page takes you from a fresh clone to a first report, on the fixtures in the repo. Next: read [what the report says](https://rollcall.apps.wayne.edu/docs/guides/what-the-report-says.html).

This runs on the fixtures in the repo, so you need no network and no token. Run `composer install` once (or the Docker line under [Run the checks](https://rollcall.apps.wayne.edu/docs/checks.html)), then the commands below.

Linux and macOS (bash):

```
export ROLLCALL_POLICY_FILE=tests/fixtures/policy.json
export ROLLCALL_REPOS_DIR=tests/fixtures/repos
export ROLLCALL_IMAGES_FILE=tests/fixtures/images.json
export ROLLCALL_PROCTOR_DIR=tests/fixtures/proctor
export ROLLCALL_STORE_DIR=var/snapshots
php bin/rollcall scan
php bin/rollcall report
```

Windows (PowerShell, no bash or WSL needed):

```
$env:ROLLCALL_POLICY_FILE = "tests/fixtures/policy.json"
$env:ROLLCALL_REPOS_DIR = "tests/fixtures/repos"
$env:ROLLCALL_IMAGES_FILE = "tests/fixtures/images.json"
$env:ROLLCALL_PROCTOR_DIR = "tests/fixtures/proctor"
$env:ROLLCALL_STORE_DIR = "var/snapshots"
php bin/rollcall scan
php bin/rollcall report
```

`policy.json` is CREED's file; the copy under `tests/fixtures/` is a snapshot for tests. Use the released one in real runs.
