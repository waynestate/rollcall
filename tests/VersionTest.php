<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Report\ReportBuilder;

/**
 * The version guard: the constant and the newest release heading in
 * CHANGELOG.md must agree, so a report never prints a stale version.
 */
final class VersionTest extends TestCase
{
    public function test_the_version_constant_matches_the_latest_changelog_release(): void
    {
        $changelog = (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
        preg_match('/^## \[?(\d+\.\d+\.\d+)\]?/m', $changelog, $matches);

        $this->assertArrayHasKey(1, $matches, 'CHANGELOG.md has no released version heading');
        $this->assertSame(ReportBuilder::VERSION, $matches[1]);
    }
}
