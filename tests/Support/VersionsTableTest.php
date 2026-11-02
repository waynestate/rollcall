<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The front page versions table generator (tools/versions-table.php), driven
 * as a process against a throwaway root so the real site pages are never
 * touched. The policy comes from tests/fixtures/policy.json and the date is
 * pinned, so the expected rows are stable.
 */
final class VersionsTableTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    private const DATE = '2026-11-02';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rollcall-versions-' . bin2hex(random_bytes(6));
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

    public function test_write_generates_both_regions_from_the_fixture_policy(): void
    {
        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--date=' . self::DATE, '--root=' . $this->root]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('wrote site/index.html', $out);
        $this->assertStringContainsString('wrote site/index.md', $out);

        $html = (string) file_get_contents($this->root . '/site/index.html');
        $this->assertStringContainsString('<!-- versions-table:start date="' . self::DATE . '" -->', $html);
        $this->assertStringContainsString('<!-- versions-table:end -->', $html);
        $this->assertStringContainsString('<tr><td>php</td><td>8.1</td><td>eol_grace_expired</td><td>2025-12-31</td></tr>', $html);
        $this->assertStringContainsString('<tr><td>php</td><td>8.4</td><td>active</td><td>2028-12-31</td></tr>', $html);
        $this->assertStringContainsString('<tr><td>composer</td><td>2.8.0</td><td>minimum</td><td>n/a</td></tr>', $html);
        // Every listed version plus the composer minimum, and nothing else.
        $this->assertSame(20, preg_match_all('/<tr><td>/', $html), $html);

        $md = (string) file_get_contents($this->root . '/site/index.md');
        $this->assertStringContainsString("**Supported versions from CREED's policy.json: runtime or tool, version, support state and end of support**", $md);
        $this->assertStringContainsString('| Runtime or tool | Version | Support state | End of support |', $md);
        $this->assertStringContainsString('| --- | --- | --- | --- |', $md);
        $this->assertStringContainsString('| php | 8.1 | eol_grace_expired | 2025-12-31 |', $md);
        $this->assertStringContainsString('| composer | 2.8.0 | minimum | n/a |', $md);
        // The fixture lists no inertia versions, so none may appear.
        $this->assertStringNotContainsString('inertia', $md);
    }

    public function test_check_passes_right_after_a_write_and_reuses_the_stored_date(): void
    {
        $this->write();
        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--check', '--root=' . $this->root]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Versions table is current', $out);
        $this->assertStringContainsString('Judged as of ' . self::DATE, $out);
    }

    public function test_check_fails_and_names_the_file_when_a_row_drifts(): void
    {
        $this->write();
        $path = $this->root . '/site/index.html';
        $html = (string) file_get_contents($path);
        $tampered = str_replace(
            '<tr><td>php</td><td>8.1</td><td>eol_grace_expired</td><td>2025-12-31</td></tr>',
            '<tr><td>php</td><td>8.1</td><td>active</td><td>2025-12-31</td></tr>',
            $html,
            $count,
        );
        $this->assertSame(1, $count, 'the test expects exactly one php 8.1 row to tamper with');
        file_put_contents($path, $tampered);

        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--check', '--root=' . $this->root]);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('site/index.html: versions table is stale', $out);
        $this->assertStringContainsString('composer site:versions', $out);
        $this->assertStringNotContainsString('site/index.md', $out, 'only the tampered file may be reported stale');
    }

    public function test_check_accepts_the_markdown_twin_after_the_kit_strips_the_markers(): void
    {
        $this->write();
        $path = $this->root . '/site/index.md';
        $md = (string) file_get_contents($path);
        $stripped = str_replace(["<!-- versions-table:start date=\"" . self::DATE . "\" -->\n", "<!-- versions-table:end -->\n"], '', $md);
        $this->assertNotSame($md, $stripped, 'the test expects the twin to carry markers before stripping');
        file_put_contents($path, $stripped);

        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--check', '--root=' . $this->root]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Versions table is current', $out);
    }

    public function test_an_older_date_moves_versions_out_of_the_back_row(): void
    {
        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--date=2020-01-01', '--root=' . $this->root]);
        $this->assertSame(0, $code, $out);

        $html = (string) file_get_contents($this->root . '/site/index.html');
        $this->assertStringContainsString('<!-- versions-table:start date="2020-01-01" -->', $html);
        // Still in support on 2020-01-01, long expired on the default date.
        $this->assertStringContainsString('<tr><td>mysql</td><td>5.6</td><td>security</td><td>2021-02-28</td></tr>', $html);
        $this->assertStringNotContainsString('<tr><td>mysql</td><td>5.6</td><td>eol_grace_expired</td>', $html);
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
        $this->assertStringContainsString('versions-table:', $out);
    }

    /**
     * Run the tool as a process and return [exit code, combined output].
     *
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runTool(array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::toolPath()) . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        exec($command, $output, $code);

        return [$code, implode("\n", $output)];
    }

    private static function toolPath(): string
    {
        return dirname(__DIR__, 2) . '/tools/versions-table.php';
    }

    /**
     * Generate both regions from the fixture policy at the pinned date.
     */
    private function write(): void
    {
        [$code, $out] = $this->runTool(['--policy=' . self::FIXTURES . '/policy.json', '--date=' . self::DATE, '--root=' . $this->root]);
        $this->assertSame(0, $code, $out);
    }

    private static function htmlShell(): string
    {
        return <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>fixture</title>
</head>
<body>
  <div class="chapter__body">
        <!-- versions-table:start -->
        <!-- versions-table:end -->
  </div>
</body>
</html>
HTML;
    }

    private static function mdShell(): string
    {
        return <<<MD
# Fixture page

<!-- versions-table:start -->
<!-- versions-table:end -->

The end.
MD;
    }
}
