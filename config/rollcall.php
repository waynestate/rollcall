<?php

declare(strict_types=1);

/*
| ROLLCALL's Laravel settings. Every value comes from an environment variable;
| the names match the CLI's, so one .env serves both. Nothing here holds a secret.
*/

return [
    'policy_file' => env('ROLLCALL_POLICY_FILE'),
    'store_dir' => env('ROLLCALL_STORE_DIR', storage_path('rollcall/snapshots')),
    'source' => env('ROLLCALL_SOURCE', 'directory'),
    'repos_dir' => env('ROLLCALL_REPOS_DIR'),
    'gitlab_url' => env('ROLLCALL_GITLAB_URL'),
    'gitlab_token' => env('ROLLCALL_GITLAB_TOKEN'),
    'gitlab_groups' => env('ROLLCALL_GITLAB_GROUPS'),
    'images_file' => env('ROLLCALL_IMAGES_FILE'),
    'proctor_dir' => env('ROLLCALL_PROCTOR_DIR'),
    'auth_packages' => env('ROLLCALL_AUTH_PACKAGES'),
    'retention_days' => env('ROLLCALL_RETENTION_DAYS', 90),

    // Off by default, in Prometheus format, like the rest of the stack. On, /metrics needs the bearer token or an allowlisted address.
    'metrics_enabled' => env('ROLLCALL_METRICS_ENABLED', false),
    'metrics_token' => env('ROLLCALL_METRICS_TOKEN'),
    'metrics_allow' => env('ROLLCALL_METRICS_ALLOW'),
    'public_report_enabled' => env('ROLLCALL_PUBLIC_REPORT_ENABLED', false),

    // The dashboard shows counts only. The per-app page is internal and off until you turn it on
    // and name the middleware that signs people in; with no such middleware it fails closed.
    'dashboard' => [
        'enabled' => env('ROLLCALL_DASHBOARD_ENABLED', false),
        'path' => env('ROLLCALL_DASHBOARD_PATH', 'rollcall'),
        'middleware' => ['web'],
        'internal_enabled' => env('ROLLCALL_INTERNAL_ENABLED', false),
        'internal_middleware' => ['web', 'auth.sesame'],
    ],

    // Stylesheet that gives the dashboard WayneUI's look until the package is installable here.
    'wayneui_css' => env('ROLLCALL_WAYNEUI_CSS'),
];
