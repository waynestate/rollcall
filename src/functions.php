<?php

declare(strict_types=1);

use Waynestate\Rollcall\Dashboard\RawDashboard;

if (! function_exists('rollcall_dashboard')) {
    /**
     * Serve the Rollcall dashboard from a raw PHP app, and stop.
     *
     * Drop it into a file under the served folder, after the autoloader (and
     * after helmet_boot(), when the app runs HELMET):
     *
     *     <?php
     *     require __DIR__ . '/../vendor/autoload.php';
     *     $helmet = \Waynestate\Helmet\helmet_boot(['preset' => 'citadel']);
     *     rollcall_dashboard(['nonce' => $helmet['nonce']]);
     *
     * It is the same page the Laravel adapter serves, built by the same
     * renderer from the same report. It answers 404 until
     * ROLLCALL_DASHBOARD_ENABLED is true, sends X-Robots-Tag: noindex, and never
     * runs a scan. The options are RawDashboard::response()'s: internal, nonce,
     * env, method and now. 'internal' => true shows the per-app page, and only
     * when ROLLCALL_INTERNAL_ENABLED is true as well; put that file behind the
     * app's sign-in. Everybody present, nobody ranked.
     *
     * @param  array{internal?: bool, nonce?: string|null, env?: array<string, string|false|null>, method?: string, now?: DateTimeImmutable}  $options
     */
    function rollcall_dashboard(array $options = []): never
    {
        $response = RawDashboard::response($options);

        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value, true);
        }
        if (($options['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo $response['body'];
        }
        exit;
    }
}
