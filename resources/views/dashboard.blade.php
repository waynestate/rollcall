{{--
    The ROLLCALL dashboard. Counts and progress only; the per-app table appears
    on the internal page and nowhere else.

    This page uses WayneUI's class names (wsu-table, wsu-stat) and links its
    stylesheet from ROLLCALL_WAYNEUI_CSS. It becomes <x-wsu::app-shell> once
    WayneUI is installable as a Composer dependency (see OPEN_QUESTIONS.md).
    State is spelled out in words, never colour alone (WCAG 1.4.1).
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>ROLLCALL: fleet census</title>
    @if ($css)
        <link rel="stylesheet" href="{{ $css }}">
    @endif
</head>
<body>
<a href="#main" class="wsu-skip-link">Skip to the report</a>
<main id="main">
    <h1>ROLLCALL</h1>

    @if ($report === null)
        <p>No scan has been stored yet. Run <code>php artisan rollcall:scan</code> and reload.</p>
    @else
        <p>
            Scan of {{ $report['scan_date'] }}, read against the policy as of {{ $report['as_of'] }}.
            @if ($internal)
                <strong>Internal view: do not publish.</strong>
            @endif
        </p>

        <section aria-labelledby="totals">
            <h2 id="totals">Apps present</h2>
            <div class="wsu-stat">
                <span class="wsu-stat__label">Apps present</span>
                <span class="wsu-stat__value">{{ $report['totals']['apps'] }}</span>
            </div>
            <div class="wsu-stat">
                <span class="wsu-stat__label">Apps that moved since {{ $report['progress']['since'] ?? 'the first scan' }}</span>
                <span class="wsu-stat__value">{{ $report['progress']['moved'] }}</span>
            </div>
            <div class="wsu-stat">
                <span class="wsu-stat__label">Apps upgraded from an EOL image to a supported one</span>
                <span class="wsu-stat__value">{{ $report['progress']['upgraded_to_supported'] }}</span>
            </div>
        </section>

        @foreach (['php_image' => 'PHP image', 'php_floor' => 'Declared PHP floor', 'laravel' => 'Laravel'] as $key => $title)
            <section aria-labelledby="h-{{ $key }}">
                <h2 id="h-{{ $key }}">{{ $title }}</h2>
                <div class="wsu-table-wrap" tabindex="0" role="region" aria-labelledby="cap-{{ $key }}">
                    <table class="wsu-table wsu-table--zebra">
                        <caption id="cap-{{ $key }}">{{ $title }}: apps by version, with the state the policy gives it today</caption>
                        <thead>
                            <tr><th scope="col">Version</th><th scope="col">Apps</th><th scope="col">State</th><th scope="col">Security support ends</th><th scope="col">Days left</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($report[$key] as $row)
                            <tr>
                                <th scope="row">{{ $row['version'] }}</th>
                                <td>{{ $row['apps'] }}</td>
                                <td>{{ str_replace('_', ' ', $row['state']) }}</td>
                                <td>{{ $row['security_until'] ?? 'not in the policy' }}</td>
                                <td>{{ $row['days_to_eol'] ?? 'unknown' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach

        <section aria-labelledby="h-drift">
            <h2 id="h-drift">Drift</h2>
            <div class="wsu-table-wrap" tabindex="0" role="region" aria-labelledby="cap-drift">
                <table class="wsu-table wsu-table--zebra">
                    <caption id="cap-drift">Apps showing each kind of drift</caption>
                    <thead><tr><th scope="col">Kind</th><th scope="col">Apps</th></tr></thead>
                    <tbody>
                    @foreach ($report['drift'] as $kind => $count)
                        <tr><th scope="row">{{ str_replace('_', ' ', $kind) }}</th><td>{{ $count }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section aria-labelledby="h-burn">
            <h2 id="h-burn">EOL burn-down</h2>
            <div class="wsu-table-wrap" tabindex="0" role="region" aria-labelledby="cap-burn">
                <table class="wsu-table wsu-table--zebra">
                    <caption id="cap-burn">Apps on an EOL PHP image, by scan day</caption>
                    <thead><tr><th scope="col">Scan day</th><th scope="col">Apps</th><th scope="col">EOL</th><th scope="col">Supported</th><th scope="col">Unknown</th></tr></thead>
                    <tbody>
                    @foreach ($report['burn_down'] as $point)
                        <tr><th scope="row">{{ $point['date'] }}</th><td>{{ $point['apps'] }}</td><td>{{ $point['eol'] }}</td><td>{{ $point['supported'] }}</td><td>{{ $point['unknown'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($internal)
            <section aria-labelledby="h-apps">
                <h2 id="h-apps">Apps</h2>
                <div class="wsu-table-wrap" tabindex="0" role="region" aria-labelledby="cap-apps">
                    <table class="wsu-table wsu-table--zebra">
                        <caption id="cap-apps">Each app's versions, PROCTOR result and drift</caption>
                        <thead>
                            <tr><th scope="col">App</th><th scope="col">PHP image</th><th scope="col">Image state</th><th scope="col">PHP floor</th><th scope="col">Laravel</th><th scope="col">Auth package</th><th scope="col">PROCTOR</th><th scope="col">Drift</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($report['apps'] as $app)
                            <tr>
                                <th scope="row">{{ $app['id'] }}</th>
                                <td>{{ $app['php_image'] ?? 'unknown' }}</td>
                                <td>{{ str_replace('_', ' ', $app['image_state']) }}</td>
                                <td>{{ $app['php_floor'] ?? 'unbounded' }}</td>
                                <td>{{ $app['laravel'] ?? 'none' }}</td>
                                <td>{{ $app['auth_package'] ?? 'none' }}{{ $app['auth_version'] ? ' '.$app['auth_version'] : '' }}</td>
                                <td>{{ $app['proctor'] ?? 'no report' }}</td>
                                <td>{{ $app['drift'] === [] ? 'none' : implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), $app['drift'])) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @endif
</main>
</body>
</html>
