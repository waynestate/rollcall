<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;
use Waynestate\Rollcall\Dashboard\DashboardPage;
use Waynestate\Rollcall\Dashboard\RawDashboard;

/**
 * One roll, two rooms: the raw-PHP page and the Laravel page answer with the
 * same markup for the same snapshot, byte for byte.
 */
final class DashboardParityTest extends LaravelTestCase
{
    private const CSS = 'https://example.test/wayne-ui.css';

    protected function tune(Repository $config): void
    {
        $config->set('rollcall.dashboard.enabled', true);
        $config->set('rollcall.dashboard.internal_enabled', true);
        // The test app has no sign-in middleware; a plain 'web' stands in for the app's own.
        $config->set('rollcall.dashboard.internal_middleware', ['web']);
        $config->set('rollcall.wayneui_css', self::CSS);
    }

    public function test_the_public_pages_are_identical(): void
    {
        $laravel = $this->get('/rollcall')->assertOk()->getContent();
        $raw = RawDashboard::response(['env' => $this->rawEnv(['ROLLCALL_WAYNEUI_CSS' => self::CSS])]);

        $this->assertSame(200, $raw['status']);
        $this->assertStringContainsString('<p id="rc-status" role="status">', $raw['body']);
        $this->assertSame($laravel, $raw['body']);
    }

    public function test_the_internal_pages_are_identical(): void
    {
        $laravel = $this->get('/rollcall/apps')->assertOk()->getContent();
        $raw = RawDashboard::response(['env' => $this->rawEnv(['ROLLCALL_WAYNEUI_CSS' => self::CSS]), 'internal' => true]);

        $this->assertSame(200, $raw['status']);
        $this->assertStringContainsString('demo/', $raw['body'], 'the internal page lists the apps');
        $this->assertSame($laravel, $raw['body']);
    }

    public function test_the_empty_states_differ_only_in_the_scan_command_they_name(): void
    {
        foreach (glob($this->store . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        $laravel = (string) $this->get('/rollcall')->assertOk()->getContent();
        $raw = RawDashboard::response(['env' => $this->rawEnv(['ROLLCALL_WAYNEUI_CSS' => self::CSS])]);

        $this->assertStringContainsString('<code>' . DashboardPage::LARAVEL_SCAN . '</code>', $laravel);
        $this->assertStringContainsString('<code>' . DashboardPage::CLI_SCAN . '</code>', $raw['body']);
        $this->assertSame(str_replace(DashboardPage::LARAVEL_SCAN, DashboardPage::CLI_SCAN, $laravel), $raw['body']);
    }

    public function test_both_pages_send_noindex(): void
    {
        $laravel = (string) $this->get('/rollcall')->getContent();
        $raw = RawDashboard::response(['env' => $this->rawEnv()]);

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $laravel);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $raw['body']);
        $this->assertSame('noindex', $raw['headers']['X-Robots-Tag'] ?? null);
    }
}
