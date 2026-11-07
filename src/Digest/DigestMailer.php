<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Digest;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Waynestate\Rollcall\RollcallException;

/**
 * Sends digests through Symfony Mailer (suggested, not required: install
 * symfony/mailer to use it). Plain text only. The one outbound action in
 * Rollcall, and only with `rollcall digest --send` plus
 * ROLLCALL_DIGEST_MAIL_ENABLED=true, a DSN and a from address.
 */
final class DigestMailer
{
    private function __construct(private readonly TransportInterface $transport, private readonly Address $from) {}

    public static function available(): bool
    {
        return class_exists(Mailer::class) && class_exists(Email::class);
    }

    public static function fromDsn(string $dsn, string $from): self
    {
        if (! self::available()) {
            throw new RollcallException('Sending digests needs symfony/mailer. Run `composer require symfony/mailer`, or leave out --send and print the digests.');
        }
        try {
            $transport = Transport::fromDsn($dsn);
        } catch (\Throwable) {
            // The DSN can hold a password: never echo it back.
            throw new RollcallException('ROLLCALL_DIGEST_MAIL_DSN is not a mailer DSN Symfony Mailer understands (for example smtp://mail.example.test:25).');
        }

        return self::withTransport($transport, $from);
    }

    public static function withTransport(TransportInterface $transport, string $from): self
    {
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new RollcallException('ROLLCALL_DIGEST_MAIL_FROM must be one mail address.');
        }

        return new self($transport, new Address($from));
    }

    public function send(Digest $digest): void
    {
        if (filter_var($digest->owner->contact, FILTER_VALIDATE_EMAIL) === false) {
            throw new RollcallException(sprintf('The contact "%s" is not a mail address; print its digest instead of sending it.', $digest->owner->contact));
        }
        $email = (new Email())
            ->from($this->from)
            ->to(new Address($digest->owner->contact))
            ->subject($digest->subject())
            ->text($digest->body());
        try {
            (new Mailer($this->transport))->send($email);
        } catch (TransportExceptionInterface) {
            throw new RollcallException(sprintf('The mail server refused the digest for "%s"; nothing else was sent after it.', $digest->owner->contact));
        }
    }
}
