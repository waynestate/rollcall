<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Update;

/**
 * Fetches Packagist's p2 metadata for one package. This is the seam that keeps
 * the update notice testable without a network: a test hands over a fetcher
 * that returns a saved array, or null for "the fetch failed".
 */
interface ReleaseFetcher
{
    /**
     * The decoded p2 metadata for the package, or null when the fetch failed
     * for any reason (offline, timeout, refused, malformed body).
     *
     * @return array<string, mixed>|null
     */
    public function fetch(): ?array;
}
