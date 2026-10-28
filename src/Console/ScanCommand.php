<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;

/** Calls the roll once and stores the snapshot. Reads sources; writes only the snapshot directory. */
final class ScanCommand extends RollcallCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('scan')->setDescription('Scan the fleet read-only and store a snapshot.');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int
    {
        $store = $factory->store();
        $snapshot = $factory->scanner()->scan($store->latest());
        $store->save($snapshot);

        $output->writeln(sprintf('Present: %d apps. Snapshot %s saved to %s.', count($snapshot->apps), $snapshot->date->format('Y-m-d'), $config->storeDir));
        foreach ($snapshot->errors as $error) {
            $output->writeln('Note: ' . $error);
        }

        return 0;
    }
}
