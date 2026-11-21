<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\Update\PackagistReleaseFetcher;
use Waynestate\Rollcall\Update\UpdateCache;
use Waynestate\Rollcall\Update\UpdateNotice;

/** `rollcall scan | report | digest | owners | drift | advisories | prune`. Present! */
final class Application extends BaseApplication
{
    /** @var array<string, string|false|null> */
    private readonly array $env;

    private readonly ?UpdateNotice $notice;

    /** @param  array<string, string|false|null>  $env */
    public function __construct(array $env = [], ?UpdateNotice $updateNotice = null)
    {
        $this->env = $env;
        $this->notice = $updateNotice;
        parent::__construct('rollcall', ReportBuilder::version());
        $this->addCommand(new ScanCommand($env));
        $this->addCommand(new ReportCommand($env));
        $this->addCommand(new DigestCommand($env));
        $this->addCommand(new OwnersCommand($env));
        $this->addCommand(new DriftCommand($env));
        $this->addCommand(new AdvisoriesCommand($env));
        $this->addCommand(new PruneCommand($env));
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $exitCode = parent::doRunCommand($command, $input, $output);
        $this->updateNotice($output);

        return $exitCode;
    }

    /**
     * One stderr line when a newer release is out. Buffered and test output
     * has no separate stderr stream, so it stays silent there.
     */
    private function updateNotice(OutputInterface $output): void
    {
        if (! $output instanceof ConsoleOutputInterface) {
            return;
        }
        $cache = UpdateCache::fromEnv($this->env);
        if ($cache === null) {
            return;
        }
        $notice = $this->notice ?? new UpdateNotice(
            new PackagistReleaseFetcher(),
            new SystemClock(),
            $cache,
            dirname(__DIR__, 2),
            inPhar: \Phar::running(false) !== '',
        );
        $stderr = $output->getErrorOutput();
        $notice->run($this->env, self::argv(), ReportBuilder::version(), static fn(string $line) => $stderr->writeln($line));
    }

    /** @return list<string> */
    private static function argv(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        if (! is_array($argv)) {
            return [];
        }

        return array_values(array_filter($argv, 'is_string'));
    }
}
