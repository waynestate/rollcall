<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Model\AppFacts;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Source\DirectoryProctorReportSource;
use Waynestate\Rollcall\Source\DirectoryRepositorySource;
use Waynestate\Rollcall\Source\RepoFile;
use Waynestate\Rollcall\Source\RepositorySource;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\CountingSource;
use Waynestate\Rollcall\Tests\Support\Fleet;

final class ScannerTest extends TestCase
{
    /** @return array<string, AppFacts> */
    private function byId(Snapshot $snapshot): array
    {
        $apps = [];
        foreach ($snapshot->apps as $app) {
            $apps[$app->id] = $app;
        }

        return $apps;
    }

    public function test_every_demo_app_answers_present(): void
    {
        $snapshot = Fleet::snapshot('2026-10-28');

        $this->assertSame('2026-10-28', $snapshot->date->format('Y-m-d'));
        $this->assertSame(['demo/atlas', 'demo/broken', 'demo/ledger', 'demo/relic'], array_map(static fn(AppFacts $a): string => $a->id, $snapshot->apps));
    }

    public function test_facts_join_the_composer_files_the_image_and_the_proctor_report(): void
    {
        $apps = $this->byId(Fleet::snapshot('2026-10-28'));

        $ledger = $apps['demo/ledger'];
        $this->assertSame('8.4', $ledger->phpImage);
        $this->assertSame('php:8.4-apache', $ledger->imageTag);
        $this->assertSame('pass', $ledger->proctorResult);
        $this->assertSame('12.3.1', $ledger->laravel);

        $atlas = $apps['demo/atlas'];
        $this->assertSame('fail', $atlas->proctorResult);
        $this->assertSame('apereo/phpcas', $atlas->authPackage);
        $this->assertCount(1, $atlas->overrides);
    }

    public function test_gaps_become_notes_and_never_a_failed_scan(): void
    {
        $apps = $this->byId(Fleet::snapshot('2026-10-28'));

        $relic = $apps['demo/relic'];
        $this->assertNull($relic->phpImage);
        $this->assertNull($relic->proctorResult);
        $this->assertContains('image tag does not name a PHP version', $relic->notes);
        $this->assertContains('composer.lock not found', $relic->notes);

        $broken = $apps['demo/broken'];
        $this->assertContains('composer.json is not valid JSON', $broken->notes);
        $this->assertContains('PROCTOR report could not be read', $broken->notes);
        $this->assertNull($broken->proctorResult);
        $this->assertSame('8.3', $broken->phpImage);
    }

    public function test_the_booster_hop_record_joins_the_facts_or_becomes_a_note(): void
    {
        $apps = $this->byId(Fleet::snapshot('2026-10-28'));

        $ledger = $apps['demo/ledger'];
        $this->assertSame('pass', $ledger->boosterResult);
        $this->assertSame('1.10.0', $ledger->boosterVersion);
        $this->assertSame('2026-11-08T14:07:41+00:00', $ledger->boosterAt);

        $relic = $apps['demo/relic'];
        $this->assertNull($relic->boosterResult);
        $this->assertNull($relic->boosterVersion);
        $this->assertNull($relic->boosterAt);

        $broken = $apps['demo/broken'];
        $this->assertNull($broken->boosterResult);
        $this->assertContains('Booster hop record could not be read', $broken->notes);
    }

    public function test_a_stale_booster_result_does_not_survive_when_the_record_disappears(): void
    {
        $first = Fleet::snapshot('2026-10-27');
        $hidden = new class (Fleet::repositories()) implements RepositorySource {
            public function __construct(private readonly RepositorySource $inner) {}

            public function projects(): array
            {
                return $this->inner->projects();
            }

            public function file(string $project, string $path, ?string $knownBlobId = null): RepoFile
            {
                if ($path === 'booster-hop.json') {
                    return RepoFile::missing();
                }

                return $this->inner->file($project, $path, $knownBlobId);
            }
        };
        $second = Fleet::scanner('2026-10-28', $hidden)->scan($first);

        foreach ($second->apps as $app) {
            $this->assertNull($app->boosterResult);
            $this->assertNull($app->boosterVersion);
            $this->assertNull($app->boosterAt);
            $this->assertNotContains('Booster hop record could not be read', $app->notes);
        }
    }

