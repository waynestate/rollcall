<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

/**
 * An image source that also knows what runs inside the container (Citadel's
 * export may carry the PHP and httpd versions). Optional: a source that
 * cannot answer simply does not implement it.
 */
interface RuntimeVersionSource
{
    /** The PHP version in the running container, such as "8.3.14", or null. */
    public function runtimePhp(string $project): ?string;

    /** The Apache httpd version in the running container, such as "2.4.62", or null. */
    public function runtimeHttpd(string $project): ?string;
}
