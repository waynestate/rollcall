<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Console\Application;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

/** Retention: snapshots older than the window go, the newest always stays. */
final class PruneTest extends TestCase
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

    /** @param  list<string>  $dates */
    private function seed(array $dates): JsonFileSnapshotStore
    {
        $store = new JsonFileSnapshotStore($this->store);
        foreach ($dates as $date) {
            $store->save(Fleet::snapshot($date));
        }

        return $store;
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

    public function test_prune_deletes_older_snapshots_and_keeps_the_newest(): void
    {
        $store = $this->seed(['2026-07-01', '2026-08-01', '2026-09-01', '2026-10-28']);

        $gone = $store->prune(90, (new FixedClock('2026-10-28'))->now());

        $this->assertSame(['2026-07-01'], $gone);
        $this->assertFileDoesNotExist($this->store . '/2026-07-01.json');
        $this->assertFileExists($this->store . '/2026-08-01.json');
        $this->assertFileExists($this->store . '/2026-09-01.json');
        $this->assertFileExists($this->store . '/2026-10-28.json');
    }

    public function test_prune_never_deletes_the_newest_snapshot(): void
    {
        $store = $this->seed(['2026-01-01', '2026-02-01']);

        $gone = $store->prune(90, (new FixedClock('2026-10-28'))->now());

        $this->assertSame(['2026-01-01'], $gone);
        $this->assertFileExists($this->store . '/2026-02-01.json');
    }

    public function test_zero_days_keeps_everything(): void
    {
        $store = $this->seed(['2026-01-01', '2026-02-01']);

        $this->assertSame([], $store->prune(0, (new FixedClock('2026-10-28'))->now()));
        $this->assertCount(2, $store->all());
    }

    public function test_dry_run_lists_but_deletes_nothing(): void
    {
        $store = $this->seed(['2026-07-01', '2026-10-28']);

        $gone = $store->prune(90, (new FixedClock('2026-10-28'))->now(), true);

        $this->assertSame(['2026-07-01'], $gone);
        $this->assertCount(2, $store->all());
    }

    public function test_a_snapshot_exactly_at_the_cutoff_is_kept(): void
    {
        // 2026-10-28 minus 90 days is 2026-07-30; a snapshot on the cut-off stays.
        $store = $this->seed(['2026-07-29', '2026-07-30', '2026-10-28']);

        $gone = $store->prune(90, (new FixedClock('2026-10-28'))->now());

        $this->assertSame(['2026-07-29'], $gone);
        $this->assertFileExists($this->store . '/2026-07-30.json');
    }

    public function test_prune_on_an_empty_store_deletes_nothing(): void
    {
        $store = new JsonFileSnapshotStore($this->store);

        $this->assertSame([], $store->prune(90, (new FixedClock('2026-10-28'))->now()));
    }

    public function test_the_prune_command_deletes_older_snapshots_and_keeps_the_newest(): void
    {
        $this->seed(['2026-07-01', '2026-08-01', '2026-10-28']);
        $tester = $this->tester();

        $this->assertSame(0, $tester->run(['command' => 'prune', '--days' => '90', '--today' => '2026-10-28']));
        $this->assertStringContainsString('Pruned snapshot 2026-07-01.', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->store . '/2026-07-01.json');
        $this->assertFileExists($this->store . '/2026-08-01.json');
        $this->assertFileExists($this->store . '/2026-10-28.json');
    }

    public function test_the_prune_command_dry_run_deletes_nothing(): void
    {
        $this->seed(['2026-07-01', '2026-10-28']);
        $tester = $this->tester();

        $this->assertSame(0, $tester->run(['command' => 'prune', '--days' => '90', '--dry-run' => true, '--today' => '2026-10-28']));
        $this->assertStringContainsString('Would prune snapshot 2026-07-01.', $tester->getDisplay());
        $this->assertFileExists($this->store . '/2026-07-01.json');
        $this->assertFileExists($this->store . '/2026-10-28.json');
    }

    public function test_the_prune_command_with_zero_days_keeps_everything(): void
    {
        $this->seed(['2026-01-01', '2026-10-28']);
        $tester = $this->tester();

        $this->assertSame(0, $tester->run(['command' => 'prune', '--days' => '0', '--today' => '2026-10-28']));
        $this->assertStringContainsString('Nothing to prune.', $tester->getDisplay());
        $this->assertCount(2, (new JsonFileSnapshotStore($this->store))->all());
    }

    public function test_the_prune_command_uses_the_configured_retention_days(): void
    {
        $this->seed(['2026-07-01', '2026-09-01', '2026-10-01', '2026-10-28']);
        $tester = $this->tester(['ROLLCALL_RETENTION_DAYS' => '30']);

        $this->assertSame(0, $tester->run(['command' => 'prune', '--today' => '2026-10-28']));
        $this->assertStringContainsString('Pruned snapshot 2026-07-01.', $tester->getDisplay());
        $this->assertStringContainsString('Pruned snapshot 2026-09-01.', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->store . '/2026-07-01.json');
        $this->assertFileDoesNotExist($this->store . '/2026-09-01.json');
        $this->assertFileExists($this->store . '/2026-10-01.json');
        $this->assertFileExists($this->store . '/2026-10-28.json');
    }

    public function test_scan_with_prune_prunes_after_saving(): void
    {
        $tester = $this->tester();
        $tester->run(['command' => 'scan', '--today' => '2026-07-01']);
        $tester->run(['command' => 'scan', '--today' => '2026-08-01']);

        $this->assertSame(0, $tester->run(['command' => 'scan', '--today' => '2026-10-28', '--prune' => '90']));
        $this->assertStringContainsString('Pruned snapshot 2026-07-01.', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->store . '/2026-07-01.json');
        $this->assertFileExists($this->store . '/2026-08-01.json');
        $this->assertFileExists($this->store . '/2026-10-28.json');
    }

    public function test_scan_with_a_bare_prune_uses_the_configured_retention_days(): void
    {
        $tester = $this->tester(['ROLLCALL_RETENTION_DAYS' => '30']);
        $tester->run(['command' => 'scan', '--today' => '2026-09-01']);

        // A bare --prune (no value) means "use ROLLCALL_RETENTION_DAYS".
        $this->assertSame(0, $tester->run(['command' => 'scan', '--today' => '2026-10-28', '--prune' => null]));
        $this->assertStringContainsString('Pruned snapshot 2026-09-01.', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->store . '/2026-09-01.json');
        $this->assertFileExists($this->store . '/2026-10-28.json');
    }

    public function test_prune_rejects_a_bad_days_value(): void
    {
        $this->assertSame(2, $this->tester()->run(['command' => 'prune', '--days' => '-5', '--today' => '2026-10-28']));
        $this->assertSame(2, $this->tester()->run(['command' => 'prune', '--days' => 'soon', '--today' => '2026-10-28']));
        $this->assertSame(2, $this->tester()->run(['command' => 'scan', '--today' => '2026-10-28', '--prune' => 'soon']));
        $this->assertSame(2, $this->tester(['ROLLCALL_RETENTION_DAYS' => 'soon'])->run(['command' => 'prune', '--today' => '2026-10-28']));
    }
}
