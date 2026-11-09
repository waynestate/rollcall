<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;
use Waynestate\Rollcall\Dashboard\RawDashboard;

/**
 * Writes the rendered dashboard pages (public, internal and the empty state,
 * from Laravel and from the raw-PHP entry point) to the folder named by
 * ROLLCALL_DASHBOARD_OUT, so the dashboard axe gate can read them as static
 * files. The substitute takes the register once per page and keeps a copy of
 * every roll; the raw-PHP room gets its own copy.
 */
final class DashboardRenderTest extends LaravelTestCase
{
    protected function tune(Repository $config): void
    {
        $config->set('rollcall.dashboard.enabled', true);
        $config->set('rollcall.dashboard.internal_enabled', true);
        // The test app has no sign-in middleware; a plain 'web' stands in for the app's own.
        $config->set('rollcall.dashboard.internal_middleware', ['web']);
        $css = $this->stylesheet();
        if ($css !== null) {
            $config->set('rollcall.wayneui_css', $css);
        }
    }

    public function test_it_writes_both_rendered_pages_when_asked(): void
    {
        $out = $this->outputFolder();

        $index = $this->get('/rollcall')->assertOk();
        $apps = $this->get('/rollcall/apps')->assertOk();

        file_put_contents($out . '/index.html', $index->getContent() ?: '');
        file_put_contents($out . '/apps.html', $apps->getContent() ?: '');

        $this->assertFileExists($out . '/index.html');
        $this->assertFileExists($out . '/apps.html');
    }

    public function test_it_writes_the_empty_state_page_when_asked(): void
    {
        $out = $this->outputFolder();

        $this->emptyTheStore();

        $empty = $this->get('/rollcall')->assertOk();

        file_put_contents($out . '/empty.html', $empty->getContent() ?: '');

        $this->assertFileExists($out . '/empty.html');
        $this->assertStringContainsString('No scan has been stored yet', (string) file_get_contents($out . '/empty.html'));
    }

    public function test_it_writes_the_raw_php_pages_when_asked(): void
    {
        $out = $this->outputFolder();

        $raw = RawDashboard::response(['env' => $this->env()]);
        $rawApps = RawDashboard::response(['env' => $this->env(), 'internal' => true]);
        $this->assertSame(200, $raw['status']);
        $this->assertSame(200, $rawApps['status']);

        file_put_contents($out . '/raw.html', $raw['body']);
        file_put_contents($out . '/raw-apps.html', $rawApps['body']);

        $this->emptyTheStore();
        $rawEmpty = RawDashboard::response(['env' => $this->env()]);
        $this->assertSame(200, $rawEmpty['status']);
        file_put_contents($out . '/raw-empty.html', $rawEmpty['body']);

        $this->assertStringContainsString('No scan has been stored yet', (string) file_get_contents($out . '/raw-empty.html'));
        $this->assertStringContainsString('role="status"', (string) file_get_contents($out . '/raw.html'));
    }

    /** @return array<string, string> */
    private function env(): array
    {
        $css = $this->stylesheet();

        return $this->rawEnv($css === null ? [] : ['ROLLCALL_WAYNEUI_CSS' => $css]);
    }

    private function stylesheet(): ?string
    {
        $css = getenv('ROLLCALL_WAYNEUI_CSS');

        return is_string($css) && $css !== '' ? $css : null;
    }

    /** An empty fleet store: drop the fixture scan the base case saved. */
    private function emptyTheStore(): void
    {
        foreach (glob($this->store . '/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    /** The output folder, or a skipped test when the gate did not ask for one. */
    private function outputFolder(): string
    {
        $out = getenv('ROLLCALL_DASHBOARD_OUT');
        if (!is_string($out) || $out === '') {
            $this->markTestSkipped('Set ROLLCALL_DASHBOARD_OUT to a folder to write the rendered dashboard pages.');
        }

        if (!is_dir($out) && !mkdir($out, 0777, true)) {
            $this->fail('could not create the output folder: ' . $out);
        }

        return $out;
    }
}
