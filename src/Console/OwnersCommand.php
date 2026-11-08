<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Model\AppFacts;
use Waynestate\Rollcall\Owner\Owners;
use Waynestate\Rollcall\Report\JsonRenderer;
use Waynestate\Rollcall\RollcallException;

/**
 * The substitute teacher takes the register twice: once from the latest
 * scan, once from the owners file. Every app that answered present to nobody
 * gets named, and while one sits without a teacher the command exits 1, so
 * the weekly scan job fails instead of moving on.
 */
final class OwnersCommand extends RollcallCommand
{
    /** @param  array<string, string|false|null>  $env */
    public function __construct(array $env = [])
    {
        parent::__construct($env);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setName('owners')
            ->setDescription('List the scanned apps that no owners-file entry lists; exit 1 when there is one.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json.', 'text');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int
    {
        $format = (string) $input->getOption('format');
        if (! in_array($format, ['text', 'json'], true)) {
            throw new RollcallException(sprintf('Invalid format "%s". Allowed values: text, json.', $format));
        }
        if ($config->ownersFile === null) {
            throw new RollcallException('No owners file. Set ROLLCALL_OWNERS_FILE to the file that says who answers for which apps.');
        }
        $owners = Owners::fromFile($config->ownersFile);
        $store = $factory->store();
        $latest = $store->latest();
        if ($latest === null) {
            throw new RollcallException('No scan yet. Run `rollcall scan` first.');
        }
        $scanned = count($latest->apps);
        $ownerCount = count($owners->owners);
        $optedIn = count($owners->optedIn());
        $unowned = $owners->unowned(array_map(static fn(AppFacts $app): string => $app->id, $latest->apps));

        if ($format === 'json') {
            $output->write((new JsonRenderer())->render([
                'scanned' => $scanned,
                'owners' => $ownerCount,
                'opted_in' => $optedIn,
                'unowned' => $unowned,
            ]) . "\n", false, OutputInterface::OUTPUT_RAW);
        } else {
            $output->write($this->text($scanned, $ownerCount, $optedIn, $unowned), false, OutputInterface::OUTPUT_RAW);
        }

        return $unowned === [] ? 0 : 1;
    }

    /** @param  list<string>  $unowned */
    private function text(int $scanned, int $ownerCount, int $optedIn, array $unowned): string
    {
        $noun = $scanned === 1 ? 'app' : 'apps';
        $verb = $scanned === 1 ? 'has' : 'have';
        $lines = [sprintf('Owners file: %d owners, %d opted in to the digest', $ownerCount, $optedIn)];
        if ($unowned === []) {
            $lines[] = sprintf('All %d scanned %s %s an owner on file.', $scanned, $noun, $verb);
        } else {
            $lines[] = sprintf('%d of %d scanned %s %s no owner on file:', count($unowned), $scanned, $noun, $verb);
            foreach ($unowned as $id) {
                $lines[] = '- ' . $id;
            }
        }

        return implode("\n", $lines) . "\n";
    }
}
