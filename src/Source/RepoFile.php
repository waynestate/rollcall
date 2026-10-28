<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

/**
 * The answer to "give me this file": found, missing, or unchanged since the
 * blob id the caller already holds.
 */
final class RepoFile
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $content,
        public readonly ?string $blobId,
    ) {}

    public static function found(string $content, ?string $blobId): self
    {
        return new self('found', $content, $blobId);
    }

    public static function missing(): self
    {
        return new self('missing', null, null);
    }

    public static function unchanged(string $blobId): self
    {
        return new self('unchanged', null, $blobId);
    }

    public function isFound(): bool
    {
        return $this->status === 'found';
    }

    public function isUnchanged(): bool
    {
        return $this->status === 'unchanged';
    }
}
