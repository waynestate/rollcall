<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Support;

use Waynestate\Playbook\Playbook;

/**
 * Resolves one WSU value: the real environment wins, then PLAYBOOK
 * (waynestate/playbook) when it is installed, then null.
 *
 * Only values like a server URL come through here. Tokens stay
 * environment-only and are never read through PLAYBOOK.
 */
final class Setting
{
    public static function get(string $key): ?string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        if (class_exists(Playbook::class)) {
            $value = Playbook::get($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
