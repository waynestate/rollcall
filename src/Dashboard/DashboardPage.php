<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Dashboard;

use DateTimeImmutable;
use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\RollcallException;

/**
 * The Rollcall dashboard, as one HTML string. The Laravel view and the raw-PHP
 * entry point (rollcall_dashboard()) both print this, so the two pages cannot
 * drift: one roll, read out in two rooms.
 *
 * Counts and progress only; the per-app table appears on the internal page and
 * nowhere else. The page uses WayneUI's class names (wsu-table, wsu-stat, the
 * skip link) and links its stylesheet; see OPEN_QUESTIONS.md, question 13.
 * State is spelled out in words, never color alone (WCAG 1.4.1). No rule lives
 * here: every number comes from the report builder.
 */
final class DashboardPage
{
    /** What the Laravel page's empty state tells you to run. */
    public const LARAVEL_SCAN = 'php artisan rollcall:scan';

    /** What the raw-PHP page's empty state tells you to run. */
    public const CLI_SCAN = 'vendor/bin/rollcall scan';

    /** The version tables, in page order: report key => title. */
    private const VERSION_TABLES = ['php_image' => 'PHP image', 'php_floor' => 'Declared PHP floor', 'laravel' => 'Laravel'];

    /** The MySQL 5.6 CI job states, in page order: report key => label. */
    private const MYSQL56_STATES = ['held' => 'held', 'missing' => 'missing', 'not_applicable' => 'not applicable', 'unknown' => 'unknown'];

    /** The one state that needs a person: an app with migrations and no MySQL 5.6 job. */
    private const MYSQL56_BAD = 'missing';

    /** What a row's Citadel date says once it is the report's day or earlier: Citadel may move the app any time now. */
    private const DUE_COME = 'date has come';

    /** @param  string  $scanCommand  the command the empty state names: the only line that differs between the two paths */
    public function __construct(private readonly string $scanCommand = self::CLI_SCAN) {}

    /**
     * The report the page shows: the latest stored snapshot read against the
     * policy as of now, or null when no scan is stored yet or the core is not
     * ready (no policy, an unreadable store). A web request never runs a scan.
     *
     * @return array<string, mixed>|null
     */
    public static function report(Factory $factory, bool $internal, ?DateTimeImmutable $now = null): ?array
    {
        try {
            $store = $factory->store();
            $latest = $store->latest();

            return $latest === null
                ? null
                : $factory->reportBuilder()->build($latest, $store->all(), $now ?? (new SystemClock())->now(), $internal);
        } catch (RollcallException) {
            return null;
        }
    }

