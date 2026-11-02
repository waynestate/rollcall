<?php

declare(strict_types=1);

/**
 * Generate the front page supported-versions table from CREED's policy.
 *
 * The region between the versions-table markers in site/index.html and
 * site/index.md is generated data. Every row comes from the policy file, and
 * the support state is judged by src/Creed/Policy.php, so no support date is
 * typed by hand.
 *
 * The generation date is stored in the start marker, and --check without
 * --date reuses the stored date. The table lists each version with its
 * policy end date and its state as of that pinned date, so composer ci does
 * not go red just because a day passed. Regenerate with composer
 * site:versions after the bundled policy changes; that updates the date to
 * today.
 *
 * Usage:
 *   php tools/versions-table.php [--policy=<file>] [--date=YYYY-MM-DD] [--root=<dir>]
 *   php tools/versions-table.php --check [--policy=<file>] [--date=YYYY-MM-DD] [--root=<dir>]
 *
 * Without --policy the bundled copy in src/Creed/policy.json is used, and
 * without --date today (UTC) is. --check writes nothing and exits 1 when a
 * region differs from what the generator would write, printing which file is
 * stale. The Squire agent-ready kit strips HTML comments from the Markdown
 * twin, so site/index.md normally carries the table without markers; the
 * check accepts that and compares the table bytes instead.
 *
 * Exit codes: 0 written or current, 1 stale, 2 the tool itself could not run.
 */

use Waynestate\Rollcall\Creed\Policy;

const VERSIONS_TABLE_PAGES = ['site/index.html', 'site/index.md'];

const VERSIONS_TABLE_START = '/^[ \t]*<!-- versions-table:start(?: [^>]*)? -->[ \t]*$/';

const VERSIONS_TABLE_END = '/^[ \t]*<!-- versions-table:end -->[ \t]*$/';

const VERSIONS_TABLE_DATE = '/<!-- versions-table:start date="(\d{4}-\d{2}-\d{2})" -->/';

const VERSIONS_TABLE_INDENT = '        ';

const VERSIONS_TABLE_CAPTION = "Supported versions from CREED's policy.json: runtime or tool, version, support state and end of support";

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    versions_table_fail('vendor/autoload.php not found. Run composer install first.');
}
require $autoload;

$options = ['policy' => null, 'date' => null, 'root' => dirname(__DIR__), 'check' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--policy=')) {
        $options['policy'] = substr($arg, strlen('--policy='));
    } elseif (str_starts_with($arg, '--date=')) {
        $options['date'] = substr($arg, strlen('--date='));
    } elseif (str_starts_with($arg, '--root=')) {
        $options['root'] = substr($arg, strlen('--root='));
    } elseif ($arg === '--check') {
        $options['check'] = true;
    } else {
        versions_table_fail('Unknown argument: ' . $arg . "\n" . versions_table_usage());
    }
}

if (!is_dir($options['root'])) {
    versions_table_fail('Root directory not found: ' . $options['root']);
}
chdir($options['root']);

try {
    $policy = $options['policy'] === null ? Policy::bundled() : Policy::fromFile($options['policy']);
} catch (InvalidArgumentException $e) {
    versions_table_fail($e->getMessage());
}

