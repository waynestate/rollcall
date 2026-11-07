<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Console\Application;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Model\AppFacts;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Scan\Scanner;
use Waynestate\Rollcall\Source\CitadelExportSource;
use Waynestate\Rollcall\Source\DirectoryProctorReportSource;
use Waynestate\Rollcall\Tests\Support\Fleet;

/** The Citadel source reads a saved export only: no call to Citadel, ever. Fixtures stand in for it. */
final class CitadelExportSourceTest extends TestCase
{
    private const EXPORT = Fleet::FIXTURES . '/citadel-export.json';

    private function scan(string $date = '2026-11-07'): Snapshot
    {
        return (new Scanner(
            Fleet::repositories(),
            CitadelExportSource::fromFile(self::EXPORT),
            new DirectoryProctorReportSource(Fleet::FIXTURES . '/proctor'),
            new FixedClock($date),
            new ComposerReader(),
        ))->scan();
    }

    private function app(Snapshot $snapshot, string $id): AppFacts
    {
        foreach ($snapshot->apps as $app) {
            if ($app->id === $id) {
                return $app;
            }
        }
        $this->fail('no app ' . $id);
    }

    public function test_it_reads_each_base_image_in_citadels_own_words(): void
    {
        $source = CitadelExportSource::fromFile(self::EXPORT);

        $this->assertSame('citadel/php-apache:8.4r5', $source->imageTag('demo/ledger'));
        $this->assertSame('citadel/static:latest', $source->imageTag('demo/relic'));
        $this->assertNull($source->imageTag('demo/not-registered'));
    }

    public function test_the_earliest_pending_upgrade_is_the_one_that_counts(): void
    {
        $source = CitadelExportSource::fromFile(self::EXPORT);

        $this->assertSame('2026-12-15', $source->upgradeDue('demo/atlas'), 'two pending upgrades: the earlier date wins');
        $this->assertSame('2026-11-01', $source->upgradeDue('demo/broken'));
        $this->assertNull($source->upgradeDue('demo/ledger'), 'a completed upgrade is ignored');
        $this->assertNull($source->upgradeDue('demo/not-registered'));
    }

    public function test_entries_it_cannot_trust_are_skipped_not_guessed(): void
    {
        $source = CitadelExportSource::fromArray([
            'applications' => [
                ['repository' => 'g/ok', 'base_image' => 'citadel/php-apache:8.4r5'],
                ['repository' => '', 'base_image' => 'citadel/php-apache:8.4r5'],
                ['base_image' => 'citadel/php-apache:8.4r5'],
                'not an object',
            ],
            'base_image_upgrades' => [
                ['old_image' => 'citadel/php-apache:8.4r5', 'due_date' => 'soon', 'completed' => false],
                ['old_image' => 'citadel/php-apache:8.4r5', 'due_date' => '2026-02-30', 'completed' => false],
                ['old_image' => 'citadel/php-apache:8.4r5', 'due_date' => '2026-12-01'],
            ],
        ]);

        $this->assertSame('citadel/php-apache:8.4r5', $source->imageTag('g/ok'));
        $this->assertNull($source->imageTag(''));
        $this->assertNull($source->upgradeDue('g/ok'), 'no real date, an impossible date, and no "completed": false are all skipped');
    }

    public function test_a_scan_carries_the_php_version_and_the_due_date(): void
    {
        $snapshot = $this->scan();

        $this->assertSame('8.4', $this->app($snapshot, 'demo/ledger')->phpImage);
        $this->assertSame('8.1', $this->app($snapshot, 'demo/atlas')->phpImage);
        $this->assertSame('2026-12-15', $this->app($snapshot, 'demo/atlas')->upgradeDue);
        $this->assertNull($this->app($snapshot, 'demo/relic')->phpImage, 'a tag with no PHP version stays unknown');
        $this->assertContains('image tag does not name a PHP version', $this->app($snapshot, 'demo/relic')->notes);
        $this->assertCount(4, $snapshot->apps, 'Citadel apps with no repository in the scan are not invented');
        $this->assertSame([], $snapshot->errors, 'the Citadel export is a configured image source');
    }

    public function test_the_due_date_survives_a_snapshot_round_trip_and_old_snapshots_still_load(): void
    {
        $snapshot = $this->scan();
        $again = Snapshot::fromArray(json_decode((string) json_encode($snapshot->toArray()), true));
        $this->assertSame('2026-12-15', $this->app($again, 'demo/atlas')->upgradeDue);

        $old = $snapshot->toArray();
        foreach ($old['apps'] as $i => $app) {
            unset($old['apps'][$i]['upgrade_due']);
        }
        $this->assertNull($this->app(Snapshot::fromArray($old), 'demo/atlas')->upgradeDue);
    }

    public function test_the_report_counts_forced_upgrades_and_lists_the_date_only_internally(): void
    {
        $builder = new ReportBuilder(Policy::fromFile(Fleet::FIXTURES . '/policy.json'));
        $snapshot = $this->scan();
        $today = new DateTimeImmutable('2026-11-07');

        $public = $builder->build($snapshot, [$snapshot], $today);
        $this->assertSame(['pending' => 2, 'overdue' => 1, 'next_due' => '2026-12-15'], $public['forced_upgrades']);
        $this->assertArrayNotHasKey('apps', $public);

        $internal = $builder->build($snapshot, [$snapshot], $today, true);
        $due = array_column($internal['apps'], 'upgrade_due', 'id');
        $this->assertSame('2026-11-01', $due['demo/broken']);
        $this->assertNull($due['demo/ledger']);
    }

    public function test_with_no_citadel_export_the_counts_are_zero(): void
    {
        $builder = new ReportBuilder(Policy::fromFile(Fleet::FIXTURES . '/policy.json'));
        $snapshot = Fleet::snapshot('2026-11-07');

        $this->assertSame(['pending' => 0, 'overdue' => 0, 'next_due' => null], $builder->build($snapshot, [$snapshot], new DateTimeImmutable('2026-11-07'))['forced_upgrades']);
    }

    public function test_a_broken_export_is_refused_in_plain_words(): void
    {
        foreach ([
            [['applications' => 'nope'], 'an "applications" list'],
            [['applications' => [], 'base_image_upgrades' => 'nope'], '"base_image_upgrades" must be a list'],
        ] as [$data, $message]) {
            try {
                CitadelExportSource::fromArray($data);
                $this->fail('accepted ' . json_encode($data));
            } catch (RollcallException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->expectException(RollcallException::class);
        $this->expectExceptionMessage('Set ROLLCALL_CITADEL_EXPORT');
        CitadelExportSource::fromFile(Fleet::FIXTURES . '/no-such-export.json');
    }

    public function test_the_cli_scans_with_the_citadel_export_and_refuses_two_image_sources(): void
    {
        $store = Fleet::tempDir();
        try {
            $env = [
                'ROLLCALL_POLICY_FILE' => Fleet::FIXTURES . '/policy.json',
                'ROLLCALL_STORE_DIR' => $store,
                'ROLLCALL_REPOS_DIR' => Fleet::FIXTURES . '/repos',
                'ROLLCALL_PROCTOR_DIR' => Fleet::FIXTURES . '/proctor',
                'ROLLCALL_CITADEL_EXPORT' => self::EXPORT,
            ];
            $app = new Application($env);
            $app->setAutoExit(false);
            $tester = new ApplicationTester($app);
            $this->assertSame(0, $tester->run(['command' => 'scan', '--today' => '2026-11-07']), $tester->getDisplay());
            $tester->run(['command' => 'report', '--today' => '2026-11-07']);
            $this->assertStringContainsString('Forced image upgrades pending: 2 (1 due today or earlier), next due 2026-12-15.', $tester->getDisplay());

            $both = new Application([...$env, 'ROLLCALL_IMAGES_FILE' => Fleet::FIXTURES . '/images.json']);
            $both->setAutoExit(false);
            $tester = new ApplicationTester($both);
            $this->assertSame(2, $tester->run(['command' => 'scan', '--today' => '2026-11-08']));
            $this->assertStringContainsString('Set ROLLCALL_IMAGES_FILE or ROLLCALL_CITADEL_EXPORT, not both', $tester->getDisplay());
        } finally {
            Fleet::remove($store);
        }
    }
}
