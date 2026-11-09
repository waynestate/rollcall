<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Laravel;

use Illuminate\Contracts\View\View;
use Waynestate\Rollcall\Dashboard\DashboardPage;
use Waynestate\Rollcall\Factory;

/**
 * Renders the report the core built. No rule lives here, and no markup: the
 * view prints DashboardPage, the same renderer the raw-PHP page uses.
 */
class DashboardController
{
    public function show(Factory $factory): View
    {
        return $this->page($factory, false);
    }

    public function apps(Factory $factory): View
    {
        return $this->page($factory, true);
    }

    private function page(Factory $factory, bool $internal): View
    {
        return view('rollcall::dashboard', [
            'report' => DashboardPage::report($factory, $internal),
            'internal' => $internal,
            'css' => DashboardPage::stylesheet(config('rollcall.wayneui_css')),
        ]);
    }
}
