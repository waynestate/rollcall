<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;

final class DashboardAccessibilityTest extends LaravelTestCase
{
    protected function tune(Repository $config): void
    {
        $config->set('rollcall.dashboard.enabled', true);
        $config->set('rollcall.dashboard.internal_enabled', true);
        // The test app has no sign-in middleware; a plain 'web' stands in for the app's own.
        $config->set('rollcall.dashboard.internal_middleware', ['web']);
        $config->set('rollcall.wayneui_css', 'https://example.test/wayne-ui.css');
    }

    public function test_the_public_page_passes_the_rendered_html_rules(): void
    {
        $html = $this->get('/rollcall')->assertOk()->getContent() ?: '';

        $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html), 'the page must have exactly one h1');
        $this->assertStringContainsString('lang="en"', $html);

        // The skip link must point at an id that exists on the page.
        $this->assertTrue(
            (bool) preg_match('/<a\b(?=[^>]*class="wsu-skip-link")[^>]*href="#([\w-]+)"/i', $html, $skip),
            'the skip link is missing',
        );
        $this->assertTrue(
            (bool) preg_match('/\bid="' . preg_quote($skip[1], '/') . '"/', $html),
            'the skip link target id is missing',
        );

        // Every table carries its own caption.
        $tables = preg_split('/<table\b/i', $html);
        array_shift($tables);
        $this->assertNotEmpty($tables, 'expected at least one table');
        foreach ($tables as $chunk) {
            $this->assertStringContainsString('<caption', $chunk, 'a table is missing its caption');
        }

        // Every th declares a scope.
        $allTh = preg_match_all('/<th\b/i', $html);
        $scopedTh = preg_match_all('/<th\b[^>]*\bscope="/i', $html);
        $this->assertGreaterThan(0, $allTh, 'expected at least one th');
        $this->assertSame($allTh, $scopedTh, 'a th is missing its scope');

        // The status line names the apps shown and the scan date.
        $this->assertStringContainsString('<p id="rc-status" role="status">', $html);
        $this->assertTrue(
            (bool) preg_match('/<p[^>]*id="rc-status"[^>]*>(.*?)<\/p>/is', $html, $status),
            'the status paragraph is missing',
        );
        $statusText = preg_replace('/\s+/', ' ', trim($status[1]));
        $this->assertStringContainsString('Showing', $statusText);
        $this->assertTrue(
            (bool) preg_match('/from the scan of (\d{4}-\d{2}-\d{2})/', $statusText, $scan),
            'the status line does not name the scan date',
        );
        $this->assertStringNotContainsString('This page lists every app', $statusText);

        // No positive tabindex anywhere (tabindex="0" on the scroll regions is fine).
        $this->assertSame(0, preg_match_all('/tabindex="[1-9]\d*"/', $html), 'a positive tabindex appeared');

        // No id is used twice.
        preg_match_all('/\bid="([^"]*)"/', $html, $ids);
        $this->assertNotEmpty($ids[1], 'expected at least one id');
        $this->assertSame(count($ids[1]), count(array_unique($ids[1])), 'an id is used more than once');

        // The public page never names an app.
        $this->assertStringNotContainsString('demo/', $html);
    }

    public function test_the_internal_status_line_keeps_apps_inside(): void
    {
        $html = $this->get('/rollcall/apps')->assertOk()->getContent() ?: '';

        $this->assertStringContainsString('<p id="rc-status" role="status">', $html);
        $this->assertTrue(
            (bool) preg_match('/<p[^>]*id="rc-status"[^>]*>(.*?)<\/p>/is', $html, $status),
            'the status paragraph is missing',
        );
        $statusText = preg_replace('/\s+/', ' ', trim($status[1]));
        $this->assertStringContainsString('Showing', $statusText);
        $this->assertStringContainsString(
            'This page lists every app; keep it inside the signed-in app.',
            $statusText,
        );
    }
}
