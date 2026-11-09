<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Source\GitLab;

use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Source\ProctorReportSource;

/**
 * PROCTOR reports read straight from CI: the JSON file the `proctor` job keeps
 * as an artifact, from the newest finished pipeline on each project's default
 * branch (see GitLabRepositorySource::jobArtifact() for why not the "latest
 * successful" endpoint). Selected by ROLLCALL_PROCTOR_SOURCE=gitlab.
 *
 * It rides on the scanner's own GitLab adapter, so it shares the read_api
 * token, the GET-only gate, the 429 back-off and the same-host rule, and the
 * project list is fetched once per scan, not twice.
 *
 * Like the directory reader it hands back the raw file and judges nothing: a
 * body that is not a PROCTOR report becomes the scanner's "PROCTOR report
 * could not be read" note for that app. No artifact is no report, never an
 * error: an empty desk at roll call is noted, not sent to the principal.
 */
final class GitLabArtifactProctorReportSource implements ProctorReportSource
{
    /** Preflight's proctor component names its job this unless the include sets job_name. */
    public const DEFAULT_JOB = 'proctor';

    /** The file PROCTOR's --format=json report is kept as, at the project root. */
    public const DEFAULT_ARTIFACT = 'proctor.json';

    /** The same ceiling the directory reader puts on one report. */
    private const MAX_BYTES = 1_048_576;

    public function __construct(
        private readonly GitLabRepositorySource $gitlab,
        private readonly string $job = self::DEFAULT_JOB,
        private readonly string $artifact = self::DEFAULT_ARTIFACT,
    ) {
        if (! self::validJob($job)) {
            throw new RollcallException('ROLLCALL_PROCTOR_JOB must be a GitLab job name: letters, digits, "_", "." and "-".');
        }
        if (! self::validArtifact($artifact)) {
            throw new RollcallException('ROLLCALL_PROCTOR_ARTIFACT must be a relative path inside the artifact, such as proctor.json.');
        }
    }

    /** The rule Preflight's job_name input uses, so any name it accepts works here. */
    public static function validJob(string $job): bool
    {
        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $job) === 1 && strlen($job) <= 255;
    }

    public static function validArtifact(string $artifact): bool
    {
        return preg_match('#^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$#', $artifact) === 1
            && ! str_contains($artifact, '..')
            && strlen($artifact) <= 255;
    }

    public function report(string $project): ?string
    {
        return $this->gitlab->jobArtifact($project, $this->job, $this->artifact, self::MAX_BYTES);
    }
}
