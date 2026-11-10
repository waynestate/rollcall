---
title: "Run the collector in Docker | Rollcall docs"
description: "Build the collector image and run a scan with docker run, with no PHP on the machine."
url: https://rollcall.apps.wayne.edu/docs/guides/collector-docker.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Run the collector in Docker [#](https://rollcall.apps.wayne.edu/docs/guides/collector-docker.html#run-the-collector-in-docker)

This page builds the collector image and runs a scan in Docker. Next: [WSU values (Playbook)](https://rollcall.apps.wayne.edu/docs/guides/wsu-values.html) for where the GitLab URL comes from.

No PHP on the machine? The repo ships a `Dockerfile` that builds a small image of the collector. It runs as a non-root user, writes only to its snapshot folder, and takes every setting from the environment at run time. Never put a token in the image: the build stays clean, and the token travels with `-e` (or `--env-file`) each time you run. Two lines, and every app answers present.

Build it, from the repo root (again whenever the code changes):

```
docker build -t rollcall-collector .
```

That reads the `Dockerfile` next to it and tags the result `rollcall-collector`. Then run a scan. Each `-e` flag is a setting from [the configuration table](https://rollcall.apps.wayne.edu/docs/reference/configuration.html); for the narrow scan add `-e ROLLCALL_GITLAB_PROJECTS=group/project,other-group/other-project` and only those projects are read, no group is listed. The two `-v` flags mount your policy file and snapshot folder so the answer survives the container:

```
docker run --rm -e ROLLCALL_SOURCE=gitlab -e ROLLCALL_GITLAB_URL=https://gitlab.example.test -e ROLLCALL_GITLAB_TOKEN="$ROLLCALL_GITLAB_TOKEN" -e ROLLCALL_GITLAB_GROUPS=your-group -e ROLLCALL_POLICY_FILE=/app/policy.json -v "$PWD/policy.json:/app/policy.json" -v "$PWD/var:/app/var" rollcall-collector scan
```

Next: after a good scan, `var/snapshots/<today>.json` holds the day's attendance, and the same run with `report` instead of `scan` prints it. On Windows PowerShell, set `$env:ROLLCALL_GITLAB_TOKEN` first and use `${PWD}` for the mount paths.
