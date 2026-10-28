<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Scan;

use Psr\Clock\ClockInterface;
use Waynestate\Rollcall\Model\AppFacts;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\Parse\ImageTag;
use Waynestate\Rollcall\Parse\ProctorReportReader;
use Waynestate\Rollcall\RollcallException;
use Waynestate\Rollcall\Source\ImageSource;
use Waynestate\Rollcall\Source\ProctorReportSource;
use Waynestate\Rollcall\Source\RepositorySource;

/**
 * Calls the roll. For each repository it reads the two Composer files, then
 * adds the image and the PROCTOR result from their own sources. Everything it
 * touches is read-only, and a gap in one source becomes a note on the app, not
 * a failed scan.
 */
final class Scanner
{
    public function __construct(
        private readonly RepositorySource $repositories,
        private readonly ?ImageSource $images,
        private readonly ?ProctorReportSource $reports,
        private readonly ClockInterface $clock,
        private readonly ComposerReader $composer = new ComposerReader(),
        private readonly ProctorReportReader $proctor = new ProctorReportReader(),
    ) {}

    /** @param  Snapshot|null  $previous  the last scan, used to skip files that have not changed */
    public function scan(?Snapshot $previous = null): Snapshot
    {
        $before = [];
        foreach ($previous === null ? [] : $previous->apps as $app) {
            $before[$app->id] = $app;
        }

        $apps = [];
        $errors = [];
        foreach ($this->repositories->projects() as $project) {
            $facts = $this->composerFacts($project, $before[$project] ?? null);
            $apps[] = $this->withImage($this->withProctor($facts));
        }
        if ($this->images === null) {
            $errors[] = 'No image source configured; PHP images are unknown.';
        }
        if ($this->reports === null) {
            $errors[] = 'No PROCTOR report source configured; check results are unknown.';
        }

        return new Snapshot($this->clock->now(), $apps, $errors);
    }

    private function composerFacts(string $project, ?AppFacts $before): AppFacts
    {
        $json = $this->repositories->file($project, 'composer.json', $before?->blobs['composer.json'] ?? null);
        $lock = $this->repositories->file($project, 'composer.lock', $before?->blobs['composer.lock'] ?? null);

        if ($before !== null && $json->isUnchanged() && $lock->isUnchanged()) {
            return $before;
        }
        // One file changed and the other did not: the parser needs both bodies, so read the quiet one again.
        if ($json->isUnchanged()) {
            $json = $this->repositories->file($project, 'composer.json');
        }
        if ($lock->isUnchanged()) {
            $lock = $this->repositories->file($project, 'composer.lock');
        }

        $facts = $this->composer->read($project, $json->isFound() ? $json->content : null, $lock->isFound() ? $lock->content : null);
        $blobs = [];
        foreach (['composer.json' => $json, 'composer.lock' => $lock] as $name => $file) {
            if ($file->blobId !== null) {
                $blobs[$name] = $file->blobId;
            }
        }

        return new AppFacts(
            id: $facts->id,
            phpConstraint: $facts->phpConstraint,
            phpFloor: $facts->phpFloor,
            laravel: $facts->laravel,
            authPackage: $facts->authPackage,
            authVersion: $facts->authVersion,
            blobs: $blobs,
            notes: $facts->notes,
        );
    }

    private function withProctor(AppFacts $facts): AppFacts
    {
        // What an earlier scan said is dropped: this scan's report wins, or the result is unknown.
        $facts = $this->clear($facts);
        $raw = $this->reports?->report($facts->id);
        if ($raw === null) {
            return $facts;
        }
        try {
            $report = $this->proctor->read($raw);
        } catch (RollcallException) {
            return $this->note($facts, 'PROCTOR report could not be read');
        }

        return $facts->with(proctorResult: $report['result'], proctorVersion: $report['version'], overrides: $report['overrides']);
    }

    private function withImage(AppFacts $facts): AppFacts
    {
        $tag = $this->images?->imageTag($facts->id);
        if ($tag === null) {
            return $facts;
        }
        $php = ImageTag::phpVersion($tag);
        if ($php === null) {
            return $this->note($facts->with(imageTag: substr($tag, 0, 200)), 'image tag does not name a PHP version');
        }

        return $facts->with(imageTag: substr($tag, 0, 200), phpImage: $php);
    }

    /** Drops stale per-scan fields carried over from the last snapshot. */
    private function clear(AppFacts $facts): AppFacts
    {
        return new AppFacts(
            $facts->id,
            $facts->phpConstraint,
            $facts->phpFloor,
            null,
            null,
            $facts->laravel,
            $facts->authPackage,
            $facts->authVersion,
            null,
            null,
            [],
            $facts->blobs,
            array_values(array_filter($facts->notes, static fn(string $n): bool => ! str_starts_with($n, 'PROCTOR report') && ! str_starts_with($n, 'image tag'))),
        );
    }

    private function note(AppFacts $facts, string $note): AppFacts
    {
        return new AppFacts(
            $facts->id,
            $facts->phpConstraint,
            $facts->phpFloor,
            $facts->imageTag,
            $facts->phpImage,
            $facts->laravel,
            $facts->authPackage,
            $facts->authVersion,
            $facts->proctorResult,
            $facts->proctorVersion,
            $facts->overrides,
            $facts->blobs,
            [...$facts->notes, $note],
        );
    }
}
