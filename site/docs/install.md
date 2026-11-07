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
