<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;

final class DashboardTest extends LaravelTestCase
{
    protected function tune(Repository $config): void
    {
        $config->set('rollcall.dashboard.enabled', true);
        $config->set('rollcall.dashboard.internal_enabled', false);
        $config->set('rollcall.wayneui_css', 'https://example.test/wayne-ui.css');
    }

    public function test_the_dashboard_shows_counts_and_no_app_identity(): void
    {
        $response = $this->get('/rollcall');

        $response->assertOk();
        $response->assertSee('Apps present');
        $response->assertSee('EOL burn-down');
        $response->assertSee('Security support ends');
        $response->assertSee('<link rel="stylesheet" href="https://example.test/wayne-ui.css">', false);
        $response->assertDontSee('demo/ledger');
        $response->assertSee('lang="en"', false);
    }

    public function test_the_internal_page_does_not_exist_until_it_is_turned_on(): void
    {
        $this->get('/rollcall/apps')->assertNotFound();
    }

    public function test_metrics_are_off_by_default_even_with_the_dashboard_on(): void
    {
        $this->get('/metrics')->assertNotFound();
    }

    public function test_the_scan_command_stores_a_snapshot(): void
    {
        $this->assertSame(0, Artisan::call('rollcall:scan'));
        $this->assertStringContainsString('Present: 4 apps', Artisan::output());
    }
}
