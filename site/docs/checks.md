---
title: "Run the checks | Rollcall docs"
description: "composer ci is the contract, with Docker, pipelines and the front page accessibility gate; Contributing follows."
url: https://rollcall.apps.wayne.edu/docs/checks.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Run the checks[#](https://rollcall.apps.wayne.edu/docs/checks.html#run-the-checks)

This page shows how the project is built, tested and released, and where changes land.

Checks: `composer ci` (the same command runs on GitLab and GitHub).

Maintainers: how to build, test and release this project, and where its data comes from, is in [MAINTAINING.md](https://github.com/waynestate/rollcall/blob/main/MAINTAINING.md).

`composer ci` is the contract: manifest, tests, Pint, PHPStan, `composer audit`, and a check that the front page versions table still matches CREED's policy. PHP is not needed on the host:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

(On Windows PowerShell `${PWD}` works as written; in Git Bash use `$(pwd -W)` and set `MSYS_NO_PATHCONV=1`.)

The GitLab pipeline (`.gitlab-ci.yml`) and the GitHub workflow (`.github/workflows/ci.yml`) both wrap `composer ci`, on PHP 8.3, 8.4 and 8.5. git.wayne.edu has no runners yet; an instance runner there is strongly recommended so this check can run beside the code it protects.

The front page has its own accessibility gate (axe-core in Playwright, run in Docker): `scripts/a11y.sh` on Linux and macOS, `scripts/a11y.ps1` on Windows; [MAINTAINING.md](https://github.com/waynestate/rollcall/blob/main/MAINTAINING.md) says when to run it.

## Contributing[#](https://rollcall.apps.wayne.edu/docs/checks.html#contributing)

This repository is published from Wayne State University's internal GitLab and mirrored to GitHub. Changes land there first; Wayne State developers open issues and merge requests on the internal project.

License: MIT, Copyright Wayne State University.
