<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Laravel;

use Illuminate\Console\Command;
use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\RollcallException;

/** `php artisan rollcall:prune`: delete old snapshots; the newest always stays. */
class PruneCommand extends Command
{
    protected $signature = 'rollcall:prune {--days= : Retention window in days (default: ROLLCALL_RETENTION_DAYS, 90; 0 keeps everything)} {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Delete snapshots older than the retention window; the newest is never deleted.';

    public function handle(Factory $factory, Config $config): int
    {
        try {
            $option = $this->option('days');
            if ($option === null) {
                $days = $config->retentionDays;
            } else {
                $days = is_scalar($option) ? (string) $option : '';
                if (preg_match('/^\d+$/', $days) !== 1) {
                    throw new RollcallException(sprintf('Retention days must be a whole number of days (0 keeps everything), not "%s".', $days));
                }
                $days = (int) $days;
            }
            $dryRun = (bool) $this->option('dry-run');
            $gone = $factory->store()->prune($days, (new SystemClock())->now(), $dryRun);
        } catch (RollcallException $e) {
            $this->error($e->getMessage());

            return 2;
        }
        foreach ($gone as $date) {
            $this->info(sprintf('%s snapshot %s.', $dryRun ? 'Would prune' : 'Pruned', $date));
        }
        if ($gone === []) {
            $this->info('Nothing to prune.');
        }

        return 0;
    }
}
