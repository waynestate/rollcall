<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

/**
 * A platform that schedules forced runtime upgrades (Citadel's base-image
 * upgrades) and can say when an app's next one is due. Optional: an image
 * source that cannot answer simply does not implement it.
 */
interface UpgradeDueSource
{
    /** The due date (YYYY-MM-DD) of the earliest pending forced upgrade of the app's image, or null. */
    public function upgradeDue(string $project): ?string;
}
