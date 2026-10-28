<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\RollcallException;

/**
 * Shared plumbing: config from the environment, the --today option, and one
 * place that turns a RollcallException into exit code 2 on stderr.
 * ROLLCALL enforces nothing, so no command ever exits 1 for a "bad" fleet.
 */
abstract class RollcallCommand extends Command
{
    /** @param  array<string, string|false|null>  $env */
    public function __construct(protected readonly array $env = [])
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('today', null, InputOption::VALUE_REQUIRED, "Override today's date (YYYY-MM-DD).");
    }

    /** Runs the command body; subclasses throw RollcallException for expected failures. */
    abstract protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int;

    /**
     * The retention window in days: the option's value if one was given, else
     * the config's. A value must be a whole number of days; 0 keeps everything.
     */
    protected function retentionDays(InputInterface $input, Config $config, string $option): int
    {
        $value = $input->getOption($option);
        if ($value === null || $value === false) {
            return $config->retentionDays;
        }
        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new RollcallException(sprintf('Retention days must be a whole number of days (0 keeps everything), not "%s".', $value));
        }

        return (int) $value;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        try {
            $config = Config::fromEnv($this->env);
            $today = $input->getOption('today');
            $clock = is_string($today) ? new FixedClock($today) : new SystemClock();

            return $this->handle($input, $output, new Factory($config, $clock), $config, $clock);
        } catch (RollcallException $e) {
            $errors->writeln($e->getMessage());

            return 2;
        }
    }
}
