<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards that the public surface (front page, docs site, README, llms files)
 * never shows a per-app finding, an internal hostname, or an IP address.
 */
final class PublicSurfaceTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** @return list<string> */
    private function publicFiles(): array
    {
        $files = [];

        $siteDir = self::ROOT . '/site';
        if (is_dir($siteDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($siteDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_contains($path, '/site/assets/')
                    || str_contains($path, '/site/screenshots/')
                    || str_contains($path, '/site/brand/')) {
                    continue;
                }
                if (in_array($file->getExtension(), ['html', 'md', 'txt', 'xml', 'json'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $docsDir = self::ROOT . '/docs';
        if (is_dir($docsDir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($docsDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($it as $file) {
                if (in_array($file->getExtension(), ['md', 'json'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach (['README.md', 'CHANGELOG.md', 'OPEN_QUESTIONS.md', 'BEST_PRACTICES.md'] as $name) {
            $path = self::ROOT . '/' . $name;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    public function test_no_fixture_project_path_appears_in_public_files(): void
    {
        $json = (string) file_get_contents(self::ROOT . '/tests/fixtures/images.json');
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $projects = [];
        foreach ($data['apps'] as $app) {
            $projects[] = $app['project'];
        }
        $this->assertNotEmpty($projects);

        foreach ($this->publicFiles() as $file) {
            $text = (string) file_get_contents($file);
            foreach ($projects as $project) {
                $this->assertStringNotContainsString(
                    $project,
                    $text,
                    $file . ' names the fixture project ' . $project,
                );
            }
        }
    }

    public function test_no_internal_wayne_hostname_appears_in_public_files(): void
    {
        foreach ($this->publicFiles() as $file) {
            $text = (string) file_get_contents($file);
            $hosts = [];

            preg_match_all('/https?:\/\/([A-Za-z0-9.-]+)/', $text, $m);
            foreach ($m[1] ?? [] as $h) {
                $hosts[] = $h;
            }

            preg_match_all('/[A-Za-z0-9.-]*wayne\.edu/', $text, $m);
            foreach ($m[0] ?? [] as $h) {
                $hosts[] = $h;
            }

            foreach (array_unique($hosts) as $host) {
                $lower = strtolower($host);
                if ($lower !== 'wayne.edu' && !str_ends_with($lower, '.wayne.edu')) {
                    continue;
                }
                $allowed = $lower === 'wayne.edu'
                    || $lower === 'ui.wayne.edu'
                    || $lower === 'git.wayne.edu'
                    || str_ends_with($lower, '.apps.wayne.edu');
                $this->assertTrue($allowed, $file . ' names the internal hostname ' . $host);
            }
        }
    }

    public function test_no_ip_address_appears_in_public_files(): void
    {
        $allowed = ['127.0.0.1', '0.0.0.0'];
        $pattern = '/(?<![\d.-])(?:(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)\.){3}(?:25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)(?![\d.-])/';
        foreach ($this->publicFiles() as $file) {
            $text = (string) file_get_contents($file);
            preg_match_all($pattern, $text, $m);
            foreach (array_unique($m[0] ?? []) as $ip) {
                $this->assertTrue(
                    in_array($ip, $allowed, true),
                    $file . ' names the IP address ' . $ip,
                );
            }
        }
    }

    public function test_the_scan_found_files(): void
    {
        $files = $this->publicFiles();
        $this->assertGreaterThanOrEqual(10, count($files), 'Expected at least 10 public files to be scanned');
    }
}