    public function test_missing_sources_are_reported_on_the_snapshot(): void
    {
        $scanner = new \Waynestate\Rollcall\Scan\Scanner(Fleet::repositories(), null, null, new \Waynestate\Rollcall\Clock\FixedClock('2026-10-28'));
        $snapshot = $scanner->scan();

        $this->assertCount(2, $snapshot->errors);
        $this->assertNull($snapshot->apps[0]->phpImage);
    }

    public function test_unchanged_files_are_not_read_again(): void
    {
        $first = Fleet::snapshot('2026-10-27');
        $source = new CountingSource(Fleet::repositories());
        $second = Fleet::scanner('2026-10-28', $source)->scan($first);

        // Apps with both composer files present must never have a composer body sent again. An app
        // missing one file re-reads the other, because the parser needs both bodies. The hop record
        // is parsed on every scan, so its body is asked for again.
        $complete = array_filter($source->calls, static fn(string $c): bool => (str_starts_with($c, 'demo/ledger:') || str_starts_with($c, 'demo/atlas:')) && ! str_contains($c, ':booster-hop.json:'));
        $this->assertCount(4, $complete);
        foreach ($complete as $call) {
            $this->assertStringEndsWith(':unchanged', $call);
        }
        $this->assertEquals($first->apps, $second->apps);
    }

    public function test_a_stale_proctor_result_does_not_survive_when_the_report_disappears(): void
    {
        $first = Fleet::snapshot('2026-10-27');
        $empty = Fleet::tempDir();
        try {
            $scanner = new \Waynestate\Rollcall\Scan\Scanner(
                Fleet::repositories(),
                \Waynestate\Rollcall\Source\JsonFileImageSource::fromFile(Fleet::FIXTURES . '/images.json'),
                new DirectoryProctorReportSource($empty),
                new \Waynestate\Rollcall\Clock\FixedClock('2026-10-28'),
            );
            $second = $scanner->scan($first);
        } finally {
            Fleet::remove($empty);
        }

        foreach ($second->apps as $app) {
            $this->assertNull($app->proctorResult);
            $this->assertSame([], $app->overrides);
        }
    }

    public function test_directory_source_refuses_paths_that_escape(): void
    {
        $source = Fleet::repositories();
        $this->expectException(RollcallException::class);
        $source->file('../etc', 'composer.json');
    }

    public function test_directory_source_only_reads_the_two_composer_files(): void
    {
        $this->expectException(RollcallException::class);
        Fleet::repositories()->file('demo/ledger', '.env');
    }

    public function test_proctor_directory_source_ignores_hostile_ids(): void
    {
        $source = new DirectoryProctorReportSource(Fleet::FIXTURES . '/proctor');
        $this->assertNull($source->report('../policy'));
        $this->assertNull($source->report('demo/../../policy'));
        $this->assertNotNull($source->report('demo/ledger'));
    }

    public function test_missing_directories_are_named_errors(): void
    {
        $this->expectException(RollcallException::class);
        new DirectoryRepositorySource(Fleet::FIXTURES . '/nope');
    }

    public function test_snapshot_store_round_trips_and_replaces_the_same_day(): void
    {
        $dir = Fleet::tempDir();
        try {
            $store = new JsonFileSnapshotStore($dir . '/nested/snapshots');
            $this->assertNull($store->latest());

            $store->save(Fleet::snapshot('2026-10-27'));
            $store->save(Fleet::snapshot('2026-10-28'));
            $store->save(Fleet::snapshot('2026-10-28'));

            $all = $store->all();
            $this->assertCount(2, $all);
            $this->assertSame('2026-10-28', $store->latest()?->date->format('Y-m-d'));
            $this->assertEquals(Fleet::snapshot('2026-10-27')->apps, $all[0]->apps);
            $this->assertSame([], glob($dir . '/nested/snapshots/*.tmp'));
        } finally {
            Fleet::remove($dir . '/nested/snapshots');
            Fleet::remove($dir . '/nested');
            Fleet::remove($dir);
        }
    }

    public function test_a_corrupt_snapshot_is_a_named_error(): void
    {
        $dir = Fleet::tempDir();
        try {
            file_put_contents($dir . '/2026-10-28.json', '{ nope');
            $this->expectException(RollcallException::class);
            (new JsonFileSnapshotStore($dir))->all();
        } finally {
            Fleet::remove($dir);
        }
    }
}
