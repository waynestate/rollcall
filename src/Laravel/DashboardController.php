<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Laravel;

use Illuminate\Contracts\View\View;
use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Factory;
use Waynestate\Rollcall\RollcallException;

/** Renders the report the core built. No rule lives here. */
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
        $css = config('rollcall.wayneui_css');
        $css = is_string($css) && preg_match('#^(https://|/)[^\s"<>]+$#', $css) === 1 ? $css : null;

        try {
            $store = $factory->store();
            $latest = $store->latest();
            $report = $latest === null
                ? null
                : $factory->reportBuilder()->build($latest, $store->all(), (new SystemClock())->now(), $internal);
        } catch (RollcallException) {
            $report = null;
        }

        return view('rollcall::dashboard', ['report' => $report, 'internal' => $internal, 'css' => $css]);
    }
}
