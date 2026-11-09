<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The Vue page's gate is wired the same way in CI and on a laptop: the
 * dashboard-vue job and the local dashboard-a11y scripts run the parity check
 * in the a11y component's pinned image, every npm package it needs is pinned
 * exactly, and the accessibility gate checks the three Vue pages next to the
 * six PHP ones, in CI and locally alike.
 */
final class DashboardVueGateTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private const IMAGE = 'mcr.microsoft.com/playwright:v1.65.0-noble@sha256:6e3fb709361521eb27161606e6da6d2674e5e89fa16516cb37d6fdd91372273d';

    private const VUE_PAGES = ['vue.html', 'vue-apps.html', 'vue-empty.html'];

    public function test_the_ci_job_runs_the_parity_check_before_the_a11y_gate(): void
    {
        $ci = (string) file_get_contents(self::ROOT . '/.gitlab-ci.yml');

        $job = $this->block($ci, 'dashboard-vue');
        $this->assertStringContainsString('image: ' . self::IMAGE, $job);
        $this->assertStringContainsString('needs: [dashboard-render]', $job);
        $this->assertStringContainsString('node --disable-warning=ExperimentalWarning tools/vue-dashboard/parity.mjs build/dashboard-a11y', $job);
        $this->assertStringContainsString('npm ci --prefix tools/vue-dashboard', $job);
        $this->assertStringContainsString('npm_config_cache:', $job, 'npm downloads go through the job cache');

        $this->assertStringContainsString('needs: [dashboard-render, dashboard-vue]', $this->block($ci, 'dashboard-a11y'));
    }

    public function test_ci_and_the_local_scripts_check_the_same_pages_including_the_vue_ones(): void
    {
        $ci = (string) file_get_contents(self::ROOT . '/.gitlab-ci.yml');
        $this->assertSame(1, preg_match('/job_name: dashboard-a11y\s+site_dir: build\/dashboard-a11y\s+pages: (\[[^\]]*\])/', $ci, $m));
        $pages = json_decode($m[1], true);
        $this->assertIsArray($pages);
        foreach (self::VUE_PAGES as $vue) {
            $this->assertContains($vue, $pages);
        }

        foreach (['scripts/dashboard-a11y.sh', 'scripts/dashboard-a11y.ps1'] as $script) {
            $code = (string) file_get_contents(self::ROOT . '/' . $script);
            $this->assertSame(1, preg_match('/"pages": (\[[^\]]*\])/', $code, $s), $script . ' has no pages list');
            $this->assertSame($pages, json_decode($s[1], true), $script . ' checks other pages than CI');
            $this->assertStringContainsString('tools/vue-dashboard/parity.mjs /work/build/dashboard-a11y', $code);
            $this->assertStringContainsString('stack-npm-cache:/npm-cache', $code);
        }
    }

    public function test_every_npm_package_is_pinned_exactly(): void
    {
        $package = json_decode((string) file_get_contents(self::ROOT . '/tools/vue-dashboard/package.json'), true);
        $lock = json_decode((string) file_get_contents(self::ROOT . '/tools/vue-dashboard/package-lock.json'), true);
        $this->assertIsArray($package);
        $this->assertIsArray($lock);

        $dependencies = $package['dependencies'] ?? [];
        $this->assertArrayHasKey('vue', $dependencies);
        $this->assertArrayHasKey('parse5', $dependencies);
        $this->assertSame([], $package['devDependencies'] ?? []);
        foreach ($dependencies as $name => $version) {
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version, $name . ' must be pinned to one exact version');
            $this->assertSame($version, $lock['packages']['node_modules/' . $name]['version'] ?? null, $name . ' in package-lock.json');
        }
    }

    /** One top-level job of .gitlab-ci.yml, up to the next blank line. */
    private function block(string $ci, string $job): string
    {
        $this->assertSame(1, preg_match('/^' . preg_quote($job, '/') . ":\n(?:.+\n)+/m", $ci, $m), $job . ' is not a job in .gitlab-ci.yml');

        return $m[0];
    }
}
