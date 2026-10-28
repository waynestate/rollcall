<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Http\Endpoint;
use Waynestate\Rollcall\Metrics\MetricsGuard;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

final class EndpointTest extends TestCase
{
    private string $store;

    protected function setUp(): void
    {
        $this->store = Fleet::tempDir();
        (new JsonFileSnapshotStore($this->store))->save(Fleet::snapshot('2026-10-28'));
    }

    protected function tearDown(): void
    {
        Fleet::remove($this->store);
    }

    /** @param  array<string, string>  $extra */
    private function endpoint(array $extra = [], ?string $store = null): Endpoint
    {
        $config = Config::fromEnv([
            'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
            'ROLLCALL_STORE_DIR' => $store ?? $this->store,
            ...$extra,
        ]);

        return new Endpoint($config, new Factory($config));
    }

    public function test_metrics_are_off_by_default_and_look_like_a_missing_route(): void
    {
        [$status, , $body] = $this->endpoint()->handle('GET', '/metrics', 'Bearer anything', '127.0.0.1');

        $this->assertSame(404, $status);
        $this->assertStringNotContainsString('rollcall_', $body);
    }

    public function test_metrics_on_but_with_no_token_and_no_allowlist_fails_closed(): void
    {
        [$status] = $this->endpoint(['ROLLCALL_METRICS_ENABLED' => 'true'])->handle('GET', '/metrics', null, '127.0.0.1');
        $this->assertSame(401, $status);
    }

    public function test_metrics_need_the_token_or_an_allowlisted_address(): void
    {
        $endpoint = $this->endpoint(['ROLLCALL_METRICS_ENABLED' => 'true', 'ROLLCALL_METRICS_TOKEN' => 's3cret', 'ROLLCALL_METRICS_ALLOW' => '10.0.0.5, 10.0.0.6']);

        $this->assertSame(401, $endpoint->handle('GET', '/metrics', 'Bearer wrong', '192.0.2.1')[0]);
        $this->assertSame(401, $endpoint->handle('GET', '/metrics', null, '192.0.2.1')[0]);

        [$status, $headers, $body] = $endpoint->handle('GET', '/metrics', 'Bearer s3cret', '192.0.2.1');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('text/plain; version=0.0.4', $headers['Content-Type']);
        $this->assertStringContainsString('rollcall_apps 4', $body);

        $this->assertSame(200, $endpoint->handle('GET', '/metrics', null, '10.0.0.6')[0]);
    }

    public function test_the_public_report_is_off_by_default_and_counts_only_when_on(): void
    {
        $this->assertSame(404, $this->endpoint()->handle('GET', '/report.json', null, null)[0]);

        [$status, , $body] = $this->endpoint(['ROLLCALL_PUBLIC_REPORT_ENABLED' => '1'])->handle('GET', '/report.json', null, null);
        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('demo/', $body);
        $this->assertSame(4, json_decode($body, true)['totals']['apps']);
    }

    public function test_only_get_and_head_are_served(): void
    {
        [$status, $headers] = $this->endpoint(['ROLLCALL_METRICS_ENABLED' => 'true'])->handle('POST', '/metrics', null, null);
        $this->assertSame(405, $status);
        $this->assertSame('GET, HEAD', $headers['Allow']);
    }

    public function test_unknown_paths_are_404_and_no_scan_yet_is_503(): void
    {
        $this->assertSame(404, $this->endpoint()->handle('GET', '/apps', null, null)[0]);

        $empty = Fleet::tempDir();
        try {
            $endpoint = $this->endpoint(['ROLLCALL_PUBLIC_REPORT_ENABLED' => 'true'], $empty);
            $this->assertSame(503, $endpoint->handle('GET', '/report.json', null, null)[0]);
        } finally {
            Fleet::remove($empty);
        }
    }

    public function test_a_broken_setup_is_a_plain_503_that_leaks_no_path(): void
    {
        $config = Config::fromEnv(['ROLLCALL_PUBLIC_REPORT_ENABLED' => 'true', 'ROLLCALL_STORE_DIR' => $this->store]);
        [$status, , $body] = (new Endpoint($config, new Factory($config)))->handle('GET', '/report.json', null, null);

        $this->assertSame(503, $status);
        $this->assertStringNotContainsString('ROLLCALL_POLICY_FILE', $body);
    }

    public function test_guard_compares_in_a_fail_closed_way(): void
    {
        $this->assertFalse((new MetricsGuard())->allows('Bearer ', '127.0.0.1'));
        $this->assertFalse((new MetricsGuard('', []))->allows('Bearer ', null));
        $this->assertFalse((new MetricsGuard('abc'))->allows('Basic abc', null));
        $this->assertFalse((new MetricsGuard('abc'))->allows('Bearer abcd', null));
        $this->assertTrue((new MetricsGuard('abc'))->allows('Bearer abc', null));
        $this->assertFalse((new MetricsGuard('', ['10.0.0.5']))->allows(null, '10.0.0.50'));
    }
}
