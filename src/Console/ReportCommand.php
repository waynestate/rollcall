<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Metrics\PrometheusRenderer;
use Waynestate\Rollcall\Report\JsonRenderer;
use Waynestate\Rollcall\Report\TextRenderer;
use Waynestate\Rollcall\RollcallException;

/** Prints the report from the stored snapshots: text table, JSON, or Prometheus text (off unless enabled). */
final class ReportCommand extends RollcallCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('report')
            ->setDescription('Print the fleet report from the stored snapshots.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text, json or prometheus.', 'text')
            ->addOption('internal', null, InputOption::VALUE_NONE, 'Include the per-app list. Internal only: do not publish it.');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int
    {
        $format = (string) $input->getOption('format');
        if (! in_array($format, ['text', 'json', 'prometheus'], true)) {
            throw new RollcallException(sprintf('Invalid format "%s". Allowed values: text, json, prometheus.', $format));
        }
        if ($format === 'prometheus' && ! $config->metricsEnabled) {
            throw new RollcallException('Metrics are off. Set ROLLCALL_METRICS_ENABLED=true to print them.');
        }

        $store = $factory->store();
        $latest = $store->latest();
        if ($latest === null) {
            throw new RollcallException('No scan yet. Run `rollcall scan` first.');
        }
        $internal = (bool) $input->getOption('internal') && $format !== 'prometheus';
        $report = $factory->reportBuilder()->build($latest, $store->all(), $clock->now(), $internal);

        $output->write(match ($format) {
            'json' => (new JsonRenderer())->render($report) . "\n",
            'prometheus' => (new PrometheusRenderer())->render($report),
            default => (new TextRenderer())->render($report),
        }, false, OutputInterface::OUTPUT_RAW);

        return 0;
    }
}
