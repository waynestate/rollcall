<?php

declare(strict_types=1);

/**
 * Generate the front page quick-start printout's policy values from CREED's policy.
 *
 * The region between the quickstart-versions markers in site/index.html (and
 * the same lines in site/index.md) is generated data: the PHP image table and
 * the EOL burn-down, exactly as rollcall report prints them for the bundled
 * fixtures, ending with the pre's closing tag. Every state and end date comes
 * from the policy file, judged by src/Creed/Policy.php, so no support date is
 * typed by hand.
 *
 * The rest of the printout stays hand-written: the ROLLCALL version line is
 * the release process's, the scan dates are the fixture's, and the lede's PHP
 * floor is Rollcall's own requirement, not the policy's.
 *
 * The generation date is stored in the start marker, and --check without
 * --date reuses the stored date, so composer ci does not go red just because
 * a day passed. Regenerate with composer site:versions after the bundled
 * policy changes; that updates the date to today.
 *
 * Usage:
 *   php bin/quickstart-versions.php [--policy=<file>] [--date=YYYY-MM-DD] [--root=<dir>] [--fixtures=<dir>]
 *   php bin/quickstart-versions.php --check [--policy=<file>] [--date=YYYY-MM-DD] [--root=<dir>] [--fixtures=<dir>]
 *
 * Without --policy the bundled copy in src/Creed/policy.json is used, without
 * --date today (UTC), and without --fixtures <root>/tests/fixtures. The start
 * marker sits inside the pre tag, glued to the region's first line, and the
 * end marker sits right after the pre's closing tag: a comment on its own
 * line would render as a blank line, and a marker inside the pre breaks the
 * agent-ready converter's code fence. The region between the markers ends
 * with </pre>, which the generator writes back unchanged. The Squire
 * agent-ready kit strips HTML comments from the Markdown twin, so
 * site/index.md carries the region without markers; the check compares the
 * region bytes instead.
 *
 * Exit codes: 0 written or current, 1 stale, 2 the tool itself could not run.
 */

use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Report\TextRenderer;
use Waynestate\Rollcall\Scan\Scanner;
use Waynestate\Rollcall\Source\DirectoryProctorReportSource;
use Waynestate\Rollcall\Source\DirectoryRepositorySource;
use Waynestate\Rollcall\Source\JsonFileImageSource;

const QUICKSTART_PAGES = ['site/index.html', 'site/index.md'];

const QUICKSTART_START = '/<!-- quickstart-versions:start date="(\d{4}-\d{2}-\d{2})" -->/';

const QUICKSTART_END = '/<!-- quickstart-versions:end -->/';

const QUICKSTART_PHP_TITLE = 'PHP image';

const QUICKSTART_FLOOR_TITLE = 'Declared PHP floor';

const QUICKSTART_BURN_TITLE = 'EOL burn-down (state on each scan day)';

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    quickstart_fail('vendor/autoload.php not found. Run composer install first.');
}
require $autoload;

$options = ['policy' => null, 'date' => null, 'root' => dirname(__DIR__), 'fixtures' => null, 'check' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--policy=')) {
        $options['policy'] = substr($arg, strlen('--policy='));
    } elseif (str_starts_with($arg, '--date=')) {
        $options['date'] = substr($arg, strlen('--date='));
    } elseif (str_starts_with($arg, '--root=')) {
        $options['root'] = substr($arg, strlen('--root='));
    } elseif (str_starts_with($arg, '--fixtures=')) {
        $options['fixtures'] = substr($arg, strlen('--fixtures='));
    } elseif ($arg === '--check') {
        $options['check'] = true;
    } else {
        quickstart_fail('Unknown argument: ' . $arg . "\n" . quickstart_usage());
    }
}

if (!is_dir($options['root'])) {
    quickstart_fail('Root directory not found: ' . $options['root']);
}
chdir($options['root']);

$fixtures = $options['fixtures'] ?? ($options['root'] . '/tests/fixtures');
if (!is_dir($fixtures)) {
    quickstart_fail('Fixtures directory not found: ' . $fixtures);
}

try {
    $policy = $options['policy'] === null ? Policy::bundled() : Policy::fromFile($options['policy']);
} catch (InvalidArgumentException $e) {
    quickstart_fail($e->getMessage());
}

