<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Source\RepositorySource;
use Waynestate\Rollcall\Source\TokenExpiry;

/** Calls the roll once and stores the snapshot. Reads sources; writes only the snapshot directory. */
final class ScanCommand extends RollcallCommand
{
    /** Warn this many days before the token expires; past expiry always warns. */
    private const WARN_DAYS = 14;

    /**
     * @param  array<string, string|false|null>  $env
     * @param  RepositorySource|null  $repositories  a prebuilt source; tests use it to stand in for GitLab
     */
    public function __construct(array $env = [], private readonly ?RepositorySource $repositories = null)
    {
        parent::__construct($env);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setName('scan')->setDescription('Scan the fleet read-only and store a snapshot.');
        // The default is false, not null, so a bare --prune (null) means "use ROLLCALL_RETENTION_DAYS".
        $this->addOption('prune', null, InputOption::VALUE_OPTIONAL, 'After saving, delete snapshots older than this many days (default: ROLLCALL_RETENTION_DAYS, 90; 0 keeps everything).', false);
    }

    protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int
    {
        $store = $factory->store();
        // One source for the whole command: the scan and the expiry warning
        // share it, so repositories() is never called twice.
        $repositories = $this->repositories ?? $factory->repositories();
        $snapshot = $factory->scanner($repositories)->scan($store->latest());
        $store->save($snapshot);

        $output->writeln(sprintf('Present: %d apps. Snapshot %s saved to %s.', count($snapshot->apps), $snapshot->date->format('Y-m-d'), $config->storeDir));
        foreach ($snapshot->errors as $error) {
            $output->writeln('Note: ' . $error);
        }
        $note = $repositories instanceof TokenExpiry ? $this->tokenExpiryNote($repositories, $clock->now()) : null;
        if ($note !== null) {
            $output->writeln($note);
        }

        if ($input->getOption('prune') !== false) {
            $days = $this->retentionDays($input, $config, 'prune');
            foreach ($store->prune($days, $clock->now()) as $date) {
                $output->writeln('Pruned snapshot ' . $date . '.');
            }
        }

        return 0;
    }

    /**
     * The token-expiry warning, or nothing when the date is later than
     * WARN_DAYS or unknown. Advisory: a lookup that fails in any way prints
     * nothing; the scan itself already failed loudly if the token was refused.
     */
    private function tokenExpiryNote(TokenExpiry $source, DateTimeImmutable $now): ?string
    {
        try {
            $expiry = $source->tokenExpiry();
        } catch (RollcallException) {
            return null;
        }
        if ($expiry === null) {
            return null;
        }
        if ($expiry < $now) {
            return sprintf('Note: the GitLab token expired on %s.', $expiry->format('Y-m-d'));
        }
        $daysLeft = $now->diff($expiry)->days;
        if ($daysLeft > self::WARN_DAYS) {
            return null;
        }

        return sprintf('Note: the GitLab token expires on %s (%d days left).', $expiry->format('Y-m-d'), $daysLeft);
    }
}
