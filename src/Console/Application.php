<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Waynestate\Rollcall\Report\ReportBuilder;

/** `rollcall scan | report | drift | advisories | prune`. Present! */
final class Application extends BaseApplication
{
    /** @param  array<string, string|false|null>  $env */
    public function __construct(array $env = [])
    {
        parent::__construct('rollcall', ReportBuilder::VERSION);
        $this->addCommand(new ScanCommand($env));
        $this->addCommand(new ReportCommand($env));
        $this->addCommand(new DriftCommand($env));
        $this->addCommand(new AdvisoriesCommand($env));
        $this->addCommand(new PruneCommand($env));
    }
}