if ($options['check'] && $options['date'] === null) {
    $date = versions_table_stored_date();
} else {
    $date = $options['date'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    versions_table_fail('--date expects YYYY-MM-DD, got: ' . $date);
}
try {
    $on = new DateTimeImmutable($date);
} catch (Exception) {
    versions_table_fail('Not a calendar date: ' . $date);
}

try {
    $rows = versions_table_rows($policy, $on);
} catch (InvalidArgumentException|RuntimeException $e) {
    versions_table_fail($e->getMessage());
}

$markdown = versions_table_markdown($rows);
$blocks = [
    'site/index.html' => versions_table_html($rows, $date),
    'site/index.md' => versions_table_start_marker($date) . "\n" . $markdown . "\n" . versions_table_end_marker(),
];
$markdownLines = explode("\n", $markdown);

$stale = [];
foreach (VERSIONS_TABLE_PAGES as $page) {
    if (!is_file($page)) {
        versions_table_fail('Page not found: ' . $page);
    }
    $lines = versions_table_lines((string) file_get_contents($page));
    [$start, $end] = versions_table_region($lines);

    if ($options['check']) {
        if ($start === null) {
            if ($page === 'site/index.md' && versions_table_contains($lines, $markdownLines)) {
                continue;
            }
            $stale[] = $page;
            continue;
        }
        if ($end === null || array_slice($lines, $start, $end - $start + 1) !== explode("\n", $blocks[$page])) {
            $stale[] = $page;
        }
        continue;
    }

    if ($start === null) {
        if ($page === 'site/index.md' && versions_table_contains($lines, $markdownLines)) {
            fwrite(STDOUT, "current {$page}\n");
            continue;
        }
        versions_table_fail("No versions-table markers in {$page}. Add the markers around the table and re-run.");
    }
    if ($end === null) {
        versions_table_fail("The versions-table start marker in {$page} has no end marker.");
    }
    $newLines = array_merge(array_slice($lines, 0, $start), explode("\n", $blocks[$page]), array_slice($lines, $end + 1));
    if (implode("\n", $newLines) === implode("\n", $lines)) {
        fwrite(STDOUT, "current {$page}\n");
        continue;
    }
    if (file_put_contents($page, implode("\n", $newLines)) === false) {
        versions_table_fail("Cannot write {$page}.");
    }
    fwrite(STDOUT, "wrote {$page}\n");
}

if ($options['check']) {
    if ($stale !== []) {
        foreach ($stale as $page) {
            fwrite(STDERR, "{$page}: versions table is stale. Run composer site:versions.\n");
        }
        exit(1);
    }
    fwrite(STDOUT, 'Versions table is current in ' . implode(' and ', VERSIONS_TABLE_PAGES) . '. Judged as of ' . $date . ".\n");
    exit(0);
}
exit(0);

/**
 * The generation date stored in the start marker of site/index.html, or a
 * tool failure when the marker or its date attribute is missing.
 */
function versions_table_stored_date(): string
{
    if (!is_file('site/index.html')) {
        versions_table_fail('Page not found: site/index.html');
    }
    $lines = versions_table_lines((string) file_get_contents('site/index.html'));
    [$start, ] = versions_table_region($lines);
    if ($start === null) {
        versions_table_fail('site/index.html has no versions-table markers. Run composer site:versions.');
    }
    if (!preg_match(VERSIONS_TABLE_DATE, $lines[$start], $match)) {
        versions_table_fail('The versions-table start marker in site/index.html has no date="YYYY-MM-DD" attribute. Run composer site:versions.');
    }

    return $match[1];
}

/**
 * One row per listed version and per tool: name, version, support state and
 * end of support. Components keep the policy's file order, versions sort
 * oldest first, and the composer minimum follows the components.
 *
 * @return list<array{0: string, 1: string, 2: string, 3: string}>
 */
function versions_table_rows(Policy $policy, DateTimeImmutable $on): array
{
    $rows = [];
    foreach ($policy->components() as $component) {
        foreach ($policy->versions($component) as $version) {
            $state = $policy->stateOf($component, $version, $on);
            $line = $policy->line($component, $version);
            $rows[] = [$component, $version, $state->value, $line['security_until'] ?? 'n/a'];
        }
    }
    foreach ($policy->tools() as $tool) {
        $rows[] = [$tool, $policy->toolMinimum($tool) ?? 'n/a', 'minimum', 'n/a'];
    }

    return $rows;
}

/**
 * The HTML region, markers included, indented to the chapter body of
 * site/index.html.
 *
 * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows
 */
function versions_table_html(array $rows, string $date): string
{
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $lines = [
        versions_table_start_marker($date),
        '<table class="versions">',
        '  <caption class="wsu-sr-only">' . $escape(VERSIONS_TABLE_CAPTION) . '</caption>',
        '  <thead>',
        '    <tr><th scope="col">Runtime or tool</th><th scope="col">Version</th><th scope="col">Support state</th><th scope="col">End of support</th></tr>',
        '  </thead>',
        '  <tbody>',
    ];
    foreach ($rows as [$name, $version, $state, $end]) {
        $lines[] = '    <tr><td>' . $escape($name) . '</td><td>' . $escape($version) . '</td><td>' . $escape($state) . '</td><td>' . $escape($end) . '</td></tr>';
    }
    $lines[] = '  </tbody>';
    $lines[] = '</table>';
    $lines[] = versions_table_end_marker();

    return implode("\n", array_map(static fn(string $line): string => VERSIONS_TABLE_INDENT . $line, $lines));
}

/**
 * The Markdown table, markers excluded. It mirrors byte for byte what the
 * Squire agent-ready kit writes into site/index.md when it converts the HTML
 * table, so the check finds the same bytes in the twin.
 *
 * @param list<array{0: string, 1: string, 2: string, 3: string}> $rows
 */
function versions_table_markdown(array $rows): string
{
    $pipe = static fn(string $text): string => str_replace('|', '\|', $text);
    $lines = [
        '**' . VERSIONS_TABLE_CAPTION . '**',
        '',
        '| Runtime or tool | Version | Support state | End of support |',
        '| --- | --- | --- | --- |',
    ];
    foreach ($rows as [$name, $version, $state, $end]) {
        $lines[] = '| ' . implode(' | ', array_map($pipe, [$name, $version, $state, $end])) . ' |';
    }

    return implode("\n", $lines);
}

function versions_table_start_marker(string $date): string
{
    return '<!-- versions-table:start date="' . $date . '" -->';
}

function versions_table_end_marker(): string
{
    return '<!-- versions-table:end -->';
}

/**
 * The line indexes of the start and end markers, or nulls when absent. An
 * open region (start without end) comes back as [start, null].
 *
 * @param list<string> $lines
 * @return array{0: int|null, 1: int|null}
 */
function versions_table_region(array $lines): array
{
    $start = null;
    $end = null;
    foreach ($lines as $i => $line) {
        if ($start === null && preg_match(VERSIONS_TABLE_START, $line)) {
            $start = $i;
            continue;
        }
        if ($start !== null && preg_match(VERSIONS_TABLE_END, $line)) {
            $end = $i;
            break;
        }
    }

    return [$start, $end];
}

/**
 * Whether $needle sits in $lines as a run of consecutive lines.
 *
 * @param list<string> $lines
 * @param list<string> $needle
 */
function versions_table_contains(array $lines, array $needle): bool
{
    $size = count($needle);
    for ($i = 0; $i + $size <= count($lines); $i++) {
        if (array_slice($lines, $i, $size) === $needle) {
            return true;
        }
    }

    return false;
}

/**
 * File contents as LF-terminated lines. The repository stores LF.
 *
 * @return list<string>
 */
function versions_table_lines(string $content): array
{
    return explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
}

function versions_table_usage(): string
{
    return "Usage:\n"
        . "  php tools/versions-table.php [--policy=<file>] [--date=YYYY-MM-DD] [--root=<dir>]\n"
        . "  php tools/versions-table.php --check [--policy=<file>] [--date=YYYY-MM-DD] [--root=<dir>]\n";
}

function versions_table_fail(string $message): never
{
    fwrite(STDERR, 'versions-table: ' . $message . "\n");
    exit(2);
}
