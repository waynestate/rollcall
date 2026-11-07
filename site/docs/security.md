---
title: "Security notes | Rollcall docs"
description: "Rollcall is read-only toward every source, needs one read_api token and says exactly why, keeps that token out of git and output, and treats scanned files as data."
url: https://rollcall.apps.wayne.edu/docs/security.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Security notes[#](https://rollcall.apps.wayne.edu/docs/security.html#security-notes)

This page lists how Rollcall keeps itself and the fleet safe, and exactly what its GitLab token needs. Next: [run the checks](https://rollcall.apps.wayne.edu/docs/checks.html).

- Read-only toward GitLab and everything else: one gate in the GitLab adapter allows only `GET` and `HEAD`, and a test fails if a write verb appears in `src/`.
- The GitLab token is a bot or personal access token with the `read_api` scope only and an expiry, whose user can read only the projects to scan. It goes in the environment as `ROLLCALL_GITLAB_TOKEN`, never in git, is sent only to the configured `https` host, and is hidden from debug output and error messages. When the source is GitLab, `scan` reads the token's own record (`GET /api/v4/personal_access_tokens/self`) and prints a note 14 days before it expires, or when it has already expired. The note names the date; the owner rotates the token before its expiry, never Rollcall. The check is advisory: a refused, unreachable or unparseable answer prints nothing and never fails the scan, and the token value never appears in output.
- Composer files are parsed as data. No `composer install`, script or plugin from a scanned repository ever runs.
- PROCTOR reports, image tags and app ids are outside input: shapes are validated, text is bounded, and terminal output strips control characters.

## The GitLab token, exactly[#](https://rollcall.apps.wayne.edu/docs/security.html#the-gitlab-token-exactly)

Rollcall needs one scope, `read_api`, and one role, Reporter, on each project it scans. Nothing else. GitLab describes `read_api` as read access to the API for the token's scope, and every call Rollcall makes is a read through the REST API.

### Every call Rollcall makes[#](https://rollcall.apps.wayne.edu/docs/security.html#every-call-rollcall-makes)

| Call | When | Why | Covered by |
| --- | --- | --- | --- |
| `GET /api/v4/groups/:id/projects?include_subgroups=true&simple=true&archived=false&per_page=100` | `ROLLCALL_GITLAB_GROUPS` is set and `ROLLCALL_GITLAB_PROJECTS` is not | Lists the projects to scan, following `Link` pagination to the same host | `read_api` |
| `GET /api/v4/projects/:id?simple=true` | `ROLLCALL_GITLAB_PROJECTS` is set (no group is listed) | Reads each named project's id and default branch | `read_api` |
| `HEAD /api/v4/projects/:id/repository/files/:file_path?ref=` | Every scan, for `composer.json` and `composer.lock` | Reads the blob id only, so an unchanged file is not fetched again | `read_api` |
| `GET /api/v4/projects/:id/repository/files/:file_path/raw?ref=` | When a file is new or changed | Reads the file's bytes, without cloning | `read_api` |
| `GET /api/v4/personal_access_tokens/self` | Every scan | Reads the token's own expiry date for the 14-day note (advisory) | any valid token |

`tests/TokenScopeDocsTest.php` fails if the GitLab adapter calls an API path this table does not list.

### Scopes it must not have[#](https://rollcall.apps.wayne.edu/docs/security.html#scopes-it-must-not-have)

| Scope | Why not |
| --- | --- |
| `api` | Read and write access to the whole API. Rollcall never writes, so a leaked `api` token would hand out powers Rollcall never uses |
| `write_repository` | Push access. Rollcall never pushes |
| `read_repository` | Git-over-HTTP pull. Rollcall never clones; it reads two files through the API, which `read_api` covers |
| `read_user` | The `/user` profile. Rollcall never reads a profile; the expiry note uses `personal_access_tokens/self` |
| `read_registry`, `read_virtual_registry` | Container and package registries. Rollcall reads no images; the image tag comes from an export |
| `self_rotate` | Lets the token rotate itself. A person rotates the token, never Rollcall |
| `create_runner`, `manage_runner`, `k8s_proxy`, `ai_features` and any admin scope | Nothing Rollcall does |

### Role, membership and expiry[#](https://rollcall.apps.wayne.edu/docs/security.html#role-membership-and-expiry)

- **Reporter, on each project to scan, and nothing more.** Reporter is the lowest role that can read a private project's files; Guest cannot. Developer and above add powers Rollcall never uses.
- **Membership is the real fence.** `ROLLCALL_GITLAB_PROJECTS` limits what Rollcall asks for, not what the token can read. A group token on a large group would see other teams' repositories, so the token's user is a member of just the projects to scan: a service account, or a project or group bot on a small group made for the purpose.
- **An expiry, always.** GitLab caps a token at 365 days by default; the stack's habit is 90 days, then rotate. `scan` prints a note 14 days before the expiry and again once it has passed, naming the date.
- **Where it lives.** In the environment of the job that scans, as `ROLLCALL_GITLAB_TOKEN`: on GitLab CI a masked, hidden and protected CI/CD variable on the Rollcall project, not a file variable. Never in git, `.env.example`, an image or a log.
- **Who rotates it.** The maintainer of the project that runs the scan creates the replacement, updates the variable and revokes the old token before the expiry date. Rollcall only reminds.
- **A refused token stops the scan.** A `401` or `403` ends the scan loudly with exit `2`, so a dead token never produces a flattering partial roll.
