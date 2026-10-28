<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Lifecycle\EndOfLifeDrift;
use Waynestate\Rollcall\RollcallException;

/** Compares policy.json with a saved endoflife.date page. A drift check only; it changes nothing. */
final class DriftCommand extends RollcallCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('drift')
            ->setDescription('Compare policy.json with a saved endoflife.date v1 product file.')
            ->addOption('feed', null, InputOption::VALUE_REQUIRED, 'Path to the saved endoflife.date JSON.')
            ->addOption('component', null, InputOption::VALUE_REQUIRED, 'Policy component to compare.', 'php');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int
    {
        $feed = $input->getOption('feed');
        if (! is_string($feed) || ! is_file($feed) || ! is_readable($feed)) {
            throw new RollcallException('Missing or unreadable --feed=<file> (a saved endoflife.date v1 product page).');
        }
        $component = (string) $input->getOption('component');
        $policy = $factory->policy();
        if (! $policy->hasComponent($component)) {
            throw new RollcallException(sprintf('The policy has no component "%s".', $component));
        }

        $drift = (new EndOfLifeDrift($policy))->compare($component, (string) file_get_contents($feed));
        if ($drift === []) {
            $output->writeln(sprintf('No drift: policy.json and the feed agree on every %s date they both state.', $component));

            return 0;
        }
        foreach ($drift as $row) {
            $output->writeln(sprintf('%s %s %s: policy %s, endoflife.date %s', $component, $row['version'], $row['field'], $row['policy'] ?? '-', $row['feed'] ?? '-'), OutputInterface::OUTPUT_RAW);
        }

        return 0;
    }
}
