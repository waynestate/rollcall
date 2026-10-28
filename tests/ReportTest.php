<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Metrics\PrometheusRenderer;
use Waynestate\Rollcall\Model\AppFacts;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\Report\JsonRenderer;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Report\TextRenderer;
use Waynestate\Rollcall\Tests\Support\Fleet;

final class ReportTest extends TestCase
{
    private const TODAY = '2026-10-28';

    private function builder(): ReportBuilder
    {
        return new ReportBuilder(Policy::fromFile(Fleet::FIXTURES . '/policy.json'));
    }

    /** @return array<string, mixed> */
    private function report(bool $internal = false): array
    {
        $snapshot = Fleet::snapshot(self::TODAY);

        return $this->builder()->build($snapshot, [$snapshot], new DateTimeImmutable(self::TODAY), $internal);
    }

    public function test_counts_come_from_the_policy_by_date(): void
    {
        $report = $this->report();

        $this->assertSame(4, $report['totals']['apps']);
        $this->assertSame(['active' => 1, 'security_only' => 1, 'eol' => 1, 'unknown' => 1], $report['image_states']);

        $images = array_column($report['php_image'], null, 'version');
        $this->assertSame('security_only', $images['8.3']['state']);
        $this->assertSame('2027-12-31', $images['8.3']['security_until']);
        $this->assertSame('eol', $images['8.1']['state']);
        $this->assertSame(-301, $images['8.1']['days_to_eol']);
        $this->assertSame('unknown', $images['unknown']['version']);
        $this->assertSame(['8.1', '8.3', '8.4', 'unknown'], array_column($report['php_image'], 'version'));

        $this->assertSame(['apereo/phpcas' => 1, 'none' => 2, 'waynestate/sesame' => 1], $report['auth_package']);
        $this->assertSame(['pass' => 1, 'fail' => 1, 'none' => 2], $report['proctor']);
    }

    public function test_a_version_ahead_of_the_policy_is_shown_as_treated_as_current(): void
    {
        $snapshot = new Snapshot(new DateTimeImmutable(self::TODAY), [new AppFacts('g/future', phpImage: '9.0')]);
        $report = $this->builder()->build($snapshot, [$snapshot], new DateTimeImmutable(self::TODAY), true);

        $images = array_column($report['php_image'], null, 'version');
        $this->assertSame('newer than CREED\'s policy; treated as current', $images['9.0']['state']);
        $this->assertSame(['active' => 1, 'security_only' => 0, 'eol' => 0, 'unknown' => 0], $report['image_states'], 'the count still uses the state bucket');
        $apps = array_column($report['apps'], null, 'id');
        $this->assertSame('newer than CREED\'s policy; treated as current', $apps['g/future']['image_state']);
    }

    public function test_drift_is_counted_per_kind_and_every_kind_is_listed(): void
    {
        $drift = $this->report()['drift'];

        $this->assertSame(ReportBuilder::DRIFT_KINDS, array_keys($drift));
        $this->assertSame(1, $drift['image_eol']);
        $this->assertSame(1, $drift['image_unknown']);
        $this->assertSame(1, $drift['eol_past_grace']);
        $this->assertSame(1, $drift['laravel_eol']);
        $this->assertSame(1, $drift['proctor_failing']);
        $this->assertSame(2, $drift['proctor_missing']);
        $this->assertSame(1, $drift['override_expired']);
        $this->assertSame(1, $drift['floor_allows_eol'], '>=8.1 allows an EOL PHP');
        $this->assertSame(2, $drift['floor_unbounded'], 'the "*" constraint and the unreadable composer.json');
    }

    public function test_the_public_report_carries_no_app_identity(): void
    {
        $json = (new JsonRenderer())->render($this->report());

        $this->assertStringNotContainsString('demo/', $json);
        $this->assertStringNotContainsString('"apps": [', $json);
        $this->assertSame('public', json_decode($json, true)['visibility']);
        $this->assertStringNotContainsString('Upgrade scheduled', $json, 'override reasons are internal too');
    }

    public function test_the_internal_report_lists_apps_with_overrides_and_notes(): void
    {
        $report = $this->report(true);
        $apps = array_column($report['apps'], null, 'id');

        $this->assertSame('internal', $report['visibility']);
        $this->assertSame('eol', $apps['demo/atlas']['image_state']);
        $this->assertTrue($apps['demo/atlas']['overrides'][0]['expired'], 'an expired override stays listed, marked expired');
        $this->assertContains('override_expired', $apps['demo/atlas']['drift']);
        $this->assertContains('composer.lock not found', $apps['demo/relic']['notes']);
    }