if ($options['check'] && $options['date'] === null) {
    $date = quickstart_stored_date();
} else {
    $date = $options['date'] ?? (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    quickstart_fail('--date expects YYYY-MM-DD, got: ' . $date);
}

try {
    $region = quickstart_region($policy, $date, $fixtures);
} catch (Throwable $e) {
    quickstart_fail($e->getMessage());
}
$regionLines = explode("\n", $region);
if (count($regionLines) < 4) {
    quickstart_fail('The generated region is empty; the report layout changed.');
}

$stale = [];
foreach (QUICKSTART_PAGES as $page) {
    if (!is_file($page)) {
        quickstart_fail('Page not found: ' . $page);
    }
    $content = (string) file_get_contents($page);

    if ($page === 'site/index.md') {
        // The agent-ready kit strips the markers from the twin, so the region
        // sits there bare; compare its bytes instead.
        $lines = quickstart_lines($content);
        if (quickstart_contains($lines, $regionLines)) {
            if (!$options['check']) {
                fwrite(STDOUT, "current {$page}\n");
            }
            continue;
        }
        if ($options['check']) {
            $stale[] = $page;
            continue;
        }
        [$start, $end] = quickstart_locate($lines);
        if ($start === null) {
            quickstart_fail("Cannot find the quick-start region in {$page}.");
        }
        $newContent = implode("\n", array_merge(array_slice($lines, 0, $start), $regionLines, array_slice($lines, $end + 1)));
        if (file_put_contents($page, $newContent) === false) {
            quickstart_fail("Cannot write {$page}.");
        }
        fwrite(STDOUT, "wrote {$page}\n");
        continue;
    }

    [$markerStart, $regionStart, $regionEnd] = quickstart_marker_offsets($content);
    if ($regionStart !== null) {
        $expected = $region . '</pre>';
        $old = substr($content, $regionStart, $regionEnd - $regionStart);
        if ($old === $expected) {
            if (!$options['check']) {
                fwrite(STDOUT, "current {$page}\n");
            }
            continue;
        }
        if ($options['check']) {
            $stale[] = $page;
            continue;
        }
        $writeEnd = $regionEnd;
        if (str_starts_with(substr($content, $regionEnd), '</pre>')) {
            // Older layout: the end marker sat inside the pre, so the closing
            // tag follows it; fold it into the rewrite to heal the page.
            $writeEnd += strlen('</pre>');
        }
        // The rewrite starts at the start marker so its stored date moves
        // with the region it heads.
        $newContent = substr($content, 0, $markerStart) . quickstart_start_marker($date) . $expected . substr($content, $writeEnd);
        if (file_put_contents($page, $newContent) === false) {
            quickstart_fail("Cannot write {$page}.");
        }
        fwrite(STDOUT, "wrote {$page}\n");
        continue;
    }

    if (preg_match(QUICKSTART_START, $content)) {
        quickstart_fail('The quickstart-versions start marker in site/index.html has no end marker.');
    }
    if ($options['check']) {
        $stale[] = $page;
        continue;
    }
    $lines = quickstart_lines($content);
    [$start, $end] = quickstart_locate($lines);
    if ($start === null) {
        quickstart_fail("Cannot find the quick-start region in {$page}. Add the quickstart-versions markers around it and re-run.");
    }
    if (!str_ends_with($lines[$end], '</pre>')) {
        quickstart_fail('The quick-start region in site/index.html does not end with </pre>; the page layout changed.');
    }
    $first = $regionLines[0];
    $last = $regionLines[count($regionLines) - 1];
    $middle = array_slice($regionLines, 1, count($regionLines) - 2);
    $newContent = implode("\n", array_merge(
        array_slice($lines, 0, $start),
        [quickstart_start_marker($date) . $first],
        $middle,
        [$last . '</pre>' . quickstart_end_marker()],
        array_slice($lines, $end + 1),
    ));
    if (file_put_contents($page, $newContent) === false) {
        quickstart_fail("Cannot write {$page}.");
    }
    fwrite(STDOUT, "wrote {$page}\n");
}

if ($options['check']) {
    if ($stale !== []) {
        foreach ($stale as $page) {
            fwrite(STDERR, "{$page}: quick-start versions are stale. Run composer site:versions.\n");
        }
        exit(1);
    }
    fwrite(STDOUT, 'Quick-start versions are current in ' . implode(' and ', QUICKSTART_PAGES) . '. Judged as of ' . $date . ".\n");
}
exit(0);

/**
 * The byte offsets of the marked region in the HTML page: [markerStart,
 * regionStart, regionEnd), where regionStart..regionEnd is the text between
 * the markers and markerStart is the beginning of the start marker, or nulls
 * when a marker is missing.
 *
 * @return array{0: int|null, 1: int|null, 2: int|null}
 */
function quickstart_marker_offsets(string $content): array
{
    if (!preg_match(QUICKSTART_START, $content, $sm, PREG_OFFSET_CAPTURE)) {
        return [null, null, null];
    }
    if (!preg_match(QUICKSTART_END, $content, $em, PREG_OFFSET_CAPTURE)) {
        return [null, null, null];
    }
    $markerStart = $sm[0][1];
    $regionStart = $sm[0][1] + strlen($sm[0][0]);
    $regionEnd = $em[0][1];
    if ($regionEnd < $regionStart) {
        return [null, null, null];
    }

    return [$markerStart, $regionStart, $regionEnd];
}

/**
 * The generation date stored in the start marker of site/index.html, or a
 * tool failure when the marker or its date attribute is missing.
 */
function quickstart_stored_date(): string
{
    if (!is_file('site/index.html')) {
        quickstart_fail('Page not found: site/index.html');
    }
    $content = (string) file_get_contents('site/index.html');
    if (!preg_match(QUICKSTART_START, $content, $match)) {
        quickstart_fail('site/index.html has no quickstart-versions markers. Run composer site:versions.');
    }

    return $match[1];
}

/**
 * The generated region: the PHP image table and the EOL burn-down, exactly as
 * rollcall report prints them for the bundled fixtures on the given day. The
 * sections between them (floor, Laravel, auth, PROCTOR, drift, progress) stay
 * off the front page, so they are cut out here.
 */
function quickstart_region(Policy $policy, string $date, string $fixtures): string
{
    $clock = new FixedClock($date);
    $scanner = new Scanner(
        new DirectoryRepositorySource($fixtures . '/repos'),
        JsonFileImageSource::fromFile($fixtures . '/images.json'),
        new DirectoryProctorReportSource($fixtures . '/proctor'),
        $clock,
        new ComposerReader(),
    );
    $snapshot = $scanner->scan();
    $report = (new ReportBuilder($policy))->build($snapshot, [$snapshot], $clock->now(), false);
    $lines = explode("\n", (new TextRenderer())->render($report));

    $php = array_search(QUICKSTART_PHP_TITLE, $lines, true);
    $floor = array_search(QUICKSTART_FLOOR_TITLE, $lines, true);
    $burn = array_search(QUICKSTART_BURN_TITLE, $lines, true);
    if ($php === false || $floor === false || $burn === false || $php >= $floor || $floor >= $burn) {
        throw new RuntimeException('The report layout changed: the section titles no longer match bin/quickstart-versions.php.');
    }
    $image = array_slice($lines, $php, $floor - $php);
    while ($image !== [] && end($image) === '') {
        array_pop($image);
    }
    $burnDown = array_slice($lines, $burn);
    while ($burnDown !== [] && end($burnDown) === '') {
        array_pop($burnDown);
    }

    return implode("\n", $image) . "\n\n" . implode("\n", $burnDown);
}

/**
 * The line indexes [start, end] of the unmarked region in a page: from the
 * bare PHP image title to the last EOL burn-down row, or nulls when the
 * anchors do not all sit where the front page puts them.
 *
 * @param list<string> $lines
 * @return array{0: int|null, 1: int|null}
 */
function quickstart_locate(array $lines): array
{
    $start = null;
    foreach ($lines as $i => $line) {
        if ($line === QUICKSTART_PHP_TITLE) {
            $start = $i;
            break;
        }
    }
    if ($start === null) {
        return [null, null];
    }
    $burn = null;
    for ($i = $start + 1; $i < count($lines); $i++) {
        if ($lines[$i] === QUICKSTART_BURN_TITLE) {
            $burn = $i;
            break;
        }
        if ($lines[$i] === QUICKSTART_FLOOR_TITLE) {
            return [null, null];
        }
    }
    if ($burn === null) {
        return [null, null];
    }
    $end = null;
    for ($i = $burn + 2; $i < count($lines); $i++) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ \t]/', $lines[$i])) {
            $end = $i;
            continue;
        }
        break;
    }
    if ($end === null) {
        return [null, null];
    }

    return [$start, $end];
}

/**
 * Whether $needle sits in $lines as a run of consecutive lines.
 *
 * @param list<string> $lines
 * @param list<string> $needle
 */
function quickstart_contains(array $lines, array $needle): bool
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
function quickstart_lines(string $content): array
{
    return explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
}

function quickstart_start_marker(string $date): string
{
    return '<!-- quickstart-versions:start date="' . $date . '" -->';
}

function quickstart_end_marker(): string
{
    return '<!-- quickstart-versions:end -->';
}

function quickstart_usage(): string
{
    return "Usage:\n"
        . "  php bin/quickstart-versions.php [--policy=<file>] [--date=YYYY-MM-DD] [--root=<dir>] [--fixtures=<dir>]\n"
        . "  php bin/quickstart-versions.php --check [--policy=<file>] [--date=YYYY-MM-DD] [--root=<dir>] [--fixtures=<dir>]\n";
}

function quickstart_fail(string $message): never
{
    fwrite(STDERR, 'quickstart-versions: ' . $message . "\n");
    exit(2);
}
