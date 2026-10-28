<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/** The real clock. Tests and `--today` use a fixed one so dates never drift under a test. */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }
}
