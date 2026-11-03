---
title: "WSU values (Playbook) | Rollcall docs"
description: "ROLLCALL_GITLAB_URL may come from Playbook when the environment has none; the token never does."
url: https://rollcall.apps.wayne.edu/docs/guides/wsu-values.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# WSU values (Playbook)[#](https://rollcall.apps.wayne.edu/docs/guides/wsu-values.html#wsu-values-playbook)

This page says which setting may come from Playbook, and how to get Playbook into your app. Next: [Metrics](https://rollcall.apps.wayne.edu/docs/guides/metrics.html).

`ROLLCALL_GITLAB_URL` is a Wayne State value, so it may come from Playbook (`waynestate/playbook`) when the environment has none. The environment always wins; Playbook only fills what the environment leaves out. The GitLab token never comes through Playbook and stays an environment variable.

| Setting | Value |
| --- | --- |
| `ROLLCALL_GITLAB_URL` | The `https://` base URL of the GitLab instance |

Playbook is private and lives on git.wayne.edu. Getting it into your app:

Composer: add the repository to your `composer.json`, then require the package. Composer authenticates to git.wayne.edu with the token in `auth.json`.

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://git.wayne.edu/enterprise-apps/playbook.git"}
    ]
}
```

```
composer require waynestate/playbook
```

curl (Linux, macOS):

```
curl --fail -H "PRIVATE-TOKEN: $GITLAB_TOKEN" "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -o .env.playbook
```

PowerShell (Windows):

```
Invoke-WebRequest -Headers @{ 'PRIVATE-TOKEN' = $env:GITLAB_TOKEN } -Uri "https://git.wayne.edu/api/v4/projects/enterprise-apps%2Fplaybook/repository/files/playbook.env/raw?ref=main" -OutFile .env.playbook
```

Clone:

```
git clone https://git.wayne.edu/enterprise-apps/playbook.git
```

The token is your own: `read_api` for curl, `read_repository` for clone and Composer.

Playbook is private on git.wayne.edu. If you get a 404, ask the Enterprise Applications team for read access.