    /** The stylesheet URL when it is https:// or a path on this host; anything else links nothing. */
    public static function stylesheet(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^(https://|/)[^\s"<>]+$#', $url) === 1 ? $url : null;
    }

    /**
     * @param  array<string, mixed>|null  $report  what DashboardPage::report() returned
     * @param  string|null  $stylesheet  WayneUI's stylesheet (ROLLCALL_WAYNEUI_CSS)
     * @param  string|null  $nonce  the response's CSP nonce, for the one small built-in style element
     */
    public function render(?array $report, bool $internal, ?string $stylesheet = null, ?string $nonce = null): string
    {
        $stylesheet = self::stylesheet($stylesheet);

        $h = [];
        $h[] = '<!DOCTYPE html>';
        $h[] = '<html lang="en">';
        $h[] = '<head>';
        $h[] = '    <meta charset="utf-8">';
        $h[] = '    <meta name="viewport" content="width=device-width, initial-scale=1">';
        $h[] = '    <meta name="robots" content="noindex">';
        $h[] = '    <title>ROLLCALL: fleet census</title>';
        if ($stylesheet !== null) {
            $h[] = '    <link rel="stylesheet" href="' . self::e($stylesheet) . '">';
        }
        // WCAG 1.4.10: a wide table scrolls inside its own region at 320 px, even with no WayneUI stylesheet.
        // WayneUI's stylesheet carries the same rule, so under a strict CSP with no nonce the page still reflows.
        $h[] = '    <style' . ($nonce !== null && $nonce !== '' ? ' nonce="' . self::e($nonce) . '"' : '') . '>';
        $h[] = '        .wsu-table-wrap { max-width: 100%; overflow-x: auto; }';
        $h[] = '    </style>';
        $h[] = '</head>';
        $h[] = '<body>';
        $h[] = '<a href="#main" class="wsu-skip-link">Skip to the report</a>';
        $h[] = '<main id="main">';
        $h[] = '    <h1>ROLLCALL</h1>';

        if ($report === null) {
            $h[] = '    <p>No scan has been stored yet. Run <code>' . self::e($this->scanCommand) . '</code> and reload.</p>';
        } else {
            array_push($h, ...$this->body($report, $internal));
        }

        $h[] = '</main>';
        $h[] = '</body>';
        $h[] = '</html>';

        return implode("\n", $h) . "\n";
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function body(array $report, bool $internal): array
    {
        $totals = self::arr($report['totals'] ?? []);
        $progress = self::arr($report['progress'] ?? []);
        $scanDate = self::e($report['scan_date'] ?? '');
        $apps = self::e($totals['apps'] ?? '');

        $h = [];
        $h[] = '    <p>';
        $h[] = '        Scan of ' . $scanDate . ', read against the policy as of ' . self::e($report['as_of'] ?? '') . '.';
        if ($internal) {
            $h[] = '        <strong>Internal view: do not publish.</strong>';
        }
        $h[] = '    </p>';
        $h[] = '    <p id="rc-status" role="status">';
        $h[] = '        Showing ' . $apps . ' apps from the scan of ' . $scanDate . '.';
        if ($internal) {
            $h[] = '        This page lists every app; keep it inside the signed-in app.';
        }
        $h[] = '    </p>';
        $h[] = '';
        $h[] = '    <section aria-labelledby="totals">';
        $h[] = '        <h2 id="totals">Apps present</h2>';
        $h[] = self::stat('Apps present', $totals['apps'] ?? '');
        $h[] = self::stat('Apps that moved since ' . self::text($progress['since'] ?? null, 'the first scan'), $progress['moved'] ?? '');
        $h[] = self::stat('Apps upgraded from an EOL image to a supported one', $progress['upgraded_to_supported'] ?? '');
        $h[] = '    </section>';

        // Citadel's forced image upgrades, counted: the dates the fleet moves whether it is ready or not.
        $forced = self::arr($report['forced_upgrades'] ?? []);
        $overdue = $forced['overdue'] ?? '';
        $h[] = '';
        $h[] = '    <section aria-labelledby="h-forced">';
        $h[] = '        <h2 id="h-forced">Forced image upgrades</h2>';
        $h[] = '        <p>On each date in Citadel\'s registrations export, Citadel moves an app to a newer base image, ready or not.'
            . ' The counts come from that export, so with any other image source they stay at zero.</p>';
        $h[] = self::stat('Apps with an upgrade pending', $forced['pending'] ?? '');
        $h[] = '        <div class="wsu-stat"><span class="wsu-stat__label">'
            . (is_int($overdue) && $overdue > 0 ? self::badge('Due today or earlier') : 'Due today or earlier') . '</span>'
            . '<span class="wsu-stat__value">' . self::e($overdue) . '</span></div>';
        $h[] = self::stat('Next date ahead', self::text($forced['next_due'] ?? null, 'none'));
        $h[] = '    </section>';

        // CREED's database rule, counted: four panels, and "missing" wears a badge while any app is missing the job.
        $mysql56 = self::arr($report['mysql56_ci'] ?? []);
        $h[] = '';
        $h[] = '    <section aria-labelledby="h-mysql56">';
        $h[] = '        <h2 id="h-mysql56">MySQL 5.6 CI job</h2>';
        $h[] = '        <p>CREED says migrations run in CI against MySQL 5.6.51, the version of Citadel\'s shared server; PROCTOR reports'
            . ' which apps hold the job. Unknown means no PROCTOR report, or one older than 0.15.1.</p>';
        foreach (self::MYSQL56_STATES as $key => $label) {
            $count = $mysql56[$key] ?? '';
            $h[] = '        <div class="wsu-stat"><span class="wsu-stat__label">'
                . ($key === self::MYSQL56_BAD && is_int($count) && $count > 0 ? self::badge($label) : self::e($label)) . '</span>'
                . '<span class="wsu-stat__value">' . self::e($count) . '</span></div>';
        }
        $h[] = '    </section>';

        foreach (self::VERSION_TABLES as $key => $title) {
            $rows = [];
            foreach (self::arr($report[$key] ?? []) as $row) {
                $row = self::arr($row);
                $rows[] = '<tr><th scope="row">' . self::e($row['version'] ?? '') . '</th>'
                    . '<td>' . self::e($row['apps'] ?? '') . '</td>'
                    . '<td>' . self::e(self::words($row['state'] ?? '')) . '</td>'
                    . '<td>' . self::e(self::text($row['security_until'] ?? null, 'not in the policy')) . '</td>'
                    . '<td>' . self::e(self::text($row['days_to_eol'] ?? null, 'unknown')) . '</td></tr>';
            }
            array_push($h, ...self::table(
                'h-' . $key,
                $title,
                'cap-' . $key,
                $title . ': apps by version, with the state the policy gives it today',
                ['Version', 'Apps', 'State', 'Security support ends', 'Days left'],
                $rows,
            ));
        }

        $rows = [];
        foreach (self::arr($report['drift'] ?? []) as $kind => $count) {
            $rows[] = '<tr><th scope="row">' . self::e(self::words($kind)) . '</th><td>' . self::e($count) . '</td></tr>';
        }
        array_push($h, ...self::table('h-drift', 'Drift', 'cap-drift', 'Apps showing each kind of drift', ['Kind', 'Apps'], $rows));

        $rows = [];
        foreach (self::arr($report['burn_down'] ?? []) as $point) {
            $point = self::arr($point);
            $rows[] = '<tr><th scope="row">' . self::e($point['date'] ?? '') . '</th>'
                . '<td>' . self::e($point['apps'] ?? '') . '</td>'
                . '<td>' . self::e($point['eol'] ?? '') . '</td>'
                . '<td>' . self::e($point['supported'] ?? '') . '</td>'
                . '<td>' . self::e($point['unknown'] ?? '') . '</td></tr>';
        }
        array_push($h, ...self::table(
            'h-burn',
            'EOL burn-down',
            'cap-burn',
            'Apps on an EOL PHP image, by scan day',
            ['Scan day', 'Apps', 'EOL', 'Supported', 'Unknown'],
            $rows,
        ));

        if ($internal) {
            $rows = [];
            foreach (self::arr($report['apps'] ?? []) as $app) {
                $app = self::arr($app);
                $drift = array_map(self::words(...), self::arr($app['drift'] ?? []));
                $auth = self::text($app['auth_package'] ?? null, 'none');
                $authVersion = $app['auth_version'] ?? null;
                if (is_scalar($authVersion) && (bool) $authVersion) {
                    $auth .= ' ' . $authVersion;
                }
                $rows[] = '<tr><th scope="row">' . self::e($app['id'] ?? '') . '</th>'
                    . '<td>' . self::e(self::text($app['php_image'] ?? null, 'unknown')) . '</td>'
                    // The runtime versions stay blank until the image source reports them.
                    . '<td>' . self::e($app['runtime_php'] ?? '') . '</td>'
                    . '<td>' . self::e($app['runtime_httpd'] ?? '') . '</td>'
                    . '<td>' . self::e(self::words($app['image_state'] ?? '')) . '</td>'
                    . '<td>' . self::upgradeDue($app['upgrade_due'] ?? null, $report['as_of'] ?? null) . '</td>'
                    . '<td>' . self::e(self::text($app['php_floor'] ?? null, 'unbounded')) . '</td>'
                    . '<td>' . self::e(self::text($app['laravel'] ?? null, 'none')) . '</td>'
                    . '<td>' . self::e($auth) . '</td>'
                    . '<td>' . self::e(self::text($app['proctor'] ?? null, 'no report')) . '</td>'
                    . '<td>' . self::mysql56State($app['mysql56_ci'] ?? null) . '</td>'
                    . '<td>' . self::e($drift === [] ? 'none' : implode(', ', $drift)) . '</td></tr>';
            }
            array_push($h, ...self::table(
                'h-apps',
                'Apps',
                'cap-apps',
                "Each app's versions, Citadel upgrade date, PROCTOR result, MySQL 5.6 CI job and drift",
                ['App', 'PHP image', 'PHP (running)', 'httpd (running)', 'Image state', 'Upgrade due', 'PHP floor', 'Laravel', 'Auth package', 'PROCTOR', 'MySQL 5.6 CI', 'Drift'],
                $rows,
            ));
        }

        return $h;
    }

    /**
     * One captioned table in its own keyboard-reachable scroll region (WCAG 1.4.10, 2.1.1).
     *
     * @param  list<string>  $columns
     * @param  list<string>  $rows  rendered rows, already escaped
     * @return list<string>
     */
    private static function table(string $headingId, string $title, string $captionId, string $caption, array $columns, array $rows): array
    {
        $head = '';
        foreach ($columns as $column) {
            $head .= '<th scope="col">' . self::e($column) . '</th>';
        }

        $h = [];
        $h[] = '';
        $h[] = '    <section aria-labelledby="' . $headingId . '">';
        $h[] = '        <h2 id="' . $headingId . '">' . self::e($title) . '</h2>';
        $h[] = '        <div class="wsu-table-wrap" tabindex="0" role="region" aria-labelledby="' . $captionId . '">';
        $h[] = '            <table class="wsu-table wsu-table--zebra">';
        $h[] = '                <caption id="' . $captionId . '">' . self::e($caption) . '</caption>';
        $h[] = '                <thead><tr>' . $head . '</tr></thead>';
        $h[] = '                <tbody>';
        foreach ($rows as $row) {
            $h[] = '                    ' . $row;
        }
        $h[] = '                </tbody>';
        $h[] = '            </table>';
        $h[] = '        </div>';
        $h[] = '    </section>';

        return $h;
    }

    private static function stat(string $label, mixed $value): string
    {
        return '        <div class="wsu-stat"><span class="wsu-stat__label">' . self::e($label) . '</span>'
            . '<span class="wsu-stat__value">' . self::e($value) . '</span></div>';
    }

    /** An app's MySQL 5.6 CI job state in words, escaped; "missing" in its badge. */
    private static function mysql56State(mixed $state): string
    {
        $words = self::words(self::text($state, 'unknown'));

        return $words === self::MYSQL56_BAD ? self::badge($words) : self::e($words);
    }

    /**
     * An app's forced upgrade date, escaped: "none" without one, and in its badge once the
     * report's day has reached it (the same rule as the report's overdue count).
     */
    private static function upgradeDue(mixed $due, mixed $asOf): string
    {
        if (! is_string($due) || $due === '') {
            return self::e('none');
        }

        return is_string($asOf) && $asOf !== '' && strcmp($due, $asOf) <= 0 ? self::badge($due . ', ' . self::DUE_COME) : self::e($due);
    }

    /** A state that needs a person: the word carries it, the badge's color only backs it up (WCAG 1.4.1). */
    private static function badge(string $words): string
    {
        return '<span class="wsu-badge wsu-badge--danger">' . self::e($words) . '</span>';
    }

    /** A state or drift kind in words: security_only reads "security only". */
    private static function words(mixed $value): string
    {
        return str_replace('_', ' ', self::text($value, ''));
    }

    /** The value as text, or the fallback when it is missing. */
    private static function text(mixed $value, string $fallback): string
    {
        return $value === null || ! is_scalar($value) ? $fallback : (string) $value;
    }

    /** Escaped the way Blade's {{ }} escapes, so a value from outside input stays text. */
    private static function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array<mixed> */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
