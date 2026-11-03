<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The rollcall-scan job in .gitlab-ci.yml, read as text: it must skip green
 * without a token, never re-export or print the token, and name all 13
 * projects with no hard-coded host.
 */
final class ScanJobTest extends TestCase
{
    private const PROJECTS = [
        'enterprise-apps/creed',
        'enterprise-apps/proctor',
        'enterprise-apps/preflight',
        'enterprise-apps/sesame',
        'enterprise-apps/helmet',
        'enterprise-apps/sourdough',
        'enterprise-apps/squire',
        'enterprise-apps/rollcall',
        'enterprise-apps/booster',
        'enterprise-apps/advisor',
        'enterprise-apps/drawbridge',
        'enterprise-apps/pinstripe',
        'enterprise-apps/weld',
    ];

    private function yaml(): string
    {
        $file = __DIR__ . '/../.gitlab-ci.yml';
        $text = file_get_contents($file);
        $this->assertNotFalse($text, '.gitlab-ci.yml must exist');

        return $text;
    }

    /** Everything from the rollcall-scan key to the next top-level key. */
    private function jobBlock(string $yaml): string
    {
        $this->assertSame(1, preg_match('/^rollcall-scan:\n((?:^[ \t].*\n?|\n)*)/m', $yaml, $m), 'the rollcall-scan job must exist');

        return $m[1];
    }

    public function test_the_job_runs_on_a_scan_schedule_or_by_hand_and_keeps_the_snapshot(): void
    {
        $yaml = $this->yaml();
        $block = $this->jobBlock($yaml);

        $this->assertStringContainsString('schedule', $block);
        $this->assertStringContainsString('ROLLCALL_JOB', $block);
        $this->assertStringContainsString('"web"', $block);
        $this->assertStringContainsString('ROLLCALL_GITLAB_TOKEN not set; skipping the Rollcall scan', $yaml);
        $this->assertStringContainsString('expire_in: 30 days', $block);
        $this->assertStringContainsString('var/snapshots', $block);
    }

    public function test_the_token_is_never_re_exported_or_printed(): void
    {
        $yaml = $this->yaml();

        // No job-level `ROLLCALL_GITLAB_TOKEN: ...` line (GitLab would pass the literal string).
        $this->assertDoesNotMatchRegularExpression('/^\s*ROLLCALL_GITLAB_TOKEN\s*:/m', $yaml);
        // No `VAR: $VAR` self-reference anywhere in the file.
        $this->assertDoesNotMatchRegularExpression('/^\s+([A-Z_]+):\s*\$\1\s*$/m', $yaml);

        foreach (explode("\n", $yaml) as $line) {
            if (! str_contains($line, 'ROLLCALL_GITLAB_TOKEN')) {
                continue;
            }
            if (str_contains($line, '-z')) {
                continue; // the empty check is the only line that may mention the token next to a printer
            }
            $this->assertFalse(
                preg_match('/\b(echo|printf|printenv|env)\b|set -x/', $line) === 1,
                'the token name must not sit on a printing line: ' . $line,
            );
        }
    }

    public function test_the_job_names_all_thirteen_projects_and_no_hardcoded_host(): void
    {
        $block = $this->jobBlock($this->yaml());

        foreach (self::PROJECTS as $path) {
            $this->assertStringContainsString($path, $block);
        }

        foreach (explode("\n", $block) as $line) {
            $this->assertDoesNotMatchRegularExpression('/https:\/\/[A-Za-z0-9.-]+/', $line, 'no hard-coded host in the scan job: ' . $line);
        }
    }
}
