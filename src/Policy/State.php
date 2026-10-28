<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Policy;

/**
 * Where a version stands on a given day. ROLLCALL never decides this itself;
 * the dates in CREED's policy.json do, the stack's version policy.
 */
enum State: string
{
    case Active = 'active';
    case SecurityOnly = 'security_only';
    case Eol = 'eol';
    case Unknown = 'unknown';

    /** A supported version still gets security fixes (or better). */
    public function isSupported(): bool
    {
        return $this === self::Active || $this === self::SecurityOnly;
    }
}
