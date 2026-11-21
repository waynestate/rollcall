<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Tests\Update;

use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Waynestate\Rollcall\Clock\FixedClock;
use Waynestate\Rollcall\Clock\SystemClock;
use Waynestate\Rollcall\Console\Application;
use Waynestate\Rollcall\Tests\Support\Fleet;
use Waynestate\Rollcall\Update\ReleaseFetcher;
use Waynestate\Rollcall\Update\UpdateCache;
use Waynestate\Rollcall\Update\UpdateNotice;

final class UpdateNoticeTest extends TestCase
{
    private string $root;

    private string $cacheDir;

    private string $cacheHome;

    private int $fetches = 0;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->root = Fleet::tempDir();
        $this->cacheDir = $this->root . '/cache';
        $this->cacheHome = $this->root . '/xdg';
        $this->makeDir($this->root . '/loose');
        $this->fetches = 0;
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        // Fleet::remove globs without dotfiles, so the .git marker goes first.
        $git = $this->root . '/checkout/.git';
        if (is_file($git)) {
            unlink($git);
        }
        Fleet::remove($this->root);
    }

    public function testLineForPhar(): void
    {
        $this->check([], [], '1.2.0', inPhar: true);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall',
        ], $this->lines);
    }

    public function testLineForSourceCheckout(): void
    {
        $checkout = $this->root . '/checkout';
        $this->makeDir($checkout);
        file_put_contents($checkout . '/.git', "gitdir: /somewhere\n");

        $this->check([], [], '1.2.0', packageRoot: $checkout);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: git pull',
        ], $this->lines);
    }

    public function testLineForComposerGlobal(): void
    {
        $home = $this->root . '/global-home';
        $packageRoot = $home . '/vendor/waynestate/rollcall';
        $this->makeDir($packageRoot);

        $this->check([], [], '1.2.0', packageRoot: $packageRoot, globalHomes: [$home]);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall',
        ], $this->lines);
    }

    public function testLineForProjectDependency(): void
    {
        $project = $this->root . '/project';
        $packageRoot = $project . '/vendor/waynestate/rollcall';
        $this->makeDir($packageRoot);
        file_put_contents($project . '/composer.json', json_encode(['require' => ['waynestate/rollcall' => '^1.0']]));

        $this->check([], [], '1.2.0', packageRoot: $packageRoot, globalHomes: [$this->root . '/other-home']);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer update waynestate/rollcall',
        ], $this->lines);
    }

    public function testLineForPinnedProjectDependency(): void
    {
        $project = $this->root . '/project';
        $packageRoot = $project . '/vendor/waynestate/rollcall';
        $this->makeDir($packageRoot);
        file_put_contents($project . '/composer.json', json_encode(['require' => ['waynestate/rollcall' => '^1.0']]));

        $this->check([], [], '1.2.0', ['2.0.0'], packageRoot: $packageRoot, globalHomes: [$this->root . '/other-home']);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 2.0.0 is the newest release. Update: composer require waynestate/rollcall:^2.0 Upgrading: https://rollcall.apps.wayne.edu/docs/changelog/',
        ], $this->lines);

        // A tilde pin in require-dev that excludes the newest gets --dev.
        // The first check filled the once-a-day cache, so clear it first.
        $this->lines = [];
        $cached = $this->cacheDir . '/update-check.json';
        if (is_file($cached)) {
            unlink($cached);
        }
        file_put_contents($project . '/composer.json', json_encode(['require-dev' => ['waynestate/rollcall' => '~1.2']]));

        $this->check([], [], '1.2.0', packageRoot: $packageRoot, globalHomes: [$this->root . '/other-home']);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer require waynestate/rollcall:^1.3 --dev',
        ], $this->lines);
    }

    public function testLineForUnknownInstall(): void
    {
        $this->check([], [], '1.2.0');

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall',
        ], $this->lines);
    }

    public function testCachedResultMakesNoFetch(): void
    {
        $now = (new FixedClock('2026-11-20'))->now()->getTimestamp();
        $this->seedCache(['checked_at' => $now, 'latest' => '1.3.0']);

        // The network would say 1.4.0; the fresh cache says 1.3.0 and wins.
        $this->check([], [], '1.2.0', ['1.4.0']);

        $this->assertSame(0, $this->fetches);
        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall',
        ], $this->lines);
    }

    public function testStaleCacheFetchesOnce(): void
    {
        $now = (new FixedClock('2026-11-20'))->now()->getTimestamp();
        $this->seedCache(['checked_at' => $now - 86_401, 'latest' => null]);

        $this->check([], [], '1.2.0');

        $this->assertSame(1, $this->fetches);
        $this->assertNotEmpty($this->lines);

        $cached = json_decode((string) file_get_contents($this->cacheDir . '/update-check.json'), true);
        $this->assertSame(['checked_at' => $now, 'latest' => '1.3.0'], $cached);
    }

    public function testOfflineIsSilent(): void
    {
        $this->check([], [], '1.2.0', fetchFails: true);

        $this->assertSame([], $this->lines);
        $this->assertSame(1, $this->fetches);

        // The failed check is still remembered, so the day is not retried.
        $cached = json_decode((string) file_get_contents($this->cacheDir . '/update-check.json'), true);
        $this->assertSame(['checked_at' => (new FixedClock('2026-11-20'))->now()->getTimestamp(), 'latest' => null], $cached);
    }

    public function testMalformedJsonIsSilent(): void
    {
        $this->check([], [], '1.2.0', metadata: ['packages' => 'not-a-list']);
        $this->assertSame([], $this->lines);

        $this->lines = [];
        $this->check([], [], '1.2.0', metadata: ['unexpected' => true]);
        $this->assertSame([], $this->lines);
    }

    public function testCiEnvIsSilent(): void
    {
        $this->check(['CI' => '1'], [], '1.2.0');

        $this->assertSame([], $this->lines);
        $this->assertSame(0, $this->fetches);
    }

    public function testOptOutEnvIsSilent(): void
    {
        $this->check(['WAYNE_NO_UPDATE_CHECK' => '1'], [], '1.2.0');

        $this->assertSame([], $this->lines);
        $this->assertSame(0, $this->fetches);
    }

    public function testQuietIsSilent(): void
    {
        $this->check([], ['rollcall', 'report', '-q'], '1.2.0');
        $this->assertSame([], $this->lines);

        $this->check([], ['rollcall', 'report', '--quiet'], '1.2.0');
        $this->assertSame([], $this->lines);
        $this->assertSame(0, $this->fetches);
    }

    public function testStdoutUntouchedByJsonFormat(): void
    {
        // The command's JSON goes to stdout; the notice may only take stderr.
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        $notice = new UpdateNotice(
            $this->fetcher($this->p2(['1.3.0'])),
            new FixedClock('2026-11-20'),
            new UpdateCache($this->cacheDir),
            $this->root . '/loose',
            dockerEnvPath: $this->root . '/no-such-dockerenv',
        );
        $notice->run([], ['rollcall', 'report', '--format=json'], '1.2.0', $stderr);

        fseek($stdout, 0);
        fseek($stderr, 0);
        $this->assertSame('', (string) stream_get_contents($stdout));
        $this->assertSame(
            "rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall\n",
            (string) stream_get_contents($stderr),
        );
        fclose($stdout);
        fclose($stderr);
    }

    public function testNormalisesVPrefix(): void
    {
        $this->check([], [], '1.2.0', ['v1.3.0', '1.2.0']);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall',
        ], $this->lines);

        $this->lines = [];
        $this->check([], [], 'v1.2.0', ['1.3.0']);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall',
        ], $this->lines);
    }

    public function testIgnoresPrereleases(): void
    {
        $this->check([], [], '1.2.0', ['1.4.0-rc1', '1.3.1-beta2', '1.3.0-dev', '1.3.0']);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: composer global update waynestate/rollcall',
        ], $this->lines);

        // A dev build of the tool itself never nags.
        $this->lines = [];
        $this->check([], [], '1.2.0-dev', ['1.3.0']);
        $this->assertSame([], $this->lines);
    }

    public function testMajorBumpAddsUpgradingLink(): void
    {
        $this->check([], [], '1.2.0', ['2.0.0']);

        $this->assertSame([
            'rollcall 1.2.0 is out of date: 2.0.0 is the newest release. Update: composer global update waynestate/rollcall Upgrading: https://rollcall.apps.wayne.edu/docs/changelog/',
        ], $this->lines);
    }

    public function testNotNewerIsSilent(): void
    {
        $this->check([], [], '1.2.0', ['1.2.0']);
        $this->assertSame([], $this->lines);

        $this->check([], [], '1.5.0', ['1.2.0']);
        $this->assertSame([], $this->lines);
    }

    /**
     * bin/rollcall wiring is covered by reading (bin/rollcall:21 constructs the
     * Application and run() dispatches doRunCommand, which calls the notice
     * before any exit); the Application path is covered by this test.
     */
    public function testApplicationPrintsTheNotice(): void
    {
        $notice = $this->applicationNotice();

        [$code, $outWith, $errWith] = $this->runList($notice, ['XDG_CACHE_HOME' => $this->cacheHome]);
        $this->assertSame(0, $code);

        $lines = array_values(array_filter(explode("\n", $errWith), static fn(string $line): bool => trim($line) !== ''));
        $this->assertCount(1, $lines, $errWith);
        $this->assertStringContainsString('out of date', $lines[0]);
        $this->assertStringContainsString('99.0.0', $lines[0]);

        // The opt-out run prints the same command output and no notice line.
        [$code, $outWithout, $errWithout] = $this->runList($notice, ['XDG_CACHE_HOME' => $this->cacheHome, 'WAYNE_NO_UPDATE_CHECK' => '1']);
        $this->assertSame(0, $code);
        $this->assertNotEmpty($outWith);
        $this->assertSame($outWith, $outWithout);
        $this->assertStringNotContainsString('out of date', $errWithout);

        // The fresh cache answered, so the network was never asked.
        $this->assertSame(0, $this->fetches);
    }

    /**
     * bin/rollcall wiring is covered by reading (bin/rollcall:21 constructs the
     * Application and run() dispatches doRunCommand, which calls the notice
     * before any exit); the Application path is covered by this test.
     */
    public function testSilentInsideDockerWithDefaultPath(): void
    {
        // The rule can only be shown where /.dockerenv really exists.
        if (! is_file('/.dockerenv')) {
            $this->markTestSkipped('No /.dockerenv here, so the Docker silence rule cannot be shown.');
        }

        $this->seedCacheHome();
        $notice = new UpdateNotice(
            $this->fetcher(null),
            new SystemClock(),
            new UpdateCache($this->cacheHome . '/waynestate/rollcall'),
            $this->root . '/loose',
            // the default dockerEnvPath: /.dockerenv
        );

        [$code, , $err] = $this->runList($notice, ['XDG_CACHE_HOME' => $this->cacheHome]);
        $this->assertSame(0, $code);

        $lines = array_values(array_filter(explode("\n", $err), static fn(string $line): bool => trim($line) !== ''));
        $this->assertSame([], $lines, $err);
        $this->assertSame(0, $this->fetches);
    }

    /**
     * @param  array<string, string|false|null>  $env
     * @param  list<string>                      $argv
     * @param  list<string>                      $versions
     * @param  array<string, mixed>|null         $metadata  overrides $versions when given
     */
    private function check(
        array $env,
        array $argv,
        string $version,
        array $versions = ['1.3.0'],
        ?string $packageRoot = null,
        ?array $globalHomes = null,
        bool $inPhar = false,
        ?array $metadata = null,
        bool $fetchFails = false,
    ): void {
        $sink = function (string $line): void {
            $this->lines[] = $line;
        };
        $payload = $fetchFails ? null : ($metadata ?? $this->p2($versions));
        $notice = new UpdateNotice(
            $this->fetcher($payload),
            new FixedClock('2026-11-20'),
            new UpdateCache($this->cacheDir),
            $packageRoot ?? $this->root . '/loose',
            globalHomes: $globalHomes,
            inPhar: $inPhar,
            dockerEnvPath: $this->root . '/no-such-dockerenv',
        );
        $notice->run($env, $argv, $version, $sink);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function fetcher(?array $metadata): ReleaseFetcher
    {
        $count = function (): void {
            $this->fetches++;
        };

        return new class ($count, $metadata) implements ReleaseFetcher {
            /**
             * @param  array<string, mixed>|null  $metadata
             */
            public function __construct(private readonly Closure $count, private readonly ?array $metadata) {}

            public function fetch(): ?array
            {
                ($this->count)();

                return $this->metadata;
            }
        };
    }

    /**
     * @param  list<string>  $versions
     * @return array<string, mixed>
     */
    private function p2(array $versions): array
    {
        $packages = [];
        foreach ($versions as $version) {
            $packages[] = ['version' => $version];
        }

        return ['packages' => ['waynestate/rollcall' => $packages]];
    }

    /**
     * @param  array{checked_at: int, latest: string|null}  $entry
     */
    private function seedCache(array $entry): void
    {
        mkdir($this->cacheDir, 0o775, true);
        file_put_contents($this->cacheDir . '/update-check.json', (string) json_encode($entry));
    }

    private function makeDir(string $path): void
    {
        mkdir($path, 0o775, true);
    }

    /**
     * The notice for the in-process proof: a once-a-day cache seeded fresh
     * with a release far above the running one, a fetcher that fails the test
     * if the cache misses, and a dockerEnvPath that points at nothing.
     */
    private function applicationNotice(): UpdateNotice
    {
        $this->seedCacheHome();

        $count = function (): void {
            $this->fetches++;
        };
        $fetcher = new class ($count) implements ReleaseFetcher {
            public function __construct(private readonly Closure $count) {}

            public function fetch(): ?array
            {
                ($this->count)();

                throw new LogicException('the fresh cache must keep the notice off the network');
            }
        };

        return new UpdateNotice(
            $fetcher,
            new SystemClock(),
            new UpdateCache($this->cacheHome . '/waynestate/rollcall'),
            $this->root . '/loose',
            dockerEnvPath: $this->root . '/no-such-dockerenv',
        );
    }

    /** Seeds the once-a-day cache where UpdateCache::fromEnv finds it for the runs below. */
    private function seedCacheHome(): void
    {
        $home = $this->cacheHome . '/waynestate/rollcall';
        $this->makeDir($home);
        file_put_contents($home . '/update-check.json', (string) json_encode(['checked_at' => time(), 'latest' => '99.0.0']));
    }

    /**
     * Runs the cheap, read-only `list` command in-process through the real
     * Application. The output is a ConsoleOutputInterface with captured stdout
     * and stderr memory streams, because the notice only fires for a
     * ConsoleOutputInterface.
     *
     * @param  array<string, string|false|null>  $env
     * @return array{0: int, 1: string, 2: string}  exit code, stdout, stderr
     */
    private function runList(UpdateNotice $notice, array $env): array
    {
        $app = new Application($env, $notice);
        $app->setAutoExit(false);

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        $this->assertIsResource($stdout);
        $this->assertIsResource($stderr);

        $error = new class ($stderr) extends Output {
            public function __construct(
                /** @var resource */
                private readonly mixed $stream,
            ) {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                fwrite($this->stream, $message . ($newline ? "\n" : ''));
            }
        };

        $output = new class ($stdout, $error) extends ConsoleOutput {
            public function __construct(
                /** @var resource */
                public readonly mixed $stdout,
                private readonly OutputInterface $error,
            ) {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                fwrite($this->stdout, $message . ($newline ? "\n" : ''));
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->error;
            }
        };

        // The notice spots -q/--quiet in the process argv, so stand in for
        // `rollcall list` instead of the phpunit invocation.
        $argv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['rollcall', 'list'];
        try {
            $code = $app->run(new ArrayInput([]), $output);
        } finally {
            if ($argv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $argv;
            }
        }

        fseek($stdout, 0);
        fseek($stderr, 0);
        $out = (string) stream_get_contents($stdout);
        $err = (string) stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);

        return [$code, $out, $err];
    }
}
