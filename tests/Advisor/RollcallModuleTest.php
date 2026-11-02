<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Advisor\Advisor;
use Waynestate\Advisor\Config as AdvisorConfig;
use Waynestate\Advisor\Content\ContentBundle;
use Waynestate\Advisor\Exception\ToolInputError;
use Waynestate\Advisor\Module\ModuleContext;
use Waynestate\Advisor\Module\ToolDefinition;
use Waynestate\Advisor\Security\PathGuard;
use Waynestate\Advisor\Security\SymfonyProcessRunner;
use Waynestate\Advisor\Support\FixedClock;
use Waynestate\Rollcall\Advisor\RollcallModule;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

/** The ADVISOR module: three read-only tools over the public counts. */
final class RollcallModuleTest extends TestCase
{
    private const TODAY = '2026-11-02';

    private string $storeDir;

    protected function setUp(): void
    {
        $this->storeDir = Fleet::tempDir();
        putenv('ROLLCALL_STORE_DIR=' . $this->storeDir);
        $_ENV['ROLLCALL_STORE_DIR'] = $this->storeDir;
        putenv('ROLLCALL_POLICY_FILE');
        unset($_ENV['ROLLCALL_POLICY_FILE']);
    }

    protected function tearDown(): void
    {
        putenv('ROLLCALL_STORE_DIR');
        unset($_ENV['ROLLCALL_STORE_DIR']);
        Fleet::remove($this->storeDir);
    }

    private function module(): RollcallModule
    {
        return new RollcallModule(new ModuleContext(
            AdvisorConfig::fromEnv(['ADVISOR_ENABLED' => '1']),
            new ContentBundle(Advisor::dataDir()),
            new FixedClock(self::TODAY),
            new PathGuard(sys_get_temp_dir()),
            new SymfonyProcessRunner(),
        ));
    }

    /** @return array<string, ToolDefinition> */
    private function tools(RollcallModule $module): array
    {
        $tools = [];
        foreach ($module->tools() as $tool) {
            $tools[$tool->name] = $tool;
        }

        return $tools;
    }

    /** @return array<string, mixed> */
    private function call(ToolDefinition $tool, array $args = []): array
    {
        return ($tool->handler)($args);
    }

    private function saveSnapshots(string ...$dates): void
    {
        $store = new JsonFileSnapshotStore($this->storeDir);
        foreach ($dates as $date) {
            $store->save(Fleet::snapshot($date));
        }
    }

    public function test_every_tool_is_read_only_and_takes_nothing_dangerous(): void
    {
        $tools = $this->tools($this->module());
        $this->assertSame(['rollcall_fleet_summary', 'rollcall_burn_down', 'rollcall_supported_versions'], array_keys($tools));

        foreach ($tools as $name => $tool) {
            $this->assertTrue($tool->readOnly, $name);
            $this->assertTrue($tool->idempotent, $name);
            $this->assertFalse($tool->openWorld, $name);
            $this->assertStringStartsWith('rollcall_', $name);
            $properties = array_keys($tool->inputSchema['properties'] ?? []);
            $this->assertNotContains('path', $properties, $name);
            $this->assertNotContains('command', $properties, $name);
            $this->assertNotContains('url', $properties, $name);
        }
    }

    public function test_fleet_summary_returns_the_public_counts_with_source_and_dates(): void
    {
        $this->saveSnapshots('2026-11-01', self::TODAY);
        $out = $this->call($this->tools($this->module())['rollcall_fleet_summary']);

        $store = new JsonFileSnapshotStore($this->storeDir);
        $expected = (new ReportBuilder(Policy::bundled()))
            ->build($store->latest(), $store->all(), new DateTimeImmutable(self::TODAY), false);

        $this->assertSame('Rollcall snapshot store (ROLLCALL_STORE_DIR)', $out['source']);
        $this->assertSame(self::TODAY, $out['as_of']);
        $this->assertSame(self::TODAY, $out['scan_date']);
        foreach (['totals', 'image_states', 'php_image', 'php_floor', 'laravel', 'auth_package', 'proctor', 'drift', 'progress'] as $field) {
            $this->assertSame($expected[$field], $out[$field], $field);
        }
        $this->assertSame(4, $out['totals']['apps']);
        $this->assertSame(['active' => 1, 'security_only' => 1, 'eol' => 1, 'unknown' => 1], $out['image_states']);
        $this->assertArrayNotHasKey('apps', $out, 'the per-app list is internal');
        $this->assertArrayNotHasKey('scan_errors', $out, 'the per-app list is internal');
        $this->assertArrayNotHasKey('visibility', $out);
    }

