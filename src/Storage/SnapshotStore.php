<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Storage;

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
}
