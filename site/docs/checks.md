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

`composer ci` runs every check: manifest, tests (the [contract suite](https://rollcall.apps.wayne.edu/docs/reference/contract.html) among them), Pint, the agent-ready files, a check that the docs site is current, a check that the front page's versions table and quick-start printout still match CREED's policy, PHPStan and `composer audit`. PHP is not needed on the host:

```
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

(On Windows PowerShell `${PWD}` works as written; in Git Bash use `$(pwd -W)` and set `MSYS_NO_PATHCONV=1`.)

The GitLab pipeline (`.gitlab-ci.yml`) and the GitHub workflow (`.github/workflows/ci.yml`) both wrap `composer ci`, on PHP 8.3, 8.4 and 8.5. git.wayne.edu now has an instance runner, and the stack's CI runs on it. Every product in the stack still strongly recommends a git.wayne.edu instance runner, so that capacity stays there.

The front page has its own accessibility gate (axe-core in Playwright, run in Docker): `scripts/a11y.sh` on Linux and macOS, `scripts/a11y.ps1` on Windows; [MAINTAINING.md](https://github.com/waynestate/rollcall/blob/main/MAINTAINING.md) says when to run it.

## Contributing[#](https://rollcall.apps.wayne.edu/docs/checks.html#contributing)

The canonical repository is [enterprise-apps/rollcall](https://git.wayne.edu/enterprise-apps/rollcall) on git.wayne.edu; changes land there first, and Wayne State developers open issues and merge requests on that project. GitHub ([waynestate/rollcall](https://github.com/waynestate/rollcall)) is a read-only push mirror of git.wayne.edu: nobody pushes to it, and Packagist reads from it.

License: MIT, Copyright Wayne State University.
