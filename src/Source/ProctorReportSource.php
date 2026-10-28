<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

/**
 * Where PROCTOR's JSON reports are kept (for example as CI artifacts).
 * ROLLCALL reads the report; it never re-runs a check.
 */
interface ProctorReportSource
{
    /** The raw JSON of the app's latest report, or null when there is none. */
    public function report(string $project): ?string;
}
