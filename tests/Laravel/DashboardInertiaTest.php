<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Waynestate\Rollcall\Dashboard\DashboardPage;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Laravel\RollcallServiceProvider;

/**
 * The third room: with rollcall.dashboard.inertia on, the same routes answer
 * an Inertia app with its Vue page, and the props are the very report the
 * Blade page prints. The switches that guard the Blade page guard this one.
 * (The markup parity of the Vue component runs in the dashboard-vue CI job,
 * tools/vue-dashboard/parity.mjs, because it needs Node.)
 */
final class DashboardInertiaTest extends LaravelTestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function tune(Repository $config): void
    {
        $config->set('rollcall.dashboard.inertia', true);
        $config->set('rollcall.dashboard.enabled', $this->name() !== 'test_the_dashboard_stays_off_until_it_is_enabled');
        $config->set('rollcall.dashboard.internal_enabled', $this->name() !== 'test_the_internal_page_stays_off_without_its_switch');
        // The test app has no sign-in middleware; a plain 'web' stands in for the app's own.
        $config->set('rollcall.dashboard.internal_middleware', ['web']);
    }

    public function test_the_page_is_off_by_default(): void
    {
        $config = require self::ROOT . '/config/rollcall.php';

        $this->assertFalse((bool) $config['dashboard']['inertia']);
        $this->assertSame('Rollcall/Dashboard', $config['dashboard']['inertia_page']);
    }

    public function test_the_public_page_hands_the_report_to_the_vue_page(): void
    {
        $response = $this->visit('/rollcall');
        $page = $response->json();

        $this->assertSame('Rollcall/Dashboard', $page['component']);
        $this->assertSame($this->expected(false), $page['props']['report']);
        $this->assertFalse($page['props']['internal']);
        $this->assertSame(DashboardPage::LARAVEL_SCAN, $page['props']['scanCommand']);
        $this->assertSame('public', $page['props']['report']['visibility']);
        $this->assertArrayNotHasKey('apps', $page['props']['report']);
        $this->assertStringNotContainsString('demo/', (string) $response->getContent(), 'the public props name no app');
    }

    public function test_the_internal_page_hands_over_the_per_app_report(): void
    {
        $page = $this->visit('/rollcall/apps')->json();

        $this->assertSame($this->expected(true), $page['props']['report']);
        $this->assertTrue($page['props']['internal']);
        $this->assertCount(4, $page['props']['report']['apps']);
    }

    public function test_the_empty_state_sends_no_report(): void
    {
        foreach (glob($this->store . '/*.json') ?: [] as $file) {
            unlink($file);
        }

        $page = $this->visit('/rollcall')->json();

        $this->assertNull($page['props']['report']);
        $this->assertSame(DashboardPage::LARAVEL_SCAN, $page['props']['scanCommand']);
    }

    public function test_the_internal_page_stays_off_without_its_switch(): void
    {
        $this->get('/rollcall/apps', ['X-Inertia' => 'true'])->assertNotFound();
        $this->visit('/rollcall');
    }

    public function test_the_dashboard_stays_off_until_it_is_enabled(): void
    {
        $this->get('/rollcall', ['X-Inertia' => 'true'])->assertNotFound();
        $this->get('/rollcall/apps', ['X-Inertia' => 'true'])->assertNotFound();
    }

    public function test_the_page_follows_the_configured_component_name(): void
    {
        config()->set('rollcall.dashboard.inertia_page', 'Admin/Fleet');

        $this->assertSame('Admin/Fleet', $this->visit('/rollcall')->json('component'));
    }

    public function test_with_the_switch_off_the_same_route_serves_the_blade_page(): void
    {
        config()->set('rollcall.dashboard.inertia', false);

        $response = $this->get('/rollcall')->assertOk();
        $this->assertStringStartsWith('<!DOCTYPE html>', (string) $response->getContent());
        $this->assertStringContainsString('<p id="rc-status" role="status">', (string) $response->getContent());
    }

    public function test_the_package_ships_the_component_and_publishes_the_page(): void
    {
        $component = self::ROOT . '/resources/js/RollcallDashboard.vue';
        $page = self::ROOT . '/resources/js/pages/Rollcall/Dashboard.vue';
        $this->assertFileExists($component);
        $this->assertFileExists($page);

        $published = ServiceProvider::pathsToPublish(RollcallServiceProvider::class, 'rollcall-inertia');
        $this->assertCount(1, $published);
        $this->assertSame(realpath($page), realpath((string) array_key_first($published)));
        $this->assertSame(resource_path('js/pages/Rollcall/Dashboard.vue'), array_values($published)[0]);

        // Published to <app>/resources/js/pages/Rollcall/, four levels below the app root, the page
        // imports the component from the Composer package, so composer update keeps the markup current.
        $this->assertStringContainsString(
            "from '../../../../vendor/waynestate/rollcall/resources/js/RollcallDashboard.vue'",
            (string) file_get_contents($page),
        );
        $this->assertSame(realpath(self::ROOT), realpath(dirname($page) . '/../../../..'));
    }

    public function test_the_component_carries_nothing_a_strict_csp_refuses(): void
    {
        $code = (string) file_get_contents(self::ROOT . '/resources/js/RollcallDashboard.vue');

        $this->assertSame(1, substr_count($code, '<script'), 'one script setup block, nothing inline in the page');
        $this->assertStringContainsString('<script setup lang="ts">', $code);
        foreach (['<style', 'style=', ':style', 'v-html', 'innerHTML', 'eval(', 'new Function'] as $refused) {
            $this->assertStringNotContainsString($refused, $code);
        }
    }

    /** An Inertia visit to $path: a 200 JSON page object, marked noindex. */
    private function visit(string $path): TestResponse
    {
        return $this->get($path, ['X-Inertia' => 'true'])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * What DashboardPage::report() builds for the same store, as it reads after a JSON round trip.
     *
     * @return array<string, mixed>
     */
    private function expected(bool $internal): array
    {
        $report = DashboardPage::report($this->app->make(Factory::class), $internal);
        $this->assertIsArray($report);

        return json_decode((string) json_encode($report), true);
    }
}
