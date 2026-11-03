---
title: "Security notes | Rollcall docs"
description: "Rollcall is read-only toward every source, keeps the GitLab token out of git and output, and treats scanned files as data."
url: https://rollcall.apps.wayne.edu/docs/security.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Security notes[#](https://rollcall.apps.wayne.edu/docs/security.html#security-notes)

This page lists how Rollcall keeps itself and the fleet safe. Next: [run the checks](https://rollcall.apps.wayne.edu/docs/checks.html).

- Read-only toward GitLab and everything else: one gate in the GitLab adapter allows only `GET` and `HEAD`, and a test fails if a write verb appears in `src/`.
- The GitLab token is a bot or personal access token with the `read_api` scope only and an expiry, whose user can read only the projects to scan. It goes in the environment as `ROLLCALL_GITLAB_TOKEN`, never in git, is sent only to the configured `https` host, and is hidden from debug output and error messages. When the source is GitLab, `scan` reads the token's own record (`GET /api/v4/personal_access_tokens/self`) and prints a note 14 days before it expires, or when it has already expired. The note names the date; the owner rotates the token before its expiry, never Rollcall. The check is advisory: a refused, unreachable or unparseable answer prints nothing and never fails the scan, and the token value never appears in output.
- Composer files are parsed as data. No `composer install`, script or plugin from a scanned repository ever runs.
- PROCTOR reports, image tags and app ids are outside input: shapes are validated, text is bounded, and terminal output strips control characters.
