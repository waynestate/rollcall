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
        $this->assertSame(['held' => 1, 'missing' => 1, 'not_applicable' => 0, 'unknown' => 2], $report['mysql56_ci']);
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
        $this->assertSame('held', $apps['demo/atlas']['mysql56_ci']);
        $this->assertSame('missing', $apps['demo/ledger']['mysql56_ci']);
        $this->assertSame('unknown', $apps['demo/relic']['mysql56_ci'], 'no report at all is unknown');
        $this->assertSame('unknown', $apps['demo/broken']['mysql56_ci'], 'an unreadable report is unknown too');
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

        $this->assertSame(['date' => '2025-12-01', 'apps' => 2, 'eol' => 0, 'supported' => 2, 'unknown' => 0], array_slice($series[0], 0, 5));
        $this->assertSame(['date' => '2026-01-15', 'apps' => 2, 'eol' => 1, 'supported' => 1, 'unknown' => 0], array_slice($series[1], 0, 5));
        $this->assertSame(['active' => 1, 'security_only' => 1, 'eol' => 0, 'unknown' => 0], $series[0]['states']);
        $this->assertSame(['active' => 1, 'security_only' => 0, 'eol' => 1, 'unknown' => 0], $series[1]['states']);
    }

    public function test_each_burn_down_point_breaks_the_roll_down_by_php_minor_laravel_major_and_state(): void
    {
        $first = new Snapshot(new DateTimeImmutable('2026-09-01'), [
            new AppFacts('g/a', phpImage: '8.1', laravel: '10.48.2'),
            new AppFacts('g/b', phpImage: '8.1', laravel: '9.52.0'),
            new AppFacts('g/c', phpImage: '8.10', laravel: '12.1.0'),
            new AppFacts('g/d'),
        ]);
        $second = new Snapshot(new DateTimeImmutable('2026-10-01'), [
            new AppFacts('g/a', phpImage: '8.4', laravel: '12.3.0'),
            new AppFacts('g/b', phpImage: '8.1', laravel: '9.52.0'),
            new AppFacts('g/c', phpImage: '8.10', laravel: '12.1.0'),
            new AppFacts('g/d', phpImage: '8.3'),
        ]);

        $series = $this->builder()->build($second, [$first, $second], new DateTimeImmutable('2026-10-28'))['burn_down'];

        $this->assertSame(['date', 'apps', 'eol', 'supported', 'unknown', 'states', 'php_image', 'laravel'], array_keys($series[0]));
        $this->assertSame(['8.1' => 2, '8.10' => 1, 'unknown' => 1], $series[0]['php_image'], 'version order, not string order; unknown last');
        $this->assertSame(['8.1' => 1, '8.3' => 1, '8.4' => 1, '8.10' => 1], $series[1]['php_image']);
        $this->assertSame(['9' => 1, '10' => 1, '12' => 1, 'unknown' => 1], $series[0]['laravel']);
        $this->assertSame(['9' => 1, '12' => 2, 'unknown' => 1], $series[1]['laravel']);
        foreach ($series as $point) {
            $this->assertSame($point['apps'], array_sum($point['states']));
            $this->assertSame($point['apps'], array_sum($point['php_image']));
            $this->assertSame($point['apps'], array_sum($point['laravel']));
            $this->assertSame($point['supported'], $point['states']['active'] + $point['states']['security_only']);
        }

        $json = (new JsonRenderer())->render(['burn_down' => $series]);
        $this->assertStringContainsString('"laravel": {', $json, 'a breakdown is a JSON object keyed by version, never a list');
        $this->assertStringNotContainsString('g/', $json, 'the breakdown is counts only');
    }

    public function test_a_snapshot_stored_before_the_breakdown_existed_still_gets_one(): void
    {
        // The shape every 0.1.x scan wrote: schema_version 1, facts per app, no counts.
        $old = Snapshot::fromArray([
            'schema_version' => 1,
            'date' => '2026-09-01',
            'errors' => [],
            'apps' => [['id' => 'g/a', 'php_image' => '8.2', 'laravel' => '11.0.0'], ['id' => 'g/b']],
        ]);
        $today = Fleet::snapshot(self::TODAY);

        $series = $this->builder()->build($today, [$old, $today], new DateTimeImmutable(self::TODAY))['burn_down'];

        $this->assertSame(['8.2' => 1, 'unknown' => 1], $series[0]['php_image']);
        $this->assertSame(['11' => 1, 'unknown' => 1], $series[0]['laravel']);
        $this->assertSame(['active' => 0, 'security_only' => 1, 'eol' => 0, 'unknown' => 1], $series[0]['states']);
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
        foreach (['PHP image', 'Declared PHP floor', 'Laravel', 'Auth package', 'PROCTOR', 'MySQL 5.6 CI job', 'Drift', 'EOL burn-down'] as $heading) {
            $this->assertStringContainsString($heading, $text);
        }
        $this->assertStringNotContainsString('demo/', $text);
    }

    public function test_text_output_calls_unconfigured_sources_notes_not_problems(): void
    {
        $snapshot = new Snapshot(new DateTimeImmutable(self::TODAY), [new AppFacts('g/a', phpImage: '8.4')], [
            'No image source configured; PHP images are unknown.',
            'No PROCTOR report source configured; check results are unknown.',
        ]);
        $report = $this->builder()->build($snapshot, [$snapshot], new DateTimeImmutable(self::TODAY), false);

        $text = (new TextRenderer())->render($report);

        $this->assertStringContainsString('Apps present: 1 (scan notes: 2)', $text);
        $this->assertStringNotContainsString('problem', $text);
    }

    public function test_prometheus_text_is_low_cardinality_and_has_no_identity(): void
    {
        $text = (new PrometheusRenderer())->render($this->report());

        $this->assertStringContainsString("# TYPE rollcall_apps gauge\nrollcall_apps 4\n", $text);
        $this->assertStringContainsString('rollcall_image_state_apps{state="eol"} 1', $text);
        $this->assertStringContainsString('rollcall_php_image_apps{version="8.1",state="eol"} 1', $text);
        $this->assertStringContainsString('rollcall_php_image_days_to_eol{version="8.1"} -301', $text);
        $this->assertStringContainsString('rollcall_drift_apps{kind="image_eol"} 1', $text);
        $this->assertStringContainsString('rollcall_mysql56_ci_apps{state="held"} 1', $text);
        $this->assertStringContainsString('rollcall_mysql56_ci_apps{state="missing"} 1', $text);
        $this->assertStringContainsString('rollcall_mysql56_ci_apps{state="not_applicable"} 0', $text);
        $this->assertStringContainsString('rollcall_mysql56_ci_apps{state="unknown"} 2', $text);
        $this->assertStringNotContainsString('demo/', $text);
        $this->assertStringNotContainsString('Upgrade scheduled', $text);
    }

    public function test_prometheus_carries_the_forced_upgrades_as_counts(): void
    {
        $snapshot = Fleet::snapshot(self::TODAY);
        $apps = array_map(static fn(AppFacts $app): AppFacts => match ($app->id) {
            'demo/relic' => $app->with(upgradeDue: '2026-12-15'),
            'demo/broken' => $app->with(upgradeDue: '2026-10-01'),
            default => $app,
        }, $snapshot->apps);
        $dated = new Snapshot($snapshot->date, $apps, $snapshot->errors);
        $text = (new PrometheusRenderer())->render($this->builder()->build($dated, [$dated], new DateTimeImmutable(self::TODAY)));

        $this->assertStringContainsString("# TYPE rollcall_forced_upgrades_pending_apps gauge\nrollcall_forced_upgrades_pending_apps 2\n", $text);
        $this->assertStringContainsString("rollcall_forced_upgrades_overdue_apps 1\n", $text);
        $next = (new DateTimeImmutable('2026-12-15'))->getTimestamp();
        $this->assertStringContainsString('rollcall_forced_upgrades_next_due_timestamp_seconds ' . $next . "\n", $text);
        $this->assertStringNotContainsString('demo/', $text);
    }

    public function test_prometheus_forced_upgrades_are_zero_without_a_citadel_export(): void
    {
        $text = (new PrometheusRenderer())->render($this->report());

        $this->assertStringContainsString("rollcall_forced_upgrades_pending_apps 0\n", $text);
        $this->assertStringContainsString("rollcall_forced_upgrades_overdue_apps 0\n", $text);
        $this->assertStringNotContainsString('next_due_timestamp', $text, 'no date ahead, no series');
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

    public function test_prometheus_reports_a_version_ahead_of_the_policy_as_active(): void
    {
        $snapshot = new Snapshot(new DateTimeImmutable(self::TODAY), [
            new AppFacts('g/future', phpImage: '9.0'),
            new AppFacts('g/gap', phpImage: '8.0'),
        ]);
        $report = $this->builder()->build($snapshot, [$snapshot], new DateTimeImmutable(self::TODAY));

        $text = (new PrometheusRenderer())->render($report);

        $this->assertStringContainsString('rollcall_php_image_apps{version="9.0",state="active"} 1', $text);
        $this->assertStringContainsString('rollcall_php_image_apps{version="8.0",state="eol"} 1', $text);
    }
}
