{{--
    The Rollcall dashboard. The markup lives in Waynestate\Rollcall\Dashboard\DashboardPage,
    which the raw-PHP entry point (rollcall_dashboard()) prints too, so the two pages cannot
    drift. Counts and progress only; the per-app table appears on the internal page and
    nowhere else. The CSP nonce, when the app has one, goes on the page's one style element.
    The comment closes on the same line as the echo, so the page starts at its doctype,
    and this file has no final newline, so the page ends where the renderer ends it.
    DashboardParityTest fails if either one creeps back.
--}}{!! (new \Waynestate\Rollcall\Dashboard\DashboardPage(\Waynestate\Rollcall\Dashboard\DashboardPage::LARAVEL_SCAN))->render($report, $internal, $css, \Illuminate\Support\Facades\Vite::cspNonce()) !!}