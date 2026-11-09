<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Dashboard\RawDashboard;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

/**
 * The raw-PHP dashboard with no framework in the room: off until it is turned
 * on, noindex on every answer, no script, and the internal page only when
 * both the setting and the caller say so.
 */
final class RawDashboardTest extends TestCase
{
    private string $store = '';

    protected function setUp(): void
    {
        $this->store = Fleet::tempDir();
        (new JsonFileSnapshotStore($this->store))->save(Fleet::snapshot('2026-10-28'));
    }

    protected function tearDown(): void
    {
        Fleet::remove($this->store);
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function env(array $extra = []): array
    {
        return $extra + [
            'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
            'ROLLCALL_STORE_DIR' => $this->store,
            'ROLLCALL_DASHBOARD_ENABLED' => 'true',
        ];
    }

    public function test_it_is_off_by_default_and_answers_404(): void
    {
        $response = RawDashboard::response(['env' => ['ROLLCALL_STORE_DIR' => $this->store, 'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json']]);

        $this->assertSame(404, $response['status']);
        $this->assertSame('noindex', $response['headers']['X-Robots-Tag']);
        $this->assertStringNotContainsString('<html', $response['body']);
    }

    public function test_a_false_setting_keeps_it_off(): void
    {
        $response = RawDashboard::response(['env' => $this->env(['ROLLCALL_DASHBOARD_ENABLED' => 'false'])]);

        $this->assertSame(404, $response['status']);
    }

    public function test_on_it_serves_the_page_with_noindex_and_no_script(): void
    {
        $response = RawDashboard::response(['env' => $this->env()]);

        $this->assertSame(200, $response['status']);
        $this->assertSame('text/html; charset=utf-8', $response['headers']['Content-Type']);
        $this->assertSame('noindex', $response['headers']['X-Robots-Tag']);
        $this->assertSame('no-store', $response['headers']['Cache-Control']);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $response['body']);
        $this->assertStringContainsString('<p id="rc-status" role="status">', $response['body']);
        $this->assertMatchesRegularExpression('/Showing 4 apps from the scan of 2026-10-28\./', $response['body']);
        $this->assertStringNotContainsStringIgnoringCase('<script', $response['body']);
        $this->assertDoesNotMatchRegularExpression('/<[^>]+\son[a-z]+=/i', $response['body'], 'an inline event handler appeared');
        // The public page never names an app.
        $this->assertStringNotContainsString('demo/', $response['body']);
    }

    public function test_the_empty_state_names_the_cli_scan(): void
    {
        $empty = Fleet::tempDir();
        try {
            $response = RawDashboard::response(['env' => $this->env(['ROLLCALL_STORE_DIR' => $empty])]);
        } finally {
            Fleet::remove($empty);
        }

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('No scan has been stored yet. Run <code>vendor/bin/rollcall scan</code> and reload.', $response['body']);
        $this->assertStringNotContainsString('role="status"', $response['body']);
    }

    public function test_the_internal_page_needs_the_setting_and_the_caller(): void
    {
        $this->assertSame(404, RawDashboard::response(['env' => $this->env(), 'internal' => true])['status'], 'the setting is off');

        $public = RawDashboard::response(['env' => $this->env(['ROLLCALL_INTERNAL_ENABLED' => 'true'])]);
        $this->assertSame(200, $public['status']);
        $this->assertStringNotContainsString('demo/', $public['body'], 'the setting alone never shows the apps');

        $internal = RawDashboard::response(['env' => $this->env(['ROLLCALL_INTERNAL_ENABLED' => 'true']), 'internal' => true]);
        $this->assertSame(200, $internal['status']);
        $this->assertStringContainsString('demo/', $internal['body']);
        $this->assertStringContainsString('This page lists every app; keep it inside the signed-in app.', $internal['body']);
    }

    public function test_it_links_the_stylesheet_and_carries_the_nonce(): void
    {
        $response = RawDashboard::response([
            'env' => $this->env(['ROLLCALL_WAYNEUI_CSS' => '/assets/wayne-ui/wayne-ui.css']),
            'nonce' => 'abc123+/=',
        ]);

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/wayne-ui/wayne-ui.css">', $response['body']);
        $this->assertStringContainsString('<style nonce="abc123+/=">', $response['body']);
    }

    public function test_a_stylesheet_that_is_not_https_or_a_path_links_nothing(): void
    {
        $response = RawDashboard::response(['env' => $this->env(['ROLLCALL_WAYNEUI_CSS' => 'javascript:alert(1)'])]);

        $this->assertStringNotContainsString('<link', $response['body']);
        $this->assertStringContainsString('<style>', $response['body']);
    }

    public function test_only_get_and_head_are_answered(): void
    {
        $response = RawDashboard::response(['env' => $this->env(), 'method' => 'OPTIONS']);

        $this->assertSame(405, $response['status']);
        $this->assertSame('GET, HEAD', $response['headers']['Allow']);
        $this->assertSame(200, RawDashboard::response(['env' => $this->env(), 'method' => 'HEAD'])['status']);
    }

    public function test_a_bad_setting_is_a_plain_503(): void
    {
        $log = ini_set('error_log', '/dev/null');
        try {
            $response = RawDashboard::response(['env' => $this->env(['ROLLCALL_SOURCE' => 'ftp'])]);
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }

        $this->assertSame(503, $response['status']);
        $this->assertSame("ROLLCALL is not ready.\n", $response['body']);
        $this->assertStringNotContainsString('ROLLCALL_SOURCE', $response['body']);
    }

    public function test_the_one_call_function_is_autoloaded(): void
    {
        $this->assertTrue(function_exists('rollcall_dashboard'));
    }
}
