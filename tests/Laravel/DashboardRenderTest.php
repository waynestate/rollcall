<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;

/**
 * Writes the two rendered dashboard pages (public and internal) to the folder
 * named by ROLLCALL_DASHBOARD_OUT, so the dashboard axe gate can read them as
 * static files. The substitute takes the register once per page and keeps a
 * copy of both.
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
        $out = getenv('ROLLCALL_DASHBOARD_OUT');
        if (!is_string($out) || $out === '') {
            $this->markTestSkipped('Set ROLLCALL_DASHBOARD_OUT to a folder to write the rendered dashboard pages.');
        }

        if (!is_dir($out) && !mkdir($out, 0777, true)) {
            $this->fail('could not create the output folder: ' . $out);
        }

        $index = $this->get('/rollcall')->assertOk();
        $apps = $this->get('/rollcall/apps')->assertOk();

        file_put_contents($out . '/index.html', $index->getContent() ?: '');
        file_put_contents($out . '/apps.html', $apps->getContent() ?: '');

        $this->assertFileExists($out . '/index.html');
        $this->assertFileExists($out . '/apps.html');
    }
}