    public function test_burn_down_series_covers_every_scan_day_and_days_limits_it(): void
    {
        $this->saveSnapshots('2026-10-31', '2026-11-01', self::TODAY);
        $tool = $this->tools($this->module())['rollcall_burn_down'];

        $full = $this->call($tool);
        $this->assertCount(3, $full['series']);
        $this->assertSame(['2026-10-31', '2026-11-01', self::TODAY], array_column($full['series'], 'date'));
        foreach ($full['series'] as $point) {
            $this->assertSame(4, $point['apps']);
            $this->assertSame(1, $point['eol']);
            $this->assertSame(2, $point['supported']);
            $this->assertSame(1, $point['unknown']);
        }

        $one = $this->call($tool, ['days' => 1]);
        $this->assertCount(1, $one['series']);
        $this->assertSame(self::TODAY, $one['series'][0]['date']);
    }

    public function test_burn_down_rejects_days_outside_1_to_365(): void
    {
        $this->saveSnapshots(self::TODAY);
        $tool = $this->tools($this->module())['rollcall_burn_down'];

        $rejected = 0;
        foreach ([0, 366, -1, 'ten'] as $bad) {
            try {
                $this->call($tool, ['days' => $bad]);
                $this->fail('days=' . var_export($bad, true) . ' was accepted');
            } catch (ToolInputError) {
                $rejected++;
            }
        }
        $this->assertSame(4, $rejected);
    }

    public function test_an_empty_store_says_to_run_rollcall_scan(): void
    {
        $tools = $this->tools($this->module());

        foreach (['rollcall_fleet_summary', 'rollcall_burn_down'] as $name) {
            $out = $this->call($tools[$name]);
            $this->assertSame('No snapshot yet: run rollcall scan to take one.', $out['note']);
            $this->assertSame(self::TODAY, $out['as_of']);
            $this->assertArrayNotHasKey('totals', $out);
        }

        $versions = $this->call($tools['rollcall_supported_versions']);
        $this->assertArrayNotHasKey('note', $versions, 'the policy answers even when the store is empty');
        $this->assertArrayHasKey('components', $versions);
    }

    public function test_supported_versions_groups_the_policy_by_what_it_says_today(): void
    {
        $out = $this->call($this->tools($this->module())['rollcall_supported_versions']);

        $this->assertSame('CREED policy.json, bundled with waynestate/rollcall', $out['source']);
        $this->assertSame(self::TODAY, $out['as_of']);

        $php = $out['components']['php'];
        $this->assertSame(['8.4', '8.5'], array_column($php['active'], 'version'));
        $this->assertSame(['8.2', '8.3'], array_column($php['security_only'], 'version'));
        $this->assertSame(['8.1'], array_column($php['end_of_life'], 'version'));
        $this->assertSame('eol_grace_expired', $php['end_of_life'][0]['state']);

        $laravel = $out['components']['laravel'];
        $this->assertSame(['13'], array_column($laravel['active'], 'version'));
        $this->assertSame(['12'], array_column($laravel['security_only'], 'version'));
        $this->assertSame(['10', '11'], array_column($laravel['end_of_life'], 'version'));

        foreach ($out['components'] as $component) {
            $this->assertSame(['active', 'security_only', 'end_of_life'], array_keys($component));
        }
    }

    public function test_no_tool_output_names_a_fixture_app_or_path(): void
    {
        $this->saveSnapshots('2026-11-01', self::TODAY);
        $tools = $this->tools($this->module());

        $outputs = [
            $this->call($tools['rollcall_fleet_summary']),
            $this->call($tools['rollcall_burn_down']),
            $this->call($tools['rollcall_burn_down'], ['days' => 1]),
            $this->call($tools['rollcall_supported_versions']),
        ];
        foreach ($outputs as $out) {
            $text = (string) json_encode($out, JSON_UNESCAPED_SLASHES);
            foreach (['demo/atlas', 'demo/broken', 'demo/ledger', 'demo/relic', 'atlas', 'broken', 'ledger', 'relic'] as $needle) {
                $this->assertStringNotContainsString($needle, $text, "output names {$needle}");
            }
        }
    }

    public function test_advisor_is_suggested_and_dev_required_but_not_required(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('waynestate/advisor', $composer['require']);
        $this->assertArrayHasKey('waynestate/advisor', $composer['require-dev']);
        $this->assertArrayHasKey('waynestate/advisor', $composer['suggest']);
    }
}
