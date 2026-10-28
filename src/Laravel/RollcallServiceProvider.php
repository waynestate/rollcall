<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Waynestate\Rollcall\Config;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\Http\Endpoint;

/**
 * The Laravel adapter. It builds the core's Config from config/rollcall.php,
 * serves the dashboard and /metrics, and registers `rollcall:scan`. It holds
 * no rules: every number comes from the core's report builder.
 *
 * Routes are off until turned on, and /metrics is off until
 * ROLLCALL_METRICS_ENABLED is true, in Prometheus format, like the rest of the stack.
 */
class RollcallServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/rollcall.php', 'rollcall');

        $this->app->singleton(Config::class, static function (Application $app): Config {
            /** @var array<string, mixed> $c */
            $c = $app->make('config')->get('rollcall', []);
            $str = static fn(string $key): ?string => isset($c[$key]) && is_scalar($c[$key]) && (string) $c[$key] !== '' ? (string) $c[$key] : null;

            // Reuse the core's own env parsing so the CLI and Laravel read every value the same way.
            return Config::fromEnv([
                'ROLLCALL_POLICY_FILE' => $str('policy_file'),
                'ROLLCALL_STORE_DIR' => $str('store_dir'),
                'ROLLCALL_SOURCE' => $str('source'),
                'ROLLCALL_REPOS_DIR' => $str('repos_dir'),
                'ROLLCALL_GITLAB_URL' => $str('gitlab_url'),
                'ROLLCALL_GITLAB_TOKEN' => $str('gitlab_token'),
                'ROLLCALL_GITLAB_GROUPS' => $str('gitlab_groups'),
                'ROLLCALL_IMAGES_FILE' => $str('images_file'),
                'ROLLCALL_PROCTOR_DIR' => $str('proctor_dir'),
                'ROLLCALL_AUTH_PACKAGES' => $str('auth_packages'),
                'ROLLCALL_METRICS_ENABLED' => ($c['metrics_enabled'] ?? false) ? 'true' : null,
                'ROLLCALL_METRICS_TOKEN' => $str('metrics_token'),
                'ROLLCALL_METRICS_ALLOW' => $str('metrics_allow'),
                'ROLLCALL_PUBLIC_REPORT_ENABLED' => ($c['public_report_enabled'] ?? false) ? 'true' : null,
            ]);
        });
        $this->app->singleton(Factory::class, static fn(Application $app): Factory => new Factory($app->make(Config::class)));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../../config/rollcall.php' => $this->app->configPath('rollcall.php')], 'rollcall-config');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'rollcall');

        if ($this->app->runningInConsole()) {
            $this->commands([ScanCommand::class]);
        }

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $config = $this->app->make('config');

        // The scraper endpoint sits on its own path, outside web middleware (no session, no CSRF).
        Route::get('/metrics', function (Request $request): Response {
            $endpoint = new Endpoint($this->app->make(Config::class), $this->app->make(Factory::class));
            $remote = $request->server->get('REMOTE_ADDR');
            [$status, $headers, $body] = $endpoint->handle(
                $request->getMethod(),
                '/metrics',
                $request->headers->get('Authorization'),
                is_string($remote) ? $remote : null,
            );

            return new Response($body, $status, $headers);
        })->name('rollcall.metrics');

        if (! (bool) $config->get('rollcall.dashboard.enabled', false)) {
            return;
        }
        $path = trim((string) $config->get('rollcall.dashboard.path', 'rollcall'), '/');
        /** @var list<string> $middleware */
        $middleware = (array) $config->get('rollcall.dashboard.middleware', ['web']);
        Route::middleware($middleware)->get('/' . $path, [DashboardController::class, 'show'])->name('rollcall.dashboard');

        if ((bool) $config->get('rollcall.dashboard.internal_enabled', false)) {
            /** @var list<string> $internal */
            $internal = (array) $config->get('rollcall.dashboard.internal_middleware', ['web', 'auth.sesame']);
            Route::middleware($internal)->get('/' . $path . '/apps', [DashboardController::class, 'apps'])->name('rollcall.apps');
        }
    }
}
