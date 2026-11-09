---
title: "Install | Rollcall docs"
description: "Install Rollcall from Packagist with one Composer line."
url: https://rollcall.apps.wayne.edu/docs/install.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Install[#](https://rollcall.apps.wayne.edu/docs/install.html#install)

This page is the install. Next: run the [quick start](https://rollcall.apps.wayne.edu/docs/guides/quick-start.html).

```
composer require waynestate/rollcall
```

Rollcall is on Packagist, so no extra repository is needed. Requires PHP `^8.3`. The CLI is `vendor/bin/rollcall`.

Optional: `composer require symfony/mailer` only if Rollcall should mail the [owner digests](https://rollcall.apps.wayne.edu/docs/guides/digest.html) itself (`rollcall digest --send`). Printing them, or writing them to files, needs nothing extra.

## Versions[#](https://rollcall.apps.wayne.edu/docs/install.html#versions)

1.0.0 is the current release, and Composer's `^1.0` takes every 1.x after it. From 1.0 Rollcall follows SemVer against its [contract](https://rollcall.apps.wayne.edu/docs/reference/contract.html): inside 1.x the report, the stored snapshot and the image list may gain a field or an enum value (a minor release), but never lose or retype one; that takes a 2.0. What changed in each release is in the [changelog](https://git.wayne.edu/enterprise-apps/rollcall/-/blob/main/CHANGELOG.md).
