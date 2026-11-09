---
title: Laravel
description: Register the package, publish the config, schedule the scan, and enable the dashboard routes; or serve the same dashboard from a raw PHP app with one call.
---

# Laravel

This page wires Rollcall into a Laravel app, and shows the same dashboard in a raw PHP app. Next: [use it from your coding agent (ADVISOR)](advisor.md).

The package registers itself (`extra.laravel.providers`). Publish the config with
`php artisan vendor:publish --tag=rollcall-config`, set the variables in [the configuration reference](../reference/configuration.md), and schedule `rollcall:scan`. The
dashboard shows counts and progress; the per-app page is a second route that stays off until you enable it, and it
runs behind `rollcall.dashboard.internal_middleware` (default `web` and `auth.sesame`), so an app with no such
middleware fails closed. The page uses WayneUI's class names and takes its stylesheet from `ROLLCALL_WAYNEUI_CSS`.
The page also carries a status line (a `role="status"` paragraph) that names the apps shown and the scan date, so
assistive technology hears what the page displays. The `/metrics` route is registered on its own path; if your app
already serves `/metrics` (HELMET does), run Rollcall's on a separate app or leave metrics off.

## The dashboard in a raw PHP app

The same page, no framework. `rollcall_dashboard()` prints the renderer the Laravel page prints, from the same
report, so the two answer the roll in the same words. Drop one file into the folder your web server serves:

```php
<?php
// public/rollcall.php
require __DIR__ . '/../vendor/autoload.php';

$helmet = \Waynestate\Helmet\helmet_boot(['preset' => 'citadel']);
rollcall_dashboard(['nonce' => $helmet['nonce']]);
```

It answers 404 until `ROLLCALL_DASHBOARD_ENABLED=true`, sends `X-Robots-Tag: noindex` with every answer, and never
runs a scan: it reads the latest snapshot, so set `ROLLCALL_POLICY_FILE` and `ROLLCALL_STORE_DIR` as for the CLI
and schedule `vendor/bin/rollcall scan`. The page has no script. Its one small style element takes HELMET's nonce;
link WayneUI's stylesheet with `ROLLCALL_WAYNEUI_CSS` and the page keeps its look under HELMET's CSP even without
one. No HELMET? Call `rollcall_dashboard()` with no options. It carries the same status line as the Laravel page.

The per-app page is a second file, behind your sign-in: call `rollcall_dashboard(['internal' => true])` after
SESAME's `sesame_login()` has returned a user. It also needs `ROLLCALL_INTERNAL_ENABLED=true`; with either one
missing it answers 404, so it fails closed. For a front controller of your own,
`Waynestate\Rollcall\Dashboard\RawDashboard::response()` returns the same answer as data (status, headers, body).

Both pages go through the same accessibility gate: `DashboardRenderTest` writes the Laravel and the raw-PHP pages,
and `dashboard-a11y` checks all six in light and dark at 320 and 1280 px.