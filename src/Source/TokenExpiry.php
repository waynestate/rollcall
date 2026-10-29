<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

use DateTimeImmutable;

/**
 * A source that can say when its own credential expires, if it has one.
 * Advisory by contract: null means "never expires" or "cannot be read", and
 * no caller may fail a scan over the answer (or over a lookup that fails).
 */
interface TokenExpiry
{
    public function tokenExpiry(): ?DateTimeImmutable;
}
