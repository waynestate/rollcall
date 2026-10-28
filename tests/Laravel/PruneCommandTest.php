<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Laravel;

use Illuminate\Support\Facades\Artisan;
use Waynestate\Rollcall\Storage\JsonFileSnapshotStore;
use Waynestate\Rollcall\Tests\Support\Fleet;

final class PruneCommandTest extends LaravelTestCase
{
    public function test_the_prune_command_deletes_older_snapshots_and_keeps_the_newest(): void
    {
        // 2020-01-01 is older than any 90-day window; 2026-10-28 is the newest and always stays.
        (new JsonFileSnapshotStore($this->store))->save(Fleet::snapshot('2020-01-01'));

        $this->assertSame(0, Artisan::call('rollcall:prune', ['--days' => '90']));
        $this->assertStringContainsString('Pruned snapshot 2020-01-01.', Artisan::output());
        $this->assertFileDoesNotExist($this->store . '/2020-01-01.json');
        $this->assertFileExists($this->store . '/2026-10-28.json');
    }

    public function test_the_prune_command_dry_run_deletes_nothing(): void
    {
        (new JsonFileSnapshotStore($this->store))->save(Fleet::snapshot('2020-01-01'));

        $this->assertSame(0, Artisan::call('rollcall:prune', ['--days' => '90', '--dry-run' => true]));
        $this->assertStringContainsString('Would prune snapshot 2020-01-01.', Artisan::output());
        $this->assertFileExists($this->store . '/2020-01-01.json');
        $this->assertFileExists($this->store . '/2026-10-28.json');
    }

    public function test_the_prune_command_with_zero_days_keeps_everything(): void
    {
        (new JsonFileSnapshotStore($this->store))->save(Fleet::snapshot('2020-01-01'));

        $this->assertSame(0, Artisan::call('rollcall:prune', ['--days' => '0']));
        $this->assertStringContainsString('Nothing to prune.', Artisan::output());
        $this->assertFileExists($this->store . '/2020-01-01.json');
    }

    public function test_the_prune_command_rejects_a_bad_days_value(): void
    {
        $this->assertSame(2, Artisan::call('rollcall:prune', ['--days' => 'soon']));
        $this->assertStringContainsString('whole number of days', Artisan::output());
    }
}
