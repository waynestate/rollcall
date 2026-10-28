<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Console\Application;
use Waynestate\Rollcall\Lifecycle\EndOfLifeDrift;
use Waynestate\Rollcall\Policy\Policy;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Tests\Support\Fleet;

final class ConsoleTest extends TestCase
{
    private string $store;

    protected function setUp(): void
    {
        $this->store = Fleet::tempDir();
    }

    protected function tearDown(): void
    {
        Fleet::remove($this->store);
    }

    /** @param  array<string, string>  $extra */
    private function tester(array $extra = []): ApplicationTester
    {
        $app = new Application([
            'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
            'ROLLCALL_STORE_DIR' => $this->store,
            'ROLLCALL_REPOS_DIR' => Fleet::FIXTURES . '/repos',
            'ROLLCALL_IMAGES_FILE' => Fleet::FIXTURES . '/images.json',
            'ROLLCALL_PROCTOR_DIR' => Fleet::FIXTURES . '/proctor',
            ...$extra,
        ]);
        $app->setAutoExit(false);

        return new ApplicationTester($app);
    }

    public function test_scan_then_report_json(): void
    {
        $tester = $this->tester();

        $this->assertSame(0, $tester->run(['command' => 'scan', '--today' => '2026-10-28']));
        $this->assertStringContainsString('Present: 4 apps. Snapshot 2026-10-28', $tester->getDisplay());
        $this->assertFileExists($this->store . '/2026-10-28.json');

        $this->assertSame(0, $tester->run(['command' => 'report', '--format' => 'json', '--today' => '2026-10-28']));
        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(4, $report['totals']['apps']);
        $this->assertArrayNotHasKey('apps', $report);

        $this->assertSame(0, $tester->run(['command' => 'report', '--format' => 'json', '--internal' => true, '--today' => '2026-10-28']));
        $this->assertCount(4, json_decode($tester->getDisplay(), true)['apps']);
    }

    public function test_report_text_is_the_default(): void
    {
        $tester = $this->tester();
        $tester->run(['command' => 'scan', '--today' => '2026-10-28']);

        $this->assertSame(0, $tester->run(['command' => 'report', '--today' => '2026-10-28']));
        $this->assertStringContainsString('ROLLCALL 0.1.0 | scan 2026-10-28 | as of 2026-10-28 | public', $tester->getDisplay());
    }

    public function test_a_second_scan_reports_progress_against_the_first(): void
    {
        $tester = $this->tester();
        $tester->run(['command' => 'scan', '--today' => '2026-10-27']);
        $tester->run(['command' => 'scan', '--today' => '2026-10-28']);
        $tester->run(['command' => 'report', '--format' => 'json', '--today' => '2026-10-28']);

        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame('2026-10-27', $report['progress']['since']);
        $this->assertSame(0, $report['progress']['moved']);
        $this->assertCount(2, $report['burn_down']);
    }

    public function test_metrics_are_off_unless_enabled(): void
    {
        $tester = $this->tester();
        $tester->run(['command' => 'scan', '--today' => '2026-10-28']);

        $this->assertSame(2, $tester->run(['command' => 'report', '--format' => 'prometheus', '--today' => '2026-10-28']));
        $this->assertStringContainsString('Metrics are off', $tester->getDisplay());

        $on = $this->tester(['ROLLCALL_METRICS_ENABLED' => 'true']);
        $this->assertSame(0, $on->run(['command' => 'report', '--format' => 'prometheus', '--today' => '2026-10-28']));
        $this->assertStringContainsString('rollcall_apps 4', $on->getDisplay());
    }

    public function test_errors_exit_2_with_a_plain_message(): void
    {
        $this->assertSame(2, $this->tester()->run(['command' => 'report']));

        $this->tester()->run(['command' => 'scan', '--today' => '2026-10-28']);
        $tester = $this->tester(['ROLLCALL_POLICY_FILE' => '']);
        $this->assertSame(2, $tester->run(['command' => 'report']));
        $this->assertStringContainsString('ROLLCALL_POLICY_FILE', $tester->getDisplay());

        $this->assertSame(2, $this->tester()->run(['command' => 'report', '--format' => 'xml']));
        $this->assertSame(2, $this->tester()->run(['command' => 'scan', '--today' => 'tomorrow']));
        $this->assertSame(2, $this->tester(['ROLLCALL_SOURCE' => 'ftp'])->run(['command' => 'scan']));
    }

    public function test_the_gitlab_source_needs_its_settings_and_never_prints_the_token(): void
    {
        $tester = $this->tester(['ROLLCALL_SOURCE' => 'gitlab', 'ROLLCALL_GITLAB_TOKEN' => 'glpat-visible-secret']);

        $this->assertSame(2, $tester->run(['command' => 'scan']));
        $this->assertStringContainsString('ROLLCALL_GITLAB_URL', $tester->getDisplay());
        $this->assertStringNotContainsString('glpat-visible-secret', $tester->getDisplay());
    }

    public function test_secrets_are_hidden_from_debug_output(): void
    {
        $config = Config::fromEnv(['ROLLCALL_GITLAB_TOKEN' => 'glpat-visible-secret', 'ROLLCALL_METRICS_TOKEN' => 'scrape-secret']);

        $dump = print_r($config, true) . var_export($config->__debugInfo(), true);
        $this->assertStringNotContainsString('glpat-visible-secret', $dump);
        $this->assertStringNotContainsString('scrape-secret', $dump);
        $this->assertSame('glpat-visible-secret', $config->gitlabToken());
    }

    public function test_drift_command_and_class_compare_policy_with_the_feed(): void
    {
        $tester = $this->tester();
        $this->assertSame(0, $tester->run(['command' => 'drift', '--feed' => Fleet::FIXTURES . '/endoflife-php.json']));
        $display = $tester->getDisplay();

        $this->assertStringContainsString('php 8.3 security_until: policy 2027-12-31, endoflife.date 2027-11-30', $display);
        $this->assertStringNotContainsString('php 8.4', $display, 'matching dates are not drift');
        $this->assertStringNotContainsString('9.9', $display, 'a release the policy does not list is not drift');

        $this->assertSame(2, $tester->run(['command' => 'drift']));
    }

    public function test_drift_rejects_a_file_in_the_wrong_shape(): void
    {
        $this->expectException(RollcallException::class);
        (new EndOfLifeDrift(Policy::fromFile(Fleet::FIXTURES . '/policy.json')))->compare('php', '{"releases": []}');
    }
}
