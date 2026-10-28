<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Report;

use Waynestate\Rollcall\RollcallException;

/** The report as one pretty-printed JSON object. */
final class JsonRenderer
{
    /** @param  array<string, mixed>  $report */
    public function render(array $report): string
    {
        try {
            return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RollcallException('Could not encode the report as JSON: ' . $e->getMessage());
        }
    }
}
