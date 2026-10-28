<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Laravel;

use Illuminate\Console\Command;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\RollcallException;

/** `php artisan rollcall:scan`: the same scan as `bin/rollcall scan`, for apps that schedule it. */
class ScanCommand extends Command
{
    protected $signature = 'rollcall:scan';

    protected $description = 'Scan the fleet read-only and store a snapshot.';

    public function handle(Factory $factory, Config $config): int
    {
        try {
            $store = $factory->store();
            $snapshot = $factory->scanner()->scan($store->latest());
            $store->save($snapshot);
        } catch (RollcallException $e) {
            $this->error($e->getMessage());

            return 2;
        }
        $this->info(sprintf('Present: %d apps. Snapshot %s saved.', count($snapshot->apps), $snapshot->date->format('Y-m-d')));

        return 0;
    }
}
