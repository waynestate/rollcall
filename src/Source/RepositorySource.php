<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

/**
 * Where the repositories live. Read-only by construction: there is no method
 * here that writes, so no adapter can be asked to.
 */
interface RepositorySource
{
    /**
     * Repository ids, such as "group/project", in a stable order.
     *
     * @return list<string>
     */
    public function projects(): array;

    /**
     * One file at the default branch. When $knownBlobId matches the current
     * blob, the adapter answers "unchanged" without sending the body.
     */
    public function file(string $project, string $path, ?string $knownBlobId = null): RepoFile;
}
