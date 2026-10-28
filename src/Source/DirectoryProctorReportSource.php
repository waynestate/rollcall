<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source;

use Waynestate\Rollcall\RollcallException;

/**
 * Reports in a directory, one file per app: "group/project" is read from
 * "group__project.json". The file name is built from a strict character set,
 * so a hostile project id cannot walk out of the directory.
 */
final class DirectoryProctorReportSource implements ProctorReportSource
{
    private const MAX_BYTES = 1_048_576;

    public function __construct(private readonly string $directory)
    {
        if (! is_dir($directory)) {
            throw new RollcallException(sprintf('PROCTOR report directory "%s" does not exist.', $directory));
        }
    }

    public function report(string $project): ?string
    {
        if (preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $project) !== 1 || str_contains($project, '..')) {
            return null;
        }
        $path = $this->directory . '/' . str_replace('/', '__', $project) . '.json';
        if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
            return null;
        }
        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }
}
