<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Dashboard;

use DateTimeImmutable;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\RollcallException;

/**
 * The dashboard for a raw PHP app, as data: the status, headers and body that
 * answer one request. rollcall_dashboard() sends it; a test or a front
 * controller of your own can call this and send it any way it likes.
 *
 * It follows the Laravel page's switches, read from the same environment
 * variables, so one .env serves both:
 *  - ROLLCALL_DASHBOARD_ENABLED off (the default): 404, indistinguishable
 *    from a missing page, exactly like the Laravel route.
 *  - the internal per-app page needs ROLLCALL_INTERNAL_ENABLED and
 *    'internal' => true from the caller, which sets it only behind its own
 *    sign-in (SESAME's sesame_login(), for example). Either one missing: 404.
 *
 * Every answer carries X-Robots-Tag: noindex, and the page has no script at
 * all, so it sits under HELMET's CSP as is. Pass HELMET's nonce for the one
 * small style element; without it, WayneUI's stylesheet carries the same rule.
 */
final class RawDashboard
{
    /**
     * Options, all optional:
     *  - internal: true for the per-app page; set it only behind your sign-in.
     *  - nonce: the response's CSP nonce, such as helmet_boot()['nonce'].
     *  - env: the settings (default: the process environment).
     *  - method: the request method (default: $_SERVER['REQUEST_METHOD']).
     *  - now: the day the report is read as of (default: today).
     *
     * @param  array{internal?: bool, nonce?: string|null, env?: array<string, string|false|null>, method?: string, now?: DateTimeImmutable}  $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function response(array $options = []): array
    {
        $env = $options['env'] ?? self::environment();
        $internal = ($options['internal'] ?? false) === true;
        $method = $options['method'] ?? (is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET');
        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ];

        // Off by default, and while off it answers like a page that is not there.
        if (! self::on($env, 'ROLLCALL_DASHBOARD_ENABLED') || ($internal && ! self::on($env, 'ROLLCALL_INTERNAL_ENABLED'))) {
            return ['status' => 404, 'headers' => $headers, 'body' => "Not found.\n"];
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return ['status' => 405, 'headers' => $headers + ['Allow' => 'GET, HEAD'], 'body' => "Method not allowed.\n"];
        }

        try {
            $config = Config::fromEnv($env);
        } catch (RollcallException $e) {
            // The reason names a setting; the log gets it and the visitor gets a plain 503.
            error_log('rollcall: ' . $e->getMessage());

            return ['status' => 503, 'headers' => $headers, 'body' => "ROLLCALL is not ready.\n"];
        }

        $stylesheet = isset($env['ROLLCALL_WAYNEUI_CSS']) && is_string($env['ROLLCALL_WAYNEUI_CSS']) ? $env['ROLLCALL_WAYNEUI_CSS'] : null;
        $report = DashboardPage::report(new Factory($config), $internal, $options['now'] ?? null);
        $body = (new DashboardPage(DashboardPage::CLI_SCAN))->render($report, $internal, $stylesheet, $options['nonce'] ?? null);

        return ['status' => 200, 'headers' => ['Content-Type' => 'text/html; charset=utf-8'] + $headers, 'body' => $body];
    }

    /** @param  array<string, string|false|null>  $env */
    private static function on(array $env, string $key): bool
    {
        return Config::truthy(isset($env[$key]) && is_string($env[$key]) ? $env[$key] : null);
    }

    /** @return array<string, string|false|null> */
    private static function environment(): array
    {
        /** @var array<string, string|false|null> $env */
        $env = array_merge($_ENV, getenv());

        return $env;
    }
}
