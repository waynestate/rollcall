<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Storage;

use Waynestate\Rollcall\Digest\Digest;
use Waynestate\Rollcall\RollcallException;

/**
 * Writes digests as plain-text files into the one directory an operator names
 * with `rollcall digest --out=DIR`: `<scan date>-<owner>.txt`, one per owner
 * with news. Besides the snapshot store, this is the only place Rollcall
 * writes, and only when asked. Nothing is written into a scanned repository.
 */
final class DigestFileWriter
{
    public function __construct(private readonly string $directory) {}

    /**
     * @param  list<Digest>  $digests
     * @return list<string> the files written
     */
    public function write(array $digests): array
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0o750, true) && ! is_dir($this->directory)) {
            throw new RollcallException(sprintf('Digest directory "%s" could not be created.', $this->directory));
        }
        if (! is_writable($this->directory)) {
            throw new RollcallException(sprintf('Digest directory "%s" is not writable.', $this->directory));
        }

        $written = [];
        $taken = [];
        foreach ($digests as $digest) {
            $stem = $digest->scanDate . '-' . $digest->owner->slug();
            $name = $stem;
            for ($n = 2; isset($taken[$name]); $n++) {
                $name = $stem . '-' . $n; // two contacts that slug alike never overwrite each other
            }
            $taken[$name] = true;
            $file = rtrim($this->directory, '/\\') . '/' . $name . '.txt';
            $text = 'To: ' . $digest->owner->contact . "\n" . 'Subject: ' . $digest->subject() . "\n\n" . $digest->body();
            if (@file_put_contents($file, $text, LOCK_EX) === false) {
                throw new RollcallException(sprintf('Could not write the digest file "%s".', $file));
            }
            $written[] = $file;
        }

        return $written;
    }
}
