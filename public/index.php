<?php

// The raw-PHP front controller: GET /metrics and GET /report.json, nothing else.
// No framework, no scan, no write. Point the web server's document root at this folder.

use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Http\Endpoint;
use Waynestate\Rollcall\RollcallException;

require __DIR__ . '/../vendor/autoload.php';

$env = array_merge($_ENV, getenv());

try {
    $config = Config::fromEnv($env);
} catch (RollcallException $e) {
    error_log('rollcall: ' . $e->getMessage());
    http_response_code(503);
    echo "ROLLCALL is not ready.\n";
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$remote = $_SERVER['REMOTE_ADDR'] ?? null;

[$status, $headers, $body] = (new Endpoint($config, new Factory($config)))->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    is_string($path) ? $path : '/',
    is_string($authorization) ? $authorization : null,
    is_string($remote) ? $remote : null,
);

http_response_code($status);
foreach ($headers as $name => $value) {
    header($name . ': ' . $value);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    echo $body;
}
