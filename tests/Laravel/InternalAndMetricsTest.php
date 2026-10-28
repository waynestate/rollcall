<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;

final class InternalAndMetricsTest extends LaravelTestCase
{
    protected function tune(Repository $config): void
    {
        $config->set('rollcall.dashboard.enabled', true);
        $config->set('rollcall.dashboard.internal_enabled', true);
        // The test app has no sign-in middleware; a plain 'web' stands in for the app's own.
        $config->set('rollcall.dashboard.internal_middleware', ['web']);
        $config->set('rollcall.metrics_enabled', true);
        $config->set('rollcall.metrics_token', 'scrape-token');
    }

    public function test_the_internal_page_lists_apps_escaped_and_marked_internal(): void
    {
        $response = $this->get('/rollcall/apps');

        $response->assertOk();
        $response->assertSee('Internal view: do not publish.');
        $response->assertSee('demo/ledger');
        $response->assertSee('demo/atlas');
    }

    public function test_metrics_need_the_bearer_token(): void
    {
        $this->get('/metrics')->assertUnauthorized();

        $response = $this->get('/metrics', ['Authorization' => 'Bearer scrape-token']);
        $response->assertOk();
        $this->assertStringContainsString('rollcall_apps 4', $response->getContent() ?: '');
        $this->assertStringNotContainsString('demo/', $response->getContent() ?: '');
    }
}
