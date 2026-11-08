<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;

/**
 * Writes the rendered dashboard pages (public, internal and the empty state)
 * to the folder named by ROLLCALL_DASHBOARD_OUT, so the dashboard axe gate
 * can read them as static files. The substitute takes the register once per
 * page and keeps a copy of every roll.
 */
final class DashboardRenderTest extends LaravelTestCase
{
    protected function tune(Repository $config): void
    {
        $config->set('rollcall.dashboard.enabled', true);
        $config->set('rollcall.dashboard.internal_enabled', true);
        // The test app has no sign-in middleware; a plain 'web' stands in for the app's own.
        $config->set('rollcall.dashboard.internal_middleware', ['web']);
        $css = getenv('ROLLCALL_WAYNEUI_CSS');
        if (is_string($css) && $css !== '') {
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

        // An empty fleet store: drop the fixture scan the base case saved.
        foreach (glob($this->store . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        $empty = $this->get('/rollcall')->assertOk();

        file_put_contents($out . '/empty.html', $empty->getContent() ?: '');

        $this->assertFileExists($out . '/empty.html');
        $this->assertStringContainsString('No scan has been stored yet', (string) file_get_contents($out . '/empty.html'));
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