    public function test_progress_and_the_upgrade_credit_across_two_scans(): void
    {
        $before = new Snapshot(new DateTimeImmutable('2026-09-01'), [
            new AppFacts('g/one', phpImage: '8.1'),
            new AppFacts('g/two', phpImage: '8.3'),
            new AppFacts('g/gone', phpImage: '8.1'),
        ]);
        $after = new Snapshot(new DateTimeImmutable('2026-10-01'), [
            new AppFacts('g/one', phpImage: '8.4'),
            new AppFacts('g/two', phpImage: '8.3'),
            new AppFacts('g/new', phpImage: '8.4'),
        ]);

        $report = $this->builder()->build($after, [$before, $after], new DateTimeImmutable('2026-10-28'), true);

        $this->assertSame(['since' => '2026-09-01', 'moved' => 1, 'upgraded_to_supported' => 1, 'new_apps' => 1, 'gone_apps' => 1], $report['progress']);
        $apps = array_column($report['apps'], null, 'id');
        $this->assertSame('2026-10-01', $apps['g/one']['upgraded_on'], 'the upgrade is credited on the day it landed');
        $this->assertNull($apps['g/two']['upgraded_on']);
    }

    public function test_the_burn_down_uses_the_state_on_each_scan_day(): void
    {
        // PHP 8.1 stops security support on 2025-12-31: supported in the first scan, EOL in the second.
        $first = new Snapshot(new DateTimeImmutable('2025-12-01'), [new AppFacts('g/a', phpImage: '8.1'), new AppFacts('g/b', phpImage: '8.4')]);
        $second = new Snapshot(new DateTimeImmutable('2026-01-15'), [new AppFacts('g/a', phpImage: '8.1'), new AppFacts('g/b', phpImage: '8.4')]);

        $series = $this->builder()->build($second, [$first, $second], new DateTimeImmutable('2026-01-15'))['burn_down'];

        $this->assertSame(['date' => '2025-12-01', 'apps' => 2, 'eol' => 0, 'supported' => 2, 'unknown' => 0], $series[0]);
        $this->assertSame(['date' => '2026-01-15', 'apps' => 2, 'eol' => 1, 'supported' => 1, 'unknown' => 0], $series[1]);
    }

    public function test_the_first_report_has_no_previous_scan(): void
    {
        $progress = $this->report()['progress'];
        $this->assertNull($progress['since']);
        $this->assertSame(4, $progress['new_apps']);
    }

    public function test_the_report_has_no_ranking_and_no_owner_fields(): void
    {
        $json = strtolower((new JsonRenderer())->render($this->report(true)));
        foreach (['rank', 'owner', 'score', 'leaderboard'] as $word) {
            $this->assertStringNotContainsString($word, $json);
        }
    }

    public function test_text_output_strips_control_characters_from_outside_input(): void
    {
        $snapshot = new Snapshot(new DateTimeImmutable(self::TODAY), [new AppFacts("g/evil\e[31m\nred", phpImage: '8.4')]);
        $report = $this->builder()->build($snapshot, [$snapshot], new DateTimeImmutable(self::TODAY), true);

        $text = (new TextRenderer())->render($report);

        $this->assertStringNotContainsString("\e", $text);
        $this->assertStringContainsString('g/evil?[31m?red', $text);
        $this->assertStringContainsString('Apps (internal, do not publish)', $text);
    }

    public function test_text_output_has_the_public_sections(): void
    {
        $text = (new TextRenderer())->render($this->report());
        foreach (['PHP image', 'Declared PHP floor', 'Laravel', 'Auth package', 'PROCTOR', 'Drift', 'EOL burn-down'] as $heading) {
            $this->assertStringContainsString($heading, $text);
        }
        $this->assertStringNotContainsString('demo/', $text);
    }

    public function test_prometheus_text_is_low_cardinality_and_has_no_identity(): void
    {
        $text = (new PrometheusRenderer())->render($this->report());

        $this->assertStringContainsString("# TYPE rollcall_apps gauge\nrollcall_apps 4\n", $text);
        $this->assertStringContainsString('rollcall_image_state_apps{state="eol"} 1', $text);
        $this->assertStringContainsString('rollcall_php_image_apps{version="8.1",state="eol"} 1', $text);
        $this->assertStringContainsString('rollcall_php_image_days_to_eol{version="8.1"} -301', $text);
        $this->assertStringContainsString('rollcall_drift_apps{kind="image_eol"} 1', $text);
        $this->assertStringNotContainsString('demo/', $text);
        $this->assertStringNotContainsString('Upgrade scheduled', $text);
    }

    public function test_prometheus_labels_from_hostile_values_collapse(): void
    {
        $report = $this->report();
        $report['auth_package'] = ['evil"} 1' => 3, 'waynestate/sesame' => 1];
        $report['php_image'] = [['version' => '8.4"}', 'apps' => 1, 'state' => 'weird', 'security_until' => null, 'days_to_eol' => null]];

        $text = (new PrometheusRenderer())->render($report);

        $this->assertStringContainsString('rollcall_auth_package_apps{package="other"} 3', $text);
        $this->assertStringContainsString('rollcall_php_image_apps{version="unknown",state="unknown"} 1', $text);
        $this->assertStringNotContainsString('evil', $text);
    }
}
