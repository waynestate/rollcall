---
title: "Laravel | Rollcall docs"
description: "Register the package, publish the config, schedule the scan, and enable the dashboard routes."
url: https://rollcall.apps.wayne.edu/docs/guides/laravel.html
llms: https://rollcall.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

# Laravel[#](https://rollcall.apps.wayne.edu/docs/guides/laravel.html#laravel)

This page wires Rollcall into a Laravel app. Next: [use it from your coding agent (ADVISOR)](https://rollcall.apps.wayne.edu/docs/guides/advisor.html).

The package registers itself (`extra.laravel.providers`). Publish the config with `php artisan vendor:publish --tag=rollcall-config`, set the variables in [the configuration reference](https://rollcall.apps.wayne.edu/docs/reference/configuration.html), and schedule `rollcall:scan`. The dashboard shows counts and progress; the per-app page is a second route that stays off until you enable it, and it runs behind `rollcall.dashboard.internal_middleware` (default `web` and `auth.sesame`), so an app with no such middleware fails closed. The page uses WayneUI's class names and takes its stylesheet from `ROLLCALL_WAYNEUI_CSS`. The page also carries a status line (a `role="status"` paragraph) that names the apps shown and the scan date, so assistive technology hears what the page displays. The `/metrics` route is registered on its own path; if your app already serves `/metrics` (HELMET does), run Rollcall's on a separate app or leave metrics off.
