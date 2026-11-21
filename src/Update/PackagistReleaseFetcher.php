<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Update;

/**
 * The default fetcher: Packagist's p2 endpoint over a stream context with a
 * total budget of about 1.5 s (1 s to connect). Any failure becomes null and
 * the caller stays silent; this class never throws.
 */
final class PackagistReleaseFetcher implements ReleaseFetcher
{
    public const URL = 'https://repo.packagist.org/p2/waynestate/rollcall.json';

    public function fetch(): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n",
                'timeout' => 1.5,
                'connect_timeout' => 1,
                'ignore_errors' => true,
            ],
        ]);

        set_error_handler(static fn(): bool => true);
        try {
            $body = file_get_contents(self::URL, false, $context);
        } finally {
            restore_error_handler();
        }

        if (! is_string($body) || $body === '') {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
