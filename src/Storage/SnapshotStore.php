<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Storage;

use DateTimeImmutable;
use Waynestate\Rollcall\Model\Snapshot;

/** Where scan history lives. One snapshot per day; saving the same day again replaces it. */
interface SnapshotStore
{
    public function save(Snapshot $snapshot): void;

    /**
     * Every snapshot, oldest first.
     *
     * @return list<Snapshot>
     */
    public function all(): array;

    public function latest(): ?Snapshot;

    /**
     * Delete snapshots older than $days before $now, oldest first. The newest
     * snapshot is never deleted, even if it is older than the cut-off, so the
     * dashboard always has data. With $dryRun, returns what would go and
     * deletes nothing.
     *
     * @return list<string> the deleted (or would-be-deleted) dates, oldest first
     */
    public function prune(int $days, DateTimeImmutable $now, bool $dryRun = false): array;
}
