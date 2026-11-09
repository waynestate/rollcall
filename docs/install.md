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

## Versions

1.0.0 is the current release, and Composer's `^1.0` takes every 1.x after it. From 1.0 Rollcall follows SemVer
against its [contract](reference/contract.md): inside 1.x the report, the stored snapshot and the image list may
gain a field or an enum value (a minor release), but never lose or retype one; that takes a 2.0. What changed in each
release is in the [changelog](https://git.wayne.edu/enterprise-apps/rollcall/-/blob/main/CHANGELOG.md).
