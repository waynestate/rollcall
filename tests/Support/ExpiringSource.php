<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Support;

use DateTimeImmutable;
use Waynestate\Rollcall\Source\RepoFile;
use Waynestate\Rollcall\Source\RepositorySource;
use Waynestate\Rollcall\Source\TokenExpiry;

/** Wraps a source and adds a token expiry date, for console tests that stand in for GitLab. */
final class ExpiringSource implements RepositorySource, TokenExpiry
{
    public function __construct(private readonly RepositorySource $inner, private readonly ?string $expiresAt) {}

    public function projects(): array
    {
        return $this->inner->projects();
    }

    public function file(string $project, string $path, ?string $knownBlobId = null): RepoFile
    {
        return $this->inner->file($project, $path, $knownBlobId);
    }

    public function tokenExpiry(): ?DateTimeImmutable
    {
        if ($this->expiresAt === null) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->expiresAt);

        return $date === false ? null : $date;
    }
}
