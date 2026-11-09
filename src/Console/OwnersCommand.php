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
 * gets named, and so does every app whose teacher has not checked in for
 * ROLLCALL_OWNERS_CONFIRM_DAYS (a name on last year's seating chart is not a
 * teacher). Both are "owner unknown", never guessed from commit authors, and
 * while one is left the command exits 1, so the weekly scan job fails
 * instead of moving on.
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
            ->setDescription('List the scanned apps whose owner is unknown (no owners-file entry, or none confirmed within ROLLCALL_OWNERS_CONFIRM_DAYS); exit 1 when there is one.')
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
        $ids = array_map(static fn(AppFacts $app): string => $app->id, $latest->apps);
        $unowned = $owners->unowned($ids);
        $days = $config->ownersConfirmDays;
        $unconfirmed = $owners->unconfirmed($ids, $clock->now(), $days);

        if ($format === 'json') {
            $output->write((new JsonRenderer())->render([
                'scanned' => $scanned,
                'owners' => $ownerCount,
                'opted_in' => $optedIn,
                'unowned' => $unowned,
                'confirm_days' => $days,
                'unconfirmed' => $unconfirmed,
                'owner_unknown' => count($unowned) + count($unconfirmed),
            ]) . "\n", false, OutputInterface::OUTPUT_RAW);
        } else {
            $output->write($this->text($scanned, $ownerCount, $optedIn, $unowned, $days, $unconfirmed), false, OutputInterface::OUTPUT_RAW);
        }

        return $unowned === [] && $unconfirmed === [] ? 0 : 1;
    }

    /**
     * @param  list<string>  $unowned
     * @param  list<array{app: string, last_confirmed: string|null}>  $unconfirmed
     */
    private function text(int $scanned, int $ownerCount, int $optedIn, array $unowned, int $days, array $unconfirmed): string
    {
        $noun = $scanned === 1 ? 'app' : 'apps';
        $verb = $scanned === 1 ? 'has' : 'have';
        $lines = [sprintf('Owners file: %d owners, %d opted in to the digest', $ownerCount, $optedIn)];
        $lines[] = $days > 0
            ? sprintf('An owner counts as confirmed for %d days after its "confirmed" date.', $days)
            : 'Confirmation check: off (ROLLCALL_OWNERS_CONFIRM_DAYS=0).';
        if ($unowned === [] && $unconfirmed === []) {
            $lines[] = $days > 0
                ? sprintf('All %d scanned %s %s an owner on file, confirmed in the last %d days.', $scanned, $noun, $verb, $days)
                : sprintf('All %d scanned %s %s an owner on file.', $scanned, $noun, $verb);

            return implode("\n", $lines) . "\n";
        }
        if ($unowned !== []) {
            $lines[] = sprintf('%d of %d scanned %s %s no owner on file:', count($unowned), $scanned, $noun, $verb);
            foreach ($unowned as $id) {
                $lines[] = '- ' . $id;
            }
        }
        if ($unconfirmed !== []) {
            $lines[] = sprintf(
                '%d of %d scanned %s %s an owner on file that nobody confirmed in the last %d days:',
                count($unconfirmed),
                $scanned,
                $noun,
                $verb,
                $days,
            );
            foreach ($unconfirmed as $row) {
                $lines[] = sprintf('- %s (%s)', $row['app'], $row['last_confirmed'] === null ? 'never confirmed' : 'last confirmed ' . $row['last_confirmed']);
            }
        }
        $unknown = count($unowned) + count($unconfirmed);
        $lines[] = sprintf(
            'Owner unknown for %d of %d scanned %s. Ask who answers for %s, then list or re-date %s in the owners file.',
            $unknown,
            $scanned,
            $noun,
            $unknown === 1 ? 'it' : 'them',
            $unknown === 1 ? 'it' : 'them',
        );

        return implode("\n", $lines) . "\n";
    }
}
