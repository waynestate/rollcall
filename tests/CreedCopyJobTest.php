<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The creed-copy-refresh job in .gitlab-ci.yml and tools/creed-copy-refresh.sh,
 * read as text: the job runs only on its schedule or by hand on the default
 * branch, the script proves the copy (creed-copy:check, then composer ci)
 * before it commits and pushes, it pushes to the default branch without force,
 * and no line prints the token URL. Nobody calls roll for CREED by hand anymore.
 */
final class CreedCopyJobTest extends TestCase
{
    private const SCRIPT = 'tools/creed-copy-refresh.sh';

    private function file(string $path): string
    {
        $text = file_get_contents(__DIR__ . '/../' . $path);
        $this->assertNotFalse($text, $path . ' must exist');

        return $text;
    }

    /** Everything from a top-level job key to the next top-level key. */
    private function jobBlock(string $job): string
    {
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote($job, '/') . ':\n((?:^[ \t].*\n?|\n)*)/m', $this->file('.gitlab-ci.yml'), $m),
            'the ' . $job . ' job must exist',
        );

        return $m[1];
    }

    /**
     * The script's command lines: trimmed, without blank lines and comments.
     *
     * @return list<string>
     */
    private function commands(): array
    {
        $commands = [];
        foreach (explode("\n", $this->file(self::SCRIPT)) as $line) {
            $code = trim($line);
            if ($code !== '' && ! str_starts_with($code, '#')) {
                $commands[] = $code;
            }
        }

        return $commands;
    }

    /** Index of the first command line matching the pattern. */
    private function firstCommand(string $pattern): int
    {
        foreach ($this->commands() as $i => $code) {
            if (preg_match($pattern, $code) === 1) {
                return $i;
            }
        }
        $this->fail('no command line in ' . self::SCRIPT . ' matches ' . $pattern);
    }

    public function test_the_job_runs_on_its_schedule_or_by_hand_and_only_on_the_default_branch(): void
    {
        $block = $this->jobBlock('creed-copy-refresh');

        $this->assertStringContainsString(
            "- if: '\$CI_PIPELINE_SOURCE == \"schedule\" && \$ROLLCALL_JOB == \"creed-copy\" && \$CI_COMMIT_BRANCH == \$CI_DEFAULT_BRANCH'",
            $block,
        );
        $this->assertStringContainsString(
            "- if: '\$CI_PIPELINE_SOURCE == \"web\" && \$CI_COMMIT_BRANCH == \$CI_DEFAULT_BRANCH'",
            $block,
        );
        $this->assertSame(2, preg_match_all('/^\s*- if:/m', $block), 'exactly the schedule rule and the web rule');
        foreach (preg_split('/\n/', $block) ?: [] as $line) {
            if (str_contains($line, '- if:')) {
                $this->assertStringContainsString('$CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH', $line, 'every rule keeps the default-branch guard');
            }
        }
    }

    public function test_the_job_uses_the_cached_composer_image_full_history_and_the_script(): void
    {
        $block = $this->jobBlock('creed-copy-refresh');

        $this->assertStringContainsString('extends: .composer-cache', $block);
        $this->assertStringContainsString('image: ${CI_DEPENDENCY_PROXY_GROUP_IMAGE_PREFIX}/composer:2.10.3', $block);
        $this->assertStringContainsString('GIT_DEPTH: "0"', $block);
        $this->assertStringContainsString('- sh ' . self::SCRIPT, $block);
    }

    public function test_the_scan_job_does_not_fire_on_the_creed_copy_schedule(): void
    {
        $this->assertStringContainsString('$ROLLCALL_JOB == "scan"', $this->jobBlock('rollcall-scan'));
    }

    public function test_the_script_proves_the_copy_before_it_commits_and_pushes(): void
    {
        $this->assertSame(0, $this->firstCommand('/^set -eu$/'), 'set -eu comes first');

        $clone = $this->firstCommand('/^if ! git clone -q /');
        $export = $this->firstCommand('/^php "\$TMP\/creed\/bin\/export\.php" --namespace="Waynestate\\\\Rollcall\\\\Creed" --to=src\/Creed$/');
        $check = $this->firstCommand('/^CREED_PATH="\$TMP\/creed" composer creed-copy:check$/');
        $ci = $this->firstCommand('/^composer ci$/');
        $commit = $this->firstCommand('/^git .*\bcommit\b/');
        $push = $this->firstCommand('/git push\b/');

        $this->assertLessThan($export, $clone);
        $this->assertLessThan($check, $export);
        $this->assertLessThan($ci, $check);
        $this->assertLessThan($commit, $ci);
        $this->assertLessThan($push, $commit);
    }

    public function test_it_pushes_to_the_default_branch_without_force(): void
    {
        $push = $this->commands()[$this->firstCommand('/git push\b/')];
        $this->assertStringContainsString('"HEAD:refs/heads/${CI_DEFAULT_BRANCH}"', $push);

        foreach ($this->commands() as $code) {
            $this->assertDoesNotMatchRegularExpression('/--force|\s-f\b|"\+HEAD|\s\+HEAD/', $code, 'no force push: ' . $code);
        }
    }

    public function test_it_stages_named_paths_and_never_everything(): void
    {
        $add = $this->commands()[$this->firstCommand('/^git add\b/')];
        $this->assertStringStartsWith('git add -- src/Creed ', $add);

        foreach ($this->commands() as $code) {
            $this->assertDoesNotMatchRegularExpression('/git add (-A|--all|\.)(\s|$)|git commit .*-a\b/', $code, 'stage by name only: ' . $code);
        }
    }

    public function test_no_line_prints_the_token_url(): void
    {
        $lines = array_merge(explode("\n", $this->file(self::SCRIPT)), explode("\n", $this->jobBlock('creed-copy-refresh')));

        foreach ($lines as $line) {
            $this->assertStringNotContainsString('set -x', $line, 'no shell tracing: it would print the token URL');
            if (! str_contains($line, 'CI_JOB_TOKEN') && ! str_contains($line, 'gitlab-ci-token:$')) {
                continue;
            }
            $this->assertFalse(
                preg_match('/\b(echo|printf|printenv|env)\b/', $line) === 1,
                'the token must not sit on a printing line: ' . $line,
            );
        }
    }

    public function test_the_messages_and_the_cleanup_are_exact(): void
    {
        $script = $this->file(self::SCRIPT);

        $this->assertStringContainsString('die "could not clone CREED with the job token"', $script);
        $this->assertStringContainsString('echo "CREED copy already matches CREED $VERSION; nothing to do"', $script);
        $this->assertStringContainsString('echo "pushed the CREED $VERSION copy to ${CI_DEFAULT_BRANCH}"', $script);
        $this->assertStringContainsString('git -C "$TMP/creed" describe --tags --always', $script);
        $this->assertStringContainsString("trap 'rm -rf \"\$TMP\"' EXIT", $script);

        foreach ($this->commands() as $code) {
            if (str_contains($code, 'rm -rf')) {
                $this->assertSame("trap 'rm -rf \"\$TMP\"' EXIT", $code, 'rm -rf only ever cleans $TMP');
            }
        }
    }
}
