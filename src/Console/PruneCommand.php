<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;

/** Deletes old snapshots. The newest one always stays, so the dashboard never goes empty. */
final class PruneCommand extends RollcallCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('prune')->setDescription('Delete snapshots older than the retention window; the newest is never deleted.');
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention window in days (default: ROLLCALL_RETENTION_DAYS, 90; 0 keeps everything).');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without deleting anything.');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int
    {
        $days = $this->retentionDays($input, $config, 'days');
        $dryRun = (bool) $input->getOption('dry-run');
        $gone = $factory->store()->prune($days, $clock->now(), $dryRun);

        foreach ($gone as $date) {
            $output->writeln(sprintf('%s snapshot %s.', $dryRun ? 'Would prune' : 'Pruned', $date));
        }
        if ($gone === []) {
            $output->writeln('Nothing to prune.');
        }

        return 0;
    }
}
