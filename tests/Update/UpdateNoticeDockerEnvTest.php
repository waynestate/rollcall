<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Update;

use PHPUnit\Framework\TestCase;
use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Tests\Support\Fleet;
use Waynestate\Rollcall\Update\ReleaseFetcher;
use Waynestate\Rollcall\Update\UpdateCache;
use Waynestate\Rollcall\Update\UpdateNotice;

final class UpdateNoticeDockerEnvTest extends TestCase
{
    private string $root;

    private string $cacheDir;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->root = Fleet::tempDir();
        $this->cacheDir = $this->root . '/cache';
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        Fleet::remove($this->root);
    }

    public function test_docker_image_is_silent(): void
    {
        $marker = $this->root . '/dockerenv';
        file_put_contents($marker, '');

        $this->check(dockerEnvPath: $marker);

        $this->assertSame([], $this->lines);
    }

    public function test_without_marker_the_check_runs(): void
    {
        $this->check(dockerEnvPath: $this->root . '/no-such-marker');

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall',
        ], $this->lines);
    }

    private function check(string $dockerEnvPath): void
    {
        $sink = function (string $line): void {
            $this->lines[] = $line;
        };
        $notice = new UpdateNotice(
            new class implements ReleaseFetcher {
                public function fetch(): ?array
                {
                    return ['packages' => ['waynestate/rollcall' => [['version' => '1.3.0']]]];
                }
            },
            new FixedClock('2026-11-20'),
            new UpdateCache($this->cacheDir),
            $this->root . '/loose',
            dockerEnvPath: $dockerEnvPath,
        );
        $notice->run([], [], '1.2.0', $sink);
    }
}
