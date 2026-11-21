<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Update;

use Psr\Clock\ClockInterface;
use Throwable;

/**
 * One stderr line after a command, when a newer stable release of Rollcall is
 * on Packagist:
 *
 *   rollcall 1.2.0 is out of date: 1.3.0 is the newest release. Update: <command>
 *
 * The check reads the once-a-day cache first and asks Packagist only when the
 * cache is stale. It stays silent on every silence rule and on any error: it
 * never touches stdout, never blocks or fails a command, and never changes an
 * exit code.
 */
final class UpdateNotice
{
    public const PACKAGE = 'waynestate/rollcall';

    public const TOOL = 'rollcall';

    /** ROLLCALL has no docs/upgrading.md, so a major bump points at the changelog. */
    public const UPGRADE_URL = 'https://rollcall.apps.wayne.edu/docs/changelog/';

    private const DAY = 86_400;

    /**
     * @param  list<string>|null  $globalHomes  candidate Composer global home dirs; resolved from $env at run time when null
     */
    public function __construct(
        private readonly ReleaseFetcher $fetcher,
        private readonly ClockInterface $clock,
        private readonly UpdateCache $cache,
        private readonly string $packageRoot,
        private readonly ?array $globalHomes = null,
        private readonly bool $inPhar = false,
        private readonly string $dockerEnvPath = '/.dockerenv',
        private readonly string $sapi = PHP_SAPI,
    ) {}

    /**
     * Run the check and, when it fires, write one line to $stderr. A callable
     * receives the line without a trailing newline; a resource gets the line
     * plus a newline. Nothing here may throw: every failure stays silent.
     *
     * @param  array<string, string|false|null>  $env
     * @param  list<string>                      $argv  the process argv, used only to spot -q and --quiet
     * @param  resource|callable(string): void    $stderr
     */
    public function run(array $env, array $argv, string $version, mixed $stderr): void
    {
        try {
            $this->runInner($env, $argv, $version, $stderr);
        } catch (Throwable) {
            // the notice never gets in the way of the tool
        }
    }

    /**
     * @param  array<string, string|false|null>  $env
     * @param  list<string>                      $argv
     * @param  resource|callable(string): void    $stderr
     */
    private function runInner(array $env, array $argv, string $version, mixed $stderr): void
    {
        if ($this->sapi !== 'cli' || is_file($this->dockerEnvPath)) {
            return;
        }
        if (self::ciSet($env) || self::optedOut($env) || self::quiet($argv) || ! self::plainRelease($version)) {
            return;
        }
        if (! $this->cache->usable()) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $cached = $this->cache->read();
        $previous = $cached['latest'] ?? null;
        $fresh = $cached !== null && $now - $cached['checked_at'] <= self::DAY;

        if ($fresh) {
            $latest = $previous;
        } else {
            $metadata = $this->fetcher->fetch();
            $latest = $metadata === null ? $previous : self::newestStable($metadata);
            if (! $this->cache->write($now, $latest)) {
                return;
            }
        }

        if ($latest === null) {
            return;
        }

        $have = self::stripV($version);
        if (version_compare($latest, $have, '<=')) {
            return;
        }

        $line = sprintf(
            '%s %s is out of date: %s is the newest release. Update: %s',
            self::TOOL,
            $have,
            $latest,
            $this->updateCommand($env, $latest),
        );
        if (self::majorBump($have, $latest)) {
            $line .= ' Upgrading: ' . self::UPGRADE_URL;
        }

        if (is_resource($stderr)) {
            fwrite($stderr, $line . "\n");
        } elseif (is_callable($stderr)) {
            $stderr($line);
        }
    }

    /**
     * CI counts as set when it holds anything other than empty, 0 or false.
     *
     * @param  array<string, string|false|null>  $env
     */
    private static function ciSet(array $env): bool
    {
        $ci = $env['CI'] ?? null;
        if ($ci === null || $ci === false) {
            return false;
        }

        return $ci !== '' && $ci !== '0' && $ci !== 'false';
    }

    /**
     * @param  array<string, string|false|null>  $env
     */
    private static function optedOut(array $env): bool
    {
        return ($env['WAYNE_NO_UPDATE_CHECK'] ?? null) === '1';
    }

