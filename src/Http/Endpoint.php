<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Http;

use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Metrics\PrometheusRenderer;
use Waynestate\Rollcall\Report\JsonRenderer;
use Waynestate\Rollcall\RollcallException;

/**
 * The framework-free HTTP face: GET /metrics and GET /report.json, as a plain
 * function from request facts to a response triple. public/index.php and the
 * Laravel adapter both call it, so the rules (off by default, token or
 * allowlist, public counts only) live in one place.
 *
 * Both routes read the latest stored snapshot. Neither runs a scan: a web
 * request must never be the thing that talks to GitLab.
 */
final class Endpoint
{
    public function __construct(private readonly Config $config, private readonly Factory $factory) {}

    /** @return array{int, array<string, string>, string} status, headers, body */
    public function handle(string $method, string $path, ?string $authorization, ?string $remoteAddress): array
    {
        $headers = ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
        if ($method !== 'GET' && $method !== 'HEAD') {
            return [405, $headers + ['Allow' => 'GET, HEAD'], "Method not allowed.\n"];
        }

        try {
            return match ($path) {
                '/metrics' => $this->metrics($headers, $authorization, $remoteAddress),
                '/report.json' => $this->report($headers),
                default => [404, $headers, "Not found.\n"],
            };
        } catch (RollcallException) {
            // The reason can name a path or a setting; the caller gets a plain 503 and the log gets the detail.
            return [503, $headers, "ROLLCALL is not ready.\n"];
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{int, array<string, string>, string}
     */
    private function metrics(array $headers, ?string $authorization, ?string $remote): array
    {
        // Off by default, and indistinguishable from a missing route while off.
        if (! $this->config->metricsEnabled) {
            return [404, $headers, "Not found.\n"];
        }
        if (! $this->factory->metricsGuard()->allows($authorization, $remote)) {
            return [401, $headers + ['WWW-Authenticate' => 'Bearer'], "Unauthorized.\n"];
        }
        $report = $this->publicReport();
        if ($report === null) {
            return [503, $headers, "No scan yet.\n"];
        }

        return [200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'] + $headers, (new PrometheusRenderer())->render($report)];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{int, array<string, string>, string}
     */
    private function report(array $headers): array
    {
        if (! $this->config->publicReportEnabled) {
            return [404, $headers, "Not found.\n"];
        }
        $report = $this->publicReport();
        if ($report === null) {
            return [503, $headers, "No scan yet.\n"];
        }

        return [200, ['Content-Type' => 'application/json; charset=utf-8'] + $headers, (new JsonRenderer())->render($report) . "\n"];
    }

    /** @return array<string, mixed>|null */
    private function publicReport(): ?array
    {
        $store = $this->factory->store();
        $latest = $store->latest();
        if ($latest === null) {
            return null;
        }

        return $this->factory->reportBuilder()->build($latest, $store->all(), (new SystemClock())->now(), false);
    }
}
