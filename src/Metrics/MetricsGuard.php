<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Metrics;

/**
 * Decides whether a /metrics request may be answered. It fails
 * closed: with no token and no allowlist configured, nobody is let in.
 *
 * A request passes with the right bearer token or from an allowlisted
 * address. The token is compared in constant time.
 */
final class MetricsGuard
{
    /** @param  list<string>  $allowedAddresses  exact IP addresses of scrapers */
    public function __construct(
        private readonly string $token = '',
        private readonly array $allowedAddresses = [],
    ) {}

    public function allows(?string $authorizationHeader, ?string $remoteAddress): bool
    {
        if ($this->token !== '' && $authorizationHeader !== null && preg_match('/^Bearer (\S+)$/', $authorizationHeader, $m) === 1 && hash_equals($this->token, $m[1])) {
            return true;
        }

        return $remoteAddress !== null && $remoteAddress !== '' && in_array($remoteAddress, $this->allowedAddresses, true);
    }
}
