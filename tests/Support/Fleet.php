<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Support;

use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Model\Snapshot;
use Waynestate\Rollcall\Parse\ComposerReader;
use Waynestate\Rollcall\Scan\Scanner;
use Waynestate\Rollcall\Source\DirectoryProctorReportSource;
use Waynestate\Rollcall\Source\DirectoryRepositorySource;
use Waynestate\Rollcall\Source\JsonFileImageSource;
use Waynestate\Rollcall\Source\RepositorySource;

/** The fixture fleet: four demo apps, scanned on a given day. */
final class Fleet
{
    public const FIXTURES = __DIR__ . '/../fixtures';

    public static function repositories(): DirectoryRepositorySource
    {
        return new DirectoryRepositorySource(self::FIXTURES . '/repos');
    }

    public static function scanner(string $date, ?RepositorySource $repositories = null): Scanner
    {
        return new Scanner(
            $repositories ?? self::repositories(),
            JsonFileImageSource::fromFile(self::FIXTURES . '/images.json'),
            new DirectoryProctorReportSource(self::FIXTURES . '/proctor'),
            new FixedClock($date),
            new ComposerReader(),
        );
    }

    public static function snapshot(string $date): Snapshot
    {
        return self::scanner($date)->scan();
    }

    /** A temporary directory that the test removes in tearDown through remove(). */
    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/rollcall-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);

        return $dir;
    }

    public static function remove(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? self::remove($file) : unlink($file);
        }
        rmdir($dir);
    }
}
