<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

/**
 * Where each app's runtime image tag comes from (on Citadel, its app list).
 * Read-only. Null means "this source does not know the app", not "no image".
 */
interface ImageSource
{
    public function imageTag(string $project): ?string;
}
