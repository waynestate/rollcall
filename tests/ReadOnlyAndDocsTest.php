<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards that are about the repository itself: ROLLCALL never names a write
 * verb, and every setting it reads is documented where a deployer will look.
 */
final class ReadOnlyAndDocsTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::ROOT . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_no_source_file_names_a_write_verb_for_a_remote_call(): void
    {
        foreach ($this->phpFiles('src') as $file) {
            $code = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/[\'"](POST|PUT|PATCH|DELETE)[\'"]/', $code, $file . ' names a write verb');
            $this->assertStringNotContainsString('curl_setopt', $code);
        }
    }

    public function test_only_the_snapshot_store_writes_files(): void
    {
        foreach ($this->phpFiles('src') as $file) {
            if (str_ends_with($file, 'JsonFileSnapshotStore.php')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/file_put_contents|fopen\(|\bunlink\(|\brename\(|\bmkdir\(/', (string) file_get_contents($file), $file . ' writes to disk');
        }
    }

    public function test_every_setting_is_in_the_readme_and_the_env_example(): void
    {
        $names = [];
        foreach ([...$this->phpFiles('src'), self::ROOT . '/config/rollcall.php'] as $file) {
            preg_match_all('/ROLLCALL_[A-Z_]+/', (string) file_get_contents($file), $m);
            array_push($names, ...$m[0]);
        }
        $names = array_unique($names);
        $this->assertNotEmpty($names);

        $readme = (string) file_get_contents(self::ROOT . '/README.md');
        $env = (string) file_get_contents(self::ROOT . '/.env.example');
        foreach ($names as $name) {
            $this->assertStringContainsString($name, $readme, $name . ' is missing from README.md');
            $this->assertStringContainsString($name, $env, $name . ' is missing from .env.example');
        }
    }

    public function test_the_env_example_holds_no_real_secret(): void
    {
        $env = (string) file_get_contents(self::ROOT . '/.env.example');
        $this->assertDoesNotMatchRegularExpression('/glpat-[A-Za-z0-9_-]{10,}/', $env);
        $this->assertMatchesRegularExpression('/^ROLLCALL_METRICS_TOKEN=$/m', $env);
    }

    public function test_the_grafana_dashboard_is_valid_json_and_names_only_rollcall_metrics(): void
    {
        $json = (string) file_get_contents(self::ROOT . '/grafana/rollcall-burn-down.json');
        $dash = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('rollcall-burn-down', $dash['uid']);
        $this->assertNotEmpty($dash['panels']);
        foreach ($dash['panels'] as $panel) {
            foreach ($panel['targets'] as $target) {
                $this->assertMatchesRegularExpression('/^rollcall_[a-z_]+/', $target['expr']);
            }
        }
    }
}
