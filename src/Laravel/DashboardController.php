<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Laravel;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Inertia\Inertia;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Waynestate\Rollcall\Dashboard\DashboardPage;
use Waynestate\Rollcall\Factory;

/**
 * Renders the report the core built. No rule lives here, and no markup: the
 * view prints DashboardPage, the same renderer the raw-PHP page uses, and the
 * Inertia page hands the same report to the Vue component as props.
 */
class DashboardController
{
    public function show(Factory $factory, Request $request): View|Response
    {
        return $this->page($factory, $request, false);
    }

    public function apps(Factory $factory, Request $request): View|Response
    {
        return $this->page($factory, $request, true);
    }

    private function page(Factory $factory, Request $request, bool $internal): View|Response
    {
        $report = DashboardPage::report($factory, $internal);

        if ((bool) config('rollcall.dashboard.inertia', false)) {
            return $this->inertia($request, $report, $internal);
        }

        return view('rollcall::dashboard', [
            'report' => $report,
            'internal' => $internal,
            'css' => DashboardPage::stylesheet(config('rollcall.wayneui_css')),
        ]);
    }

    /**
     * The same report as the Blade page, as the props of the app's Inertia page
     * (resources/js/pages/Rollcall/Dashboard.vue once published). The app's
     * layout supplies the stylesheet, so ROLLCALL_WAYNEUI_CSS is not read here.
     *
     * @param  array<string, mixed>|null  $report
     */
    private function inertia(Request $request, ?array $report, bool $internal): Response
    {
        // ::class names the facade without loading it, so an app without inertiajs/inertia-laravel gets this sentence, not a fatal error.
        if (! class_exists(Inertia::class)) {
            throw new LogicException('ROLLCALL_DASHBOARD_INERTIA is on, but inertiajs/inertia-laravel is not installed. Install it, or turn the setting off for the Blade page.');
        }

        $page = config('rollcall.dashboard.inertia_page', 'Rollcall/Dashboard');

        $inertia = Inertia::render(is_string($page) && $page !== '' ? $page : 'Rollcall/Dashboard', [
            'report' => $report,
            'internal' => $internal,
            'scanCommand' => DashboardPage::LARAVEL_SCAN,
        ]);

        $response = $inertia->toResponse($request);
        // The Blade page says noindex in a meta tag; an Inertia visit may never load a full page, so the header says it every time.
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