    /**
     * @param  list<string>  $argv
     */
    private static function quiet(array $argv): bool
    {
        foreach ($argv as $arg) {
            if ($arg === '-q' || $arg === '--quiet') {
                return true;
            }
        }

        return false;
    }

    private static function plainRelease(string $version): bool
    {
        return preg_match('/^\d+(\.\d+){0,2}$/', self::stripV($version)) === 1;
    }

    private static function stripV(string $version): string
    {
        return preg_replace('/^v/i', '', $version) ?? $version;
    }

    /**
     * The highest stable version in p2 metadata: dev, alpha, beta and rc
     * builds are dropped, a leading v is stripped, and version_compare picks.
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function newestStable(array $metadata): ?string
    {
        $versions = $metadata['packages'][self::PACKAGE] ?? [];
        if (! is_array($versions)) {
            return null;
        }

        $newest = null;
        foreach ($versions as $entry) {
            if (! is_array($entry) || ! isset($entry['version']) || ! is_string($entry['version'])) {
                continue;
            }
            if (preg_match('/(dev|alpha|beta|rc)/i', $entry['version']) === 1) {
                continue;
            }
            $clean = self::stripV($entry['version']);
            if ($newest === null || version_compare($clean, $newest, '>')) {
                $newest = $clean;
            }
        }

        return $newest;
    }

    private static function majorBump(string $have, string $latest): bool
    {
        return (int) explode('.', $latest)[0] > (int) explode('.', $have)[0];
    }

    /**
     * @param  array<string, string|false|null>  $env
     */
    private function updateCommand(array $env, string $latest): string
    {
        $kind = InstallKind::detect($this->inPhar, $this->packageRoot, $this->globalHomes ?? self::globalHomes($env));

        return match ($kind) {
            // ROLLCALL ships no PHAR, so a phar copy updates like any other
            // composer-installed one.
            InstallKind::Phar, InstallKind::Unknown => 'composer global update ' . self::PACKAGE,
            InstallKind::SourceCheckout => 'git pull',
            InstallKind::ComposerGlobal => 'composer global update ' . self::PACKAGE,
            InstallKind::ProjectDependency => $this->projectCommand($latest),
        };
    }

    private function projectCommand(string $latest): string
    {
        $project = self::projectOf($this->packageRoot);
        $pin = $project === null ? null : InstallKind::projectPin($project);
        if ($pin !== null && InstallKind::pinExcludesNewest($pin['constraint'], $latest)) {
            $flag = $pin['dev'] ? ' --dev' : '';

            return 'composer require ' . self::PACKAGE . ':' . InstallKind::suggestedPin($latest) . $flag;
        }

        return 'composer update ' . self::PACKAGE;
    }

    private static function projectOf(string $packageRoot): ?string
    {
        $root = str_replace('\\', '/', rtrim($packageRoot, '/'));
        $suffix = '/vendor/waynestate/rollcall';
        if (! str_ends_with($root, $suffix)) {
            return null;
        }

        return substr($root, 0, -strlen($suffix));
    }

    /**
     * Candidate Composer global home dirs, in order: COMPOSER_HOME wins alone,
     * else ~/.config/composer, ~/.composer and %APPDATA%\Composer.
     *
     * @param  array<string, string|false|null>  $env
     * @return list<string>
     */
    private static function globalHomes(array $env): array
    {
        $composerHome = self::envString($env, 'COMPOSER_HOME');
        if ($composerHome !== null) {
            return [$composerHome];
        }

        $homes = [];
        $home = self::envString($env, 'HOME') ?? self::envString($env, 'USERPROFILE');
        if ($home !== null) {
            $homes[] = $home . '/.config/composer';
            $homes[] = $home . '/.composer';
        }
        $appData = self::envString($env, 'APPDATA');
        if ($appData !== null) {
            $homes[] = $appData . '/Composer';
        }

        return $homes;
    }

    /**
     * @param  array<string, string|false|null>  $env
     */
    private static function envString(array $env, string $key): ?string
    {
        $value = $env[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
