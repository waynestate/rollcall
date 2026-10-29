<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Composer\Semver\VersionParser;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Lifecycle\PackageAdvisories;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\RollcallException;

/**
 * Checks each app's locked laravel/framework and auth-package versions against
 * a saved Packagist security-advisories response. Like `drift`, it reads a
 * saved page and makes no unattended outbound call. Output is package names and
 * counts only: app ids are internal and never printed. A package with no
 * advisory data prints "no data", never "safe".
 */
final class AdvisoriesCommand extends RollcallCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this->setName('advisories')
            ->setDescription('Check locked package versions against a saved Packagist security-advisories response.')
            ->addOption('feed', null, InputOption::VALUE_REQUIRED, 'Path to the saved Packagist security-advisories JSON.');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int
    {
        $feed = $input->getOption('feed');
        if (! is_string($feed) || ! is_file($feed) || ! is_readable($feed)) {
            throw new RollcallException('Missing or unreadable --feed=<file> (a saved Packagist security-advisories response).');
        }
        $latest = $factory->store()->latest();
        if ($latest === null) {
            throw new RollcallException('No scan yet. Run `rollcall scan` first.');
        }

        $lookup = new PackageAdvisories((string) file_get_contents($feed));
        $rows = $this->pairs($latest);
        foreach ($rows as $row) {
            $result = $lookup->check($row['package'], $row['version']);
            $ids = $result['ids'] === [] ? 'none' : implode(', ', $result['ids']);
            $output->writeln(sprintf('%s %s: %s (%d advisories, %s) in %d apps', $row['package'], $row['version'], $result['status'], $result['count'], $ids, $row['apps']), OutputInterface::OUTPUT_RAW);
        }

        return 0;
    }

    /**
     * The distinct (package, version) pairs across all apps, sorted by package
     * then version, with the number of apps that hold each pair.
     *
     * @return list<array{package: string, version: string, apps: int}>
     */
    private function pairs(Snapshot $latest): array
    {
        $rows = [];
        foreach ($latest->apps as $app) {
            $candidates = [
                ['laravel/framework', $app->laravel],
                [$app->authPackage, $app->authVersion],
            ];
            foreach ($candidates as [$package, $version]) {
                if (! is_string($package) || $package === '' || ! is_string($version) || $version === '') {
                    continue;
                }
                $key = strtolower($package) . "\0" . $version;
                $rows[$key]['package'] = $package;
                $rows[$key]['version'] = $version;
                $rows[$key]['apps'] = ($rows[$key]['apps'] ?? 0) + 1;
            }
        }
        $rows = array_values($rows);
        usort($rows, static fn(array $a, array $b): int => self::compare($a, $b));

        return $rows;
    }

    /**
     * @param  array{package: string, version: string}  $a
     * @param  array{package: string, version: string}  $b
     */
    private static function compare(array $a, array $b): int
    {
        $byPackage = strcasecmp($a['package'], $b['package']);
        if ($byPackage !== 0) {
            return $byPackage;
        }
        try {
            return (new VersionParser())->sortVersions($a['version'], $b['version']);
        } catch (\UnexpectedValueException) {
            return strcmp($a['version'], $b['version']);
        }
    }
}
