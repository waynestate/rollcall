<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Lifecycle;

use Waynestate\Rollcall\Creed\State;

/**
 * The words ROLLCALL's report uses for a version's support state.
 *
 * CREED's reader answers Active, Security, Eol or EolGraceExpired, or null when
 * the policy cannot answer (a version it does not list). ROLLCALL reports in
 * its own vocabulary: security and security-only are one bucket, EOL and
 * past-grace are one bucket, and a question the policy cannot answer is
 * unknown.
 */
enum Support: string
{
    case Active = 'active';
    case SecurityOnly = 'security_only';
    case Eol = 'eol';
    case Unknown = 'unknown';

    /** CREED's answer, in ROLLCALL's words: null is unknown, the two EOL buckets collapse. */
    public static function fromTryStateOf(?State $state): self
    {
        return match ($state) {
            null => self::Unknown,
            State::Active => self::Active,
            State::Security => self::SecurityOnly,
            State::Eol, State::EolGraceExpired => self::Eol,
        };
    }

    /** A supported version still gets security fixes (or better). */
    public function isSupported(): bool
    {
        return $this === self::Active || $this === self::SecurityOnly;
    }
}
