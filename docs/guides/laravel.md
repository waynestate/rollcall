---
title: Laravel
description: Register the package, publish the config, schedule the scan, and enable the dashboard routes; show the same dashboard as one of your own Inertia (Vue) pages, or serve it from a raw PHP app with one call.
---

# Laravel

This page wires Rollcall into a Laravel app, and shows the same dashboard as a Vue page in an Inertia app and in a raw PHP app. Next: [use it from your coding agent (ADVISOR)](advisor.md).

The package registers itself (`extra.laravel.providers`). Publish the config with
`php artisan vendor:publish --tag=rollcall-config`, set the variables in [the configuration reference](../reference/configuration.md), and schedule `rollcall:scan`. The
dashboard shows counts and progress; the per-app page is a second route that stays off until you enable it, and it
runs behind `rollcall.dashboard.internal_middleware` (default `web` and `auth.sesame`), so an app with no such
middleware fails closed. The page uses WayneUI's class names and takes its stylesheet from `ROLLCALL_WAYNEUI_CSS`.
In an Inertia app it can be one of your own Vue pages instead (below).
The page also carries a status line (a `role="status"` paragraph) that names the apps shown and the scan date, so
assistive technology hears what the page displays. The `/metrics` route is registered on its own path; if your app
already serves `/metrics` (HELMET does), run Rollcall's on a separate app or leave metrics off.

## The dashboard in an Inertia app (Vue)

In a Laravel + Inertia + Vue app (WELD's shape) the dashboard can be one of the app's own pages, inside its own
layout. Same routes, same switches, same report: with `ROLLCALL_DASHBOARD_INERTIA=true` the controller hands what
`DashboardPage::report()` built to an Inertia page as props (`report`, `internal`, `scanCommand`) instead of printing
the Blade view. From a WELD app:

```bash
php artisan vendor:publish --tag=rollcall-inertia   # writes resources/js/pages/Rollcall/Dashboard.vue
# .env: ROLLCALL_DASHBOARD_ENABLED=true and ROLLCALL_DASHBOARD_INERTIA=true
npm run build
```

The published page is short and it is yours. In essence it puts the component in WELD's `AppLayout` with a title
and a `noindex` tag (the full file adds breadcrumbs and a few lines of scoped CSS, in WayneUI's spacing tokens, that space the sections and set the three totals side by side):

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import RollcallDashboard from '../../../../vendor/waynestate/rollcall/resources/js/RollcallDashboard.vue';

defineProps<{ report: Record<string, unknown> | null; internal: boolean; scanCommand: string }>();
</script>

<template>
    <Head title="Rollcall"><meta name="robots" content="noindex" /></Head>
    <AppLayout>
        <RollcallDashboard :report="report" :internal="internal" :scan-command="scanCommand" />
    </AppLayout>
</template>
```

Why this shape: the page is published because the layout is the app's, and the component is imported straight
from `vendor/` because the markup is Rollcall's. `composer update` moves the component together with the PHP
renderer it must match, and there is no npm package to install or keep in step (Rollcall publishes none). Vite and
`vue-tsc` follow the relative import like any file in the project, and the component imports nothing but `vue`,
which the app already has. An app that does not use the Vue page loads none of it; `inertiajs/inertia-laravel` is
needed only when the switch is on.

The component renders what the Blade page shows inside `<main>`: the same WayneUI classes, the `role="status"` line,
the captions and headers, and the per-app table only on the internal page (`<path>/apps`, still behind
`rollcall.dashboard.internal_middleware` and `ROLLCALL_INTERNAL_ENABLED`). It has no style block, no style attribute
and no script of its own, so it works under HELMET's CSP, and it takes its look from the app's WayneUI stylesheet.
Every Inertia answer carries `X-Robots-Tag: noindex`. CI holds the rooms together: `dashboard-vue` server-renders
the component with the props the route sends and fails on any difference from the Blade page (elements, attributes
and text; only whitespace, attribute order and Vue's hydration comments are normalized), and `dashboard-a11y`
checks the three Vue pages with the other six.


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

All three go through the same accessibility gate: `DashboardRenderTest` writes the Laravel and the raw-PHP pages and
the Inertia props, `dashboard-vue` renders the Vue pages from those props once they match, and `dashboard-a11y` checks
all nine (public, internal and empty, in each room) in light and dark at 320 and 1280 px.