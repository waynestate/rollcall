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
- The GitLab token is a group or project access token (a GitLab bot), or a service account's personal access token, with the `read_api` scope only, the Reporter role and an expiry. The stack's own is the group access token `rollcall-scanner` (see [the scanner token and how to rotate it](https://rollcall.apps.wayne.edu/docs/security.html#the-scanner-token-and-how-to-rotate-it)). It goes in the environment as `ROLLCALL_GITLAB_TOKEN`, never in git, is sent only to the configured `https` host, and is hidden from debug output and error messages. When the source is GitLab, `scan` reads the token's own record (`GET /api/v4/personal_access_tokens/self`) and prints a note 14 days before it expires, or when it has already expired. The note names the date; the stack maintainer rotates the token before its expiry, never Rollcall. The check is advisory: a refused, unreachable or unparseable answer prints nothing and never fails the scan, and the token value never appears in output.
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
| `GET /api/v4/projects/:id/pipelines?ref=&scope=finished&order_by=id&sort=desc&per_page=5` | `ROLLCALL_PROCTOR_SOURCE=gitlab`, once per project per scan | Finds the newest finished pipelines on the default branch, passed or failed | `read_api` (Reporter reads pipelines) |
| `GET /api/v4/projects/:id/pipelines/:pipeline_id/jobs?per_page=100` | `ROLLCALL_PROCTOR_SOURCE=gitlab`, newest pipeline first until one ran the proctor job | Finds that pipeline's proctor job id, following `Link` pagination to the same host | `read_api` |
| `GET /api/v4/projects/:id/jobs/:job_id/artifacts/:artifact_path` | `ROLLCALL_PROCTOR_SOURCE=gitlab`, when a proctor job was found | Reads the one report file (`proctor.json`) from that job's artifact; 404 means no report | `read_api` (Reporter reads job artifacts) |
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
| `self_rotate` | Lets the token rotate itself. The stack maintainer rotates the token, never Rollcall |
| `create_runner`, `manage_runner`, `k8s_proxy`, `ai_features` and any admin scope | Nothing Rollcall does |

### Role, membership and expiry[#](https://rollcall.apps.wayne.edu/docs/security.html#role-membership-and-expiry)

- **Reporter, on each project to scan, and nothing more.** Reporter is the lowest role that can read a private project's files; Guest cannot. Developer and above add powers Rollcall never uses.
- **Membership is the real fence.** `ROLLCALL_GITLAB_PROJECTS` limits what Rollcall asks for, not what the token can read. A group token reads every project in its group. For the tightest fence, the token's user is a member of just the projects to scan: a service account, or a bot on a small group made for the purpose. The stack's own token takes the wider road on purpose (below), and the scan job's project list keeps the asking narrow.
- **An expiry, always.** GitLab caps a token at 365 days by default. `scan` prints a note 14 days before the expiry and again once it has passed, naming the date.
- **Where it lives.** In the environment of the job that scans, as `ROLLCALL_GITLAB_TOKEN`: on GitLab CI a masked, hidden and protected CI/CD variable on the Rollcall project, not a file variable. Never in git, `.env.example`, an image or a log.
- **Who rotates it.** A role, not a person: the stack maintainer creates the replacement, updates the variable and revokes the old token before the expiry date. Rollcall only reminds.
- **A refused token stops the scan.** A `401` or `403` ends the scan loudly with exit `2`, so a dead token never produces a flattering partial roll.

## The scanner token and how to rotate it[#](https://rollcall.apps.wayne.edu/docs/security.html#the-scanner-token-and-how-to-rotate-it)

The stack's weekly scan on git.wayne.edu reads with one token. Everything about it except its value is here.

| What | The stack's answer |
| --- | --- |
| Token | The group access token `rollcall-scanner` on the `enterprise-apps` group: Reporter, `read_api` only. The project owner chose group reach (2026-11-06), so it can read the group's projects; the job asks only for the 13 it lists |
| Where it lives | Its value lives only in the CI/CD variable `ROLLCALL_GITLAB_TOKEN` on `enterprise-apps/rollcall`: masked, hidden, protected, not a file. The token itself is listed under the group's Settings > Access tokens |
| Expires | 2027-11-06. `scan` starts its note on 2027-10-23 |
| Who rotates it | The stack maintainer, a role, not a named person. Creating a group access token needs the Owner role on the group; a stack maintainer without it asks a group Owner for step 1 and does the rest |
| What uses it | The `rollcall-scan` job, on the Monday schedule with `ROLLCALL_JOB=scan`, or started by hand |

Rotate it in one sitting, well before the expiry and not just before the Monday schedule runs:

1. **Make the replacement.** In the group's Settings > Access tokens, add a new token: name `rollcall-scanner`, role Reporter, scope `read_api` only, an expiry at most a year out. Copy the value once; GitLab never shows it again. (The token list's Rotate button is the shortcut, but it revokes the old token at once and you must check the new expiry it gives: GitLab's API defaults a rotated token to one week.)
2. **Update the variable.** In `enterprise-apps/rollcall`, Settings > CI/CD > Variables, edit `ROLLCALL_GITLAB_TOKEN` and paste the new value. Keep it masked, hidden and protected, not a file. Paste it nowhere else: not a chat, a ticket, a file or a commit.
3. **Prove it.** Run a pipeline on `main` with the variable `ROLLCALL_JOB` set to `scan`. In the job log, look for `Present: 13 apps.` and no `not set; skipping`, no `GitLab refused the token` and no `Note: the GitLab token` line (the new token is a year from expiry, so it gets no note).
4. **Revoke the old one.** Back in the group's access tokens, revoke the old `rollcall-scanner` (the one with the earlier expiry).
5. **Write down the new date.** Change the expiry in the table above, and note the rotation in `CHANGELOG.md`, in the same merge request.

A token that leaked is rotated the same way, at once, without waiting for its date. Revoking it first is fine: the scan stops loudly until step 2 is done.
