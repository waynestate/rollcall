<?php

declare(strict_types=1);

/**
 * Prove the generated CREED copy in src/Creed has not drifted from the export.
 *
 * Run it with `composer creed-copy:check` after setting CREED_PATH to a CREED
 * checkout. It is a plain PHP script (not a Composer callback) so it works on
 * every OS without leaning on Composer's runtime classes, and it exits with the
 * export checker's own code: 0 when the copy is current, non-zero when it has
 * drifted or CREED_PATH is unusable.
 */

chdir(dirname(__DIR__));

$creedPath = getenv('CREED_PATH');
if ($creedPath === false || $creedPath === '') {
    fwrite(STDERR, "CREED_PATH is not set. Point it at a CREED checkout to check the generated copy.\n");
    exit(2);
}

$export = $creedPath . '/bin/export.php';
if (! is_file($export)) {
    fwrite(STDERR, sprintf('CREED_PATH "%s" does not contain bin/export.php. Point it at a CREED checkout.', $creedPath) . "\n");
    exit(2);
}

$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($export)
    . ' --check'
    . ' --namespace=' . escapeshellarg('Waynestate\Rollcall\Creed')
    . ' --to=' . escapeshellarg('src/Creed');

passthru($command, $exit);
exit($exit);
