<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every OS gets its own native path (the stack's ADR-0009): a developer script
 * ships as a .ps1 for Windows plus a .sh for Linux and macOS that do the same
 * thing, or as one PHP command. Everybody answers present, in their own accent.
 */
final class ScriptPairsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /**
     * Shell files that never run on a developer machine: Citadel runs them
     * inside its Linux container (image build, first-release hook, Quick
     * Update post-script). Squire owns them; see deploy/.squire.json.
     */
    private const SERVER_SIDE = [
        'deploy/build.sh',
        'deploy/scripts/release.sh',
        'deploy/deployments/001-first-release.sh',
        'deploy/.squire/portable/squire-entrypoint.sh',
    ];

    /**
     * Shell files that run only inside a GitLab CI job (Linux, the composer
     * image), never on a developer machine, so they have no .ps1 twin. They
     * still keep to macOS bash 3.2 and POSIX tools like every other .sh.
     */
    private const CI_ONLY = [
        'tools/creed-copy-refresh.sh',
    ];

    /** @return list<string> repo-relative paths of every .sh and .ps1 outside vendor and node_modules */
    private function scripts(): array
    {
        $found = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen(self::ROOT) + 1));
            if (preg_match('#(^|/)(vendor|node_modules|\.git|var)/#', $path) === 1) {
                continue;
            }
            if (in_array($file->getExtension(), ['sh', 'ps1'], true)) {
                $found[] = $path;
            }
        }
        sort($found);

        return $found;
    }

    public function test_every_developer_script_has_its_twin_for_the_other_os(): void
    {
        $scripts = $this->scripts();
        $this->assertContains('scripts/a11y.sh', $scripts);

        foreach ($scripts as $path) {
            if (in_array($path, self::SERVER_SIDE, true) || in_array($path, self::CI_ONLY, true)) {
                continue;
            }
            $twin = str_ends_with($path, '.sh') ? substr($path, 0, -3) . '.ps1' : substr($path, 0, -4) . '.sh';
            $this->assertContains($twin, $scripts, $path . ' ships without its twin ' . $twin);
        }
    }

    public function test_the_server_side_list_is_current(): void
    {
        foreach (array_merge(self::SERVER_SIDE, self::CI_ONLY) as $path) {
            $this->assertFileExists(self::ROOT . '/' . $path, $path . ' is listed as server-side but no longer exists');
        }
    }

    public function test_shell_scripts_stay_inside_macos_bash_3_2(): void
    {
        foreach ($this->scripts() as $path) {
            if (! str_ends_with($path, '.sh') || in_array($path, self::SERVER_SIDE, true)) {
                continue;
            }
            $code = (string) file_get_contents(self::ROOT . '/' . $path);
            $this->assertMatchesRegularExpression('#^\#!/(usr/bin/env (bash|sh)|bin/sh|bin/bash)\n#', $code, $path . ' needs a bash or sh shebang');
            // Bash 4 and later only: associative arrays, mapfile/readarray, case-changing expansions, |& and &>>.
            $this->assertDoesNotMatchRegularExpression('/declare -A|\bmapfile\b|\breadarray\b|\$\{[A-Za-z_]+(,,|\^\^)\}|\|&|&>>/', $code, $path . ' uses a bash 4 feature');
            // GNU-only flags that BSD tools on macOS reject.
            $this->assertDoesNotMatchRegularExpression('/\bsed -i\s+[\'"]s|\breadlink -f\b|\bgrep -P\b/', $code, $path . ' uses a GNU-only flag');
        }
    }

    public function test_powershell_scripts_never_need_bash_or_wsl(): void
    {
        foreach ($this->scripts() as $path) {
            if (! str_ends_with($path, '.ps1')) {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents(self::ROOT . '/' . $path)) as $line) {
                $code = trim($line);
                if ($code === '' || str_starts_with($code, '#')) {
                    continue;
                }
                // Inside a container is fine (docker run ... sh -c, docker exec ... bash); on the host it is not.
                if (str_contains($code, 'docker') || str_contains($code, '$inner') || str_contains($code, "'exec'")) {
                    continue;
                }
                $this->assertDoesNotMatchRegularExpression('/^(&\s*)?(bash|wsl|sh)(\.exe)?\s/i', $code, $path . ' calls a Unix shell on the host: ' . $code);
            }
        }
    }

    public function test_the_docs_name_both_halves_of_every_pair(): void
    {
        $docs = (string) file_get_contents(self::ROOT . '/MAINTAINING.md') . (string) file_get_contents(self::ROOT . '/docs/reference/scripts.md');
        foreach ($this->scripts() as $path) {
            if (in_array($path, self::SERVER_SIDE, true)) {
                continue;
            }
            $this->assertStringContainsString(basename($path), $docs, basename($path) . ' is not named in MAINTAINING.md or docs/reference/scripts.md');
        }
    }
}
