<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

use Waynestate\Rollcall\RollcallException;

/**
 * Fixture adapter: a directory laid out as <root>/<group>/<project>/ with a
 * composer.json and composer.lock in each. Used by tests, demos and the
 * 0.1 CLI, so no live host is ever needed to try ROLLCALL out.
 */
final class DirectoryRepositorySource implements RepositorySource
{
    private const FILES = ['composer.json', 'composer.lock'];

    public function __construct(private readonly string $root)
    {
        if (! is_dir($root)) {
            throw new RollcallException(sprintf('Repository fixture directory "%s" does not exist.', $root));
        }
    }

    public function projects(): array
    {
        $projects = [];
        foreach (scandir($this->root) ?: [] as $group) {
            if ($group[0] === '.' || ! is_dir($this->root . '/' . $group)) {
                continue;
            }
            foreach (scandir($this->root . '/' . $group) ?: [] as $project) {
                if ($project[0] !== '.' && is_dir($this->root . '/' . $group . '/' . $project)) {
                    $projects[] = $group . '/' . $project;
                }
            }
        }
        sort($projects);

        return $projects;
    }

    public function file(string $project, string $path, ?string $knownBlobId = null): RepoFile
    {
        // Only the two named files are ever readable, and the project must be a plain group/project pair.
        if (! in_array($path, self::FILES, true) || preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $project) !== 1 || str_contains($project, '..')) {
            throw new RollcallException('Refusing to read an unexpected path.');
        }
        $full = $this->root . '/' . $project . '/' . $path;
        if (! is_file($full)) {
            return RepoFile::missing();
        }
        $content = file_get_contents($full);
        if ($content === false) {
            return RepoFile::missing();
        }
        $blob = sha1($content);

        return $knownBlobId === $blob ? RepoFile::unchanged($blob) : RepoFile::found($content, $blob);
    }
}
