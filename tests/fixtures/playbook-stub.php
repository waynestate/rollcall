<?php

declare(strict_types=1);

namespace Waynestate\Playbook;

/**
 * Test double for the private waynestate/playbook package, which CI never
 * installs. It mirrors the real API: get() returns null for a missing or
 * empty value. Values set here are fake (example.test hosts only).
 */
final class Playbook
{
    /** @var array<string, string> */
    private static array $values = [];

    /** @param  array<string, string>  $values */
    public static function setValues(array $values): void
    {
        self::$values = $values;
    }

    public static function get(string $key): ?string
    {
        $value = self::$values[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
