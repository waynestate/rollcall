---
title: Install
description: Install Rollcall from Packagist with one Composer line.
---

# Install

This page is the install. Next: run the [quick start](guides/quick-start.md).

```
composer require waynestate/rollcall
```

Rollcall is on Packagist, so no extra repository is needed. Requires PHP `^8.3`. The CLI is `vendor/bin/rollcall`.

Optional: `composer require symfony/mailer` only if Rollcall should mail the [owner digests](guides/digest.md)
itself (`rollcall digest --send`). Printing them, or writing them to files, needs nothing extra.
