<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Parse;

/**
 * Pulls the PHP minor out of an image tag such as "php:8.4-apache" or
 * "php-8.4". A tag that does not name a PHP version gives null, never a guess.
 */
final class ImageTag
{
    public static function phpVersion(string $tag): ?string
    {
        if (strlen($tag) > 200) {
            return null;
        }
        if (preg_match('/(?<![\d.])(\d{1,2})\.(\d{1,2})(?![\d])/', $tag, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }
        if (preg_match('/php[-_]?(\d)(\d{1,2})(?!\d)/i', $tag, $m) === 1) {
            return $m[1] . '.' . $m[2];
        }

        return null;
    }
}
