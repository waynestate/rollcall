<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Support;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Report\TextRenderer;
use Waynestate\Rollcall\Scan\Scanner;
use Waynestate\Rollcall\Source\DirectoryProctorReportSource;
use Waynestate\Rollcall\Source\DirectoryRepositorySource;
use Waynestate\Rollcall\Source\JsonFileImageSource;

/**
 * The front page quick-start versions generator (bin/quickstart-versions.php),
 * driven as a process against a throwaway root so the real site pages are
 * never touched. The policy comes from tests/fixtures/policy.json and the
 * date is pinned, so the expected region is stable. The shells seed the
 * region with wrong values, so a passing write proves the tool repairs a
 * hand-typed page, and the check right after proves byte equality.
 */
final class QuickstartVersionsTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    private const DATE = '2026-11-02';

    /** The seeded burn-down row, which no fixture scan at any date produces. */
    private const STALE_ROW = '2026-10-28  4     0    3          1';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rollcall-quickstart-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/site', 0777, true);
        file_put_contents($this->root . '/site/index.html', self::htmlShell());
        file_put_contents($this->root . '/site/index.md', self::mdShell());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/site/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root . '/site');
        rmdir($this->root);
    }

    public function test_write_repairs_the_hand_typed_region_in_both_pages(): void
    {
        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--date=' . self::DATE, '--root=' . $this->root]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('wrote site/index.html', $out);
        $this->assertStringContainsString('wrote site/index.md', $out);

        $html = (string) file_get_contents($this->root . '/site/index.html');
        // The start marker glues to the region's first line inside the pre;
        // the end marker sits right after the closing tag, so the text before
        // </pre> is the generated region alone, byte for byte.
        $this->assertStringContainsString('<!-- quickstart-versions:start date="' . self::DATE . '" -->PHP image', $html);
        $this->assertSame(self::expectedRegion() . '</pre>', self::regionOf($html));
        // The seeded wrong values are gone, and the middle sections stay off the front page.
        $this->assertStringNotContainsString(self::STALE_ROW, $html);
        $this->assertStringNotContainsString('Declared PHP floor', $html);

        $md = (string) file_get_contents($this->root . '/site/index.md');
        $this->assertStringNotContainsString('quickstart-versions', $md, 'the twin carries no markers');
        $this->assertStringNotContainsString(self::STALE_ROW, $md);
        $this->assertStringContainsString('PHP image', $md);
        $this->assertStringContainsString('EOL burn-down (state on each scan day)', $md);
    }

    public function test_check_passes_right_after_a_write_and_reuses_the_stored_date(): void
    {
        $this->write();
        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--check', '--root=' . $this->root]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Quick-start versions are current', $out);
        $this->assertStringContainsString('Judged as of ' . self::DATE, $out);
    }

    public function test_check_fails_and_names_the_file_when_the_region_drifts(): void
    {
        $this->write();
        $path = $this->root . '/site/index.html';
        $html = (string) file_get_contents($path);
        $tampered = str_replace('-->PHP image', '-->PHP images', $html, $count);
        $this->assertSame(1, $count, 'the test expects exactly one glued title to tamper with');
        file_put_contents($path, $tampered);

        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--check', '--root=' . $this->root]);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('site/index.html: quick-start versions are stale', $out);
        $this->assertStringContainsString('composer site:versions', $out);
        $this->assertStringNotContainsString('site/index.md', $out, 'only the tampered file may be reported stale');
    }

    public function test_write_repairs_a_tampered_region_between_markers(): void
    {
        $this->write();
        $path = $this->root . '/site/index.html';
        $html = (string) file_get_contents($path);
        $tampered = str_replace('-->PHP image', '-->PHP images', $html, $count);
        $this->assertSame(1, $count, 'the test expects exactly one glued title to tamper with');
        file_put_contents($path, $tampered);

        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--date=' . self::DATE, '--root=' . $this->root]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('wrote site/index.html', $out);

        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--check', '--root=' . $this->root]);
        $this->assertSame(0, $code, $out);
    }

    public function test_an_older_date_changes_the_region(): void
    {
        $this->write();
        $first = self::regionOf((string) file_get_contents($this->root . '/site/index.html'));
        $this->assertNotSame('', $first, 'the written page must carry the marked region');

        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--date=2020-01-01', '--root=' . $this->root]);
        $this->assertSame(0, $code, $out);

        $second = self::regionOf((string) file_get_contents($this->root . '/site/index.html'));
        $this->assertNotSame($first, $second, 'the states and days must move with the judged date');

        // The rewrite moves the stored date with the region, so a check that
        // reuses the stored date passes again.
        $html = (string) file_get_contents($this->root . '/site/index.html');
        $this->assertStringContainsString('<!-- quickstart-versions:start date="2020-01-01" -->', $html);
        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--check', '--root=' . $this->root]);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Judged as of 2020-01-01', $out);
    }

    public function test_an_unknown_argument_is_a_tool_error(): void
    {
        [$code, $out] = $this->runTool(['--bogus', '--root=' . $this->root]);

        $this->assertSame(2, $code, $out);
        $this->assertStringContainsString('Unknown argument: --bogus', $out);
    }

    public function test_a_missing_policy_file_is_a_tool_error(): void
    {
        [$code, $out] = $this->runTool(['--policy=' . $this->root . '/nope.json', '--root=' . $this->root]);

        $this->assertSame(2, $code, $out);
        $this->assertStringContainsString('quickstart-versions:', $out);
    }

    /**
     * Run the tool as a process and return [exit code, combined output].
     *
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runTool(array $args): array
    {
        // The throwaway root has no fixtures of its own; scan the real ones.
        $args[] = '--fixtures=' . self::FIXTURES;
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::toolPath()) . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($command, $output, $code);

        return [$code, implode("\n", $output)];
    }

    private static function toolPath(): string
    {
        return dirname(__DIR__, 2) . '/bin/quickstart-versions.php';
    }

    /**
     * Generate the region from the fixture policy at the pinned date.
     */
    private function write(): void
    {
        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--date=' . self::DATE, '--root=' . $this->root]);
        $this->assertSame(0, $code, $out);
    }

    /**
     * The text between the quickstart-versions markers, or '' when unmarked.
     * The match only succeeds when the end marker follows the captured text
     * directly, so a non-empty result also proves the marker placement.
     */
    private static function regionOf(string $html): string
    {
        if (preg_match('/<!-- quickstart-versions:start date="\d{4}-\d{2}-\d{2}" -->(.*)<!-- quickstart-versions:end -->/s', $html, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    /**
     * The region the pipeline prints for the fixture fleet at the pinned
     * date, computed here straight from the policy instead of from the tool's
     * own output, so the test compares bytes against the policy.
     */
    private static function expectedRegion(): string
    {
        $clock = new FixedClock(self::DATE);
        $scanner = new Scanner(
            new DirectoryRepositorySource(self::FIXTURES . '/repos'),
            JsonFileImageSource::fromFile(self::FIXTURES . '/images.json'),
            new DirectoryProctorReportSource(self::FIXTURES . '/proctor'),
            $clock,
            new ComposerReader(),
        );
        $snapshot = $scanner->scan();
        $report = (new ReportBuilder(Policy::fromFile(self::FIXTURES . '/policy.json')))
            ->build($snapshot, [$snapshot], $clock->now(), false);
        $lines = explode("\n", (new TextRenderer())->render($report));

        $php = array_search('PHP image', $lines, true);
        $floor = array_search('Declared PHP floor', $lines, true);
        $burn = array_search('EOL burn-down (state on each scan day)', $lines, true);
        if ($php === false || $floor === false || $burn === false || $php >= $floor || $floor >= $burn) {
            self::fail('The fixture report layout no longer matches the section titles.');
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

    private static function htmlShell(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>fixture</title>
</head>
<body>
  <section class="chapter">
    <div class="printout">
      <p class="printout__head">What comes back</p>
<pre class="printout__body" tabindex="0">Present: 4 apps. Snapshot 2026-10-28 saved to var/snapshots.

ROLLCALL 1.0.0 | scan 2026-10-28 | as of 2026-10-28 | public
Apps present: 4 (scan notes: 0)

PHP image
VERSION  APPS  STATE          SECURITY UNTIL  DAYS TO EOL
8.1      1     active         2025-12-31       120
8.3      1     active         2027-12-31       430
8.4      1     active         2028-12-31       796
unknown  1     unknown        -               -

EOL burn-down (state on each scan day)
DATE        APPS  EOL  SUPPORTED  UNKNOWN
2026-10-28  4     0    3          1</pre>
    </div>
  </section>
</body>
</html>
HTML;
    }

    private static function mdShell(): string
    {
        return <<<'MD'
# Fixture page

What comes back

```
Present: 4 apps. Snapshot 2026-10-28 saved to var/snapshots.

ROLLCALL 1.0.0 | scan 2026-10-28 | as of 2026-10-28 | public
Apps present: 4 (scan notes: 0)

PHP image
VERSION  APPS  STATE          SECURITY UNTIL  DAYS TO EOL
8.1      1     active         2025-12-31       120
8.3      1     active         2027-12-31       430
8.4      1     active         2028-12-31       796
unknown  1     unknown        -               -

EOL burn-down (state on each scan day)
DATE        APPS  EOL  SUPPORTED  UNKNOWN
2026-10-28  4     0    3          1
```

The end.
MD;
    }
}
