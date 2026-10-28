<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Symfony\Component\Console\Application as BaseApplication;
use Waynestate\Rollcall\Report\ReportBuilder;

/** `rollcall scan | report | drift | prune`. Present! */
final class Application extends BaseApplication
{
    /** @param  array<string, string|false|null>  $env */
    public function __construct(array $env = [])
    {
        parent::__construct('rollcall', ReportBuilder::VERSION);
        $this->add(new ScanCommand($env));
        $this->add(new ReportCommand($env));
        $this->add(new DriftCommand($env));
        $this->add(new PruneCommand($env));
    }
}
