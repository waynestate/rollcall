<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Waynestate\Rollcall\RollcallException;

/** A clock that always says the same day. */
final class FixedClock implements ClockInterface
{
    private readonly DateTimeImmutable $day;

    public function __construct(string $date)
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($day === false) {
            throw new RollcallException(sprintf('Invalid date "%s". Expected YYYY-MM-DD.', $date));
        }
        $this->day = $day;
    }

    public function now(): DateTimeImmutable
    {
        return $this->day;
    }
}
