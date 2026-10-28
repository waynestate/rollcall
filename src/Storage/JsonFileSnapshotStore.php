<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Storage;

use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\RollcallException;

/**
 * One JSON file per scan day in a directory ("2026-10-28.json"). Writes go to
 * a temporary file first and are renamed into place, so a crash never leaves a
 * half-written snapshot. The directory is the only place ROLLCALL writes.
 */
final class JsonFileSnapshotStore implements SnapshotStore
{
    public function __construct(private readonly string $directory) {}

    public function save(Snapshot $snapshot): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o750, true) && ! is_dir($this->directory)) {
            throw new RollcallException(sprintf('Could not create the snapshot directory "%s".', $this->directory));
        }
        $json = json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $final = $this->directory . '/' . $snapshot->date->format('Y-m-d') . '.json';
        $temp = $final . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temp, $json . "\n", LOCK_EX) === false || ! rename($temp, $final)) {
            @unlink($temp);
            throw new RollcallException(sprintf('Could not write the snapshot to "%s".', $this->directory));
        }
    }

    public function all(): array
    {
        if (! is_dir($this->directory)) {
            return [];
        }
        $files = glob($this->directory . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].json') ?: [];
        sort($files);

        $snapshots = [];
        foreach ($files as $file) {
            try {
                $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RollcallException(sprintf('Snapshot "%s" is not valid JSON.', basename($file)));
            }
            if (! is_array($data)) {
                throw new RollcallException(sprintf('Snapshot "%s" is not an object.', basename($file)));
            }
            $snapshots[] = Snapshot::fromArray($data);
        }

        return $snapshots;
    }

    public function latest(): ?Snapshot
    {
        $all = $this->all();

        return $all === [] ? null : $all[array_key_last($all)];
    }
}
