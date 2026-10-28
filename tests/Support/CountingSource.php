<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Support;

use Waynestate\Rollcall\Source\RepoFile;
use Waynestate\Rollcall\Source\RepositorySource;

/** Wraps a source and records which files were asked for and whether the body came back. */
final class CountingSource implements RepositorySource
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly RepositorySource $inner) {}

    public function projects(): array
    {
        return $this->inner->projects();
    }

    public function file(string $project, string $path, ?string $knownBlobId = null): RepoFile
    {
        $file = $this->inner->file($project, $path, $knownBlobId);
        $this->calls[] = $project . ':' . $path . ':' . $file->status;

        return $file;
    }
}
