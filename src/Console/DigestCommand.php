<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Digest\Digest;
use Waynestate\Rollcall\Digest\DigestBuilder;
use Waynestate\Rollcall\Digest\DigestMailer;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\Owner\Owners;
use Waynestate\Rollcall\Report\JsonRenderer;
use Waynestate\Rollcall\Report\ReportBuilder;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Storage\DigestFileWriter;

/**
 * The opt-in digest: each owner who asked gets plain words about only their
 * own apps, and only when something changed since the previous scan. Prints
 * by default; --out writes files; --send mails, and only when sending is also
 * switched on in the environment. Nobody is ever mailed by accident.
 */
final class DigestCommand extends RollcallCommand
{
    /** @param  array<string, string|false|null>  $env */
    public function __construct(array $env = [], private readonly ?TransportInterface $transport = null)
    {
        parent::__construct($env);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setName('digest')
            ->setDescription('Print (or write, or send) each opted-in owner\'s digest of what changed in their apps.')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'Only this contact from the owners file.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text or json.', 'text')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Write one text file per digest into this directory instead of printing them.')
            ->addOption('send', null, InputOption::VALUE_NONE, 'Mail the digests. Needs ROLLCALL_DIGEST_MAIL_ENABLED=true, ROLLCALL_DIGEST_MAIL_DSN and ROLLCALL_DIGEST_MAIL_FROM.');
    }

    protected function handle(InputInterface $input, OutputInterface $output, Factory $factory, Config $config, ClockInterface $clock): int
    {
        $format = (string) $input->getOption('format');
        if (! in_array($format, ['text', 'json'], true)) {
            throw new RollcallException(sprintf('Invalid format "%s". Allowed values: text, json.', $format));
        }
        $send = (bool) $input->getOption('send');
        $mailer = $send ? $this->mailer($config) : null;
        if ($config->ownersFile === null) {
            throw new RollcallException('No owners file. Set ROLLCALL_OWNERS_FILE to the file that says who answers for which apps; nobody gets a digest until they opt in there.');
        }
        $owners = Owners::fromFile($config->ownersFile);
        $chosen = $owners->optedIn();
        $only = $input->getOption('owner');
        if (is_string($only)) {
            $owner = $owners->find($only);
            if ($owner === null) {
                throw new RollcallException(sprintf('No owner "%s" in the owners file.', $only));
            }
            if (! $owner->digest) {
                throw new RollcallException(sprintf('"%s" has not opted in (set "digest": true in the owners file). Rollcall sends nothing to an owner who has not asked.', $only));
            }
            $chosen = [$owner];
        }

        $store = $factory->store();
        $latest = $store->latest();
        if ($latest === null) {
            throw new RollcallException('No scan yet. Run `rollcall scan` first.');
        }
        $history = $store->all();
        $previous = $this->previousOf($latest, $history);
        $builder = $factory->reportBuilder();
        $current = $builder->build($latest, $history, $clock->now(), true);
        $before = $previous === null ? null : $builder->build($previous, $this->upTo($previous, $history), $previous->date, true);

        $digests = (new DigestBuilder())->build($chosen, $current, $before);
        $news = array_values(array_filter($digests, static fn(Digest $d): bool => $d->hasNews()));
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($mailer !== null) {
            foreach ($news as $digest) {
                if (filter_var($digest->owner->contact, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RollcallException(sprintf('The contact "%s" is not a mail address, so nothing was sent. Fix the owners file, or print the digests without --send.', $digest->owner->contact));
                }
            }
        }

        $out = $input->getOption('out');
        if (is_string($out) && $out !== '') {
            $files = (new DigestFileWriter($out))->write($news);
            $errors->writeln(sprintf('Wrote %d digest %s to %s.', count($files), count($files) === 1 ? 'file' : 'files', $out));
        } else {
            $output->write($format === 'json' ? $this->json($latest, $previous, count($chosen), $news) : $this->text($news), false, OutputInterface::OUTPUT_RAW);
        }

        if ($mailer !== null) {
            foreach ($news as $digest) {
                $mailer->send($digest);
            }
            $errors->writeln(sprintf('Sent %d %s.', count($news), count($news) === 1 ? 'digest' : 'digests'));
        }
        $errors->writeln(sprintf(
            '%d of %d opted-in %s had news; the other %d get nothing this time.',
            count($news),
            count($chosen),
            count($chosen) === 1 ? 'owner' : 'owners',
            count($chosen) - count($news),
        ));

        return 0;
    }

    private function mailer(Config $config): DigestMailer
    {
        if (! $config->digestMailEnabled) {
            throw new RollcallException('Sending is off. Set ROLLCALL_DIGEST_MAIL_ENABLED=true, ROLLCALL_DIGEST_MAIL_DSN and ROLLCALL_DIGEST_MAIL_FROM to send; without --send the digests are printed.');
        }
        if ($config->digestMailDsn() === '' || $config->digestMailFrom === null) {
            throw new RollcallException('Sending needs ROLLCALL_DIGEST_MAIL_DSN (a Symfony Mailer DSN) and ROLLCALL_DIGEST_MAIL_FROM (the from address).');
        }

        return $this->transport !== null
            ? DigestMailer::withTransport($this->transport, $config->digestMailFrom)
            : DigestMailer::fromDsn($config->digestMailDsn(), $config->digestMailFrom);
    }

    /** @param  list<Snapshot>  $history */
    private function previousOf(Snapshot $current, array $history): ?Snapshot
    {
        $previous = null;
        foreach ($history as $snapshot) {
            if ($snapshot->date < $current->date) {
                $previous = $snapshot;
            }
        }

        return $previous;
    }

    /**
     * @param  list<Snapshot>  $history
     * @return list<Snapshot>
     */
    private function upTo(Snapshot $last, array $history): array
    {
        return array_values(array_filter($history, static fn(Snapshot $s): bool => $s->date <= $last->date));
    }

    /** @param  list<Digest>  $news */
    private function text(array $news): string
    {
        $parts = [];
        foreach ($news as $digest) {
            $parts[] = 'To: ' . $digest->owner->contact . "\n" . 'Subject: ' . $digest->subject() . "\n\n" . $digest->body();
        }

        return implode("\n----\n\n", $parts);
    }

    /** @param  list<Digest>  $news */
    private function json(Snapshot $latest, ?Snapshot $previous, int $optedIn, array $news): string
    {
        return (new JsonRenderer())->render([
            'tool' => 'rollcall',
            'version' => ReportBuilder::version(),
            'visibility' => 'internal',
            'scan_date' => $latest->date->format('Y-m-d'),
            'since' => $previous?->date->format('Y-m-d'),
            'opted_in' => $optedIn,
            'quiet' => $optedIn - count($news),
            'digests' => array_map(static fn(Digest $d): array => $d->toArray(), $news),
        ]) . "\n";
    }
}
