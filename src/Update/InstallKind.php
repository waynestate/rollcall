<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Update;

/**
 * How this copy of Rollcall was installed, in detection order: PHAR, source
 * checkout, Composer global, project dependency, unknown.
 */
enum InstallKind: string
{
    case Phar = 'phar';
    case SourceCheckout = 'source-checkout';
    case ComposerGlobal = 'composer-global';
    case ProjectDependency = 'project-dependency';
    case Unknown = 'unknown';

    /**
     * @param  list<string>  $globalHomes  candidate Composer global home dirs
     */
    public static function detect(bool $inPhar, string $packageRoot, array $globalHomes): self
    {
        if ($inPhar) {
            return self::Phar;
        }

        $root = str_replace('\\', '/', rtrim($packageRoot, '/'));
        $segments = explode('/', $root);

        if (! in_array('vendor', $segments, true) && (is_dir($root . DIRECTORY_SEPARATOR . '.git') || is_file($root . DIRECTORY_SEPARATOR . '.git'))) {
            return self::SourceCheckout;
        }

        if (array_slice($segments, -3) === ['vendor', 'waynestate', 'rollcall']) {
            $project = implode('/', array_slice($segments, 0, -3));
            foreach ($globalHomes as $home) {
                if (self::samePath($home, $project)) {
                    return self::ComposerGlobal;
                }
            }

            return self::ProjectDependency;
        }

        return self::Unknown;
    }

    /**
     * The constraint the project pins on waynestate/rollcall and whether it
     * sits in require-dev. Null when there is no readable composer.json or no
     * entry for the package.
     *
     * @return array{constraint: ?string, dev: bool}|null
     */
    public static function projectPin(string $projectDir): ?array
    {
        $path = $projectDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (! is_file($path)) {
            return null;
        }

        set_error_handler(static fn(): bool => true);
        try {
            $raw = file_get_contents($path);
        } finally {
            restore_error_handler();
        }
        if (! is_string($raw)) {
            return null;
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }

        $require = is_array($data['require'] ?? null) ? $data['require']['waynestate/rollcall'] ?? null : null;
        $requireDev = is_array($data['require-dev'] ?? null) ? $data['require-dev']['waynestate/rollcall'] ?? null : null;
        if (! is_string($require) && ! is_string($requireDev)) {
            return null;
        }

        return [
            'constraint' => is_string($require) ? $require : $requireDev,
            'dev' => ! is_string($require),
        ];
    }

    /**
     * Whether a ^ or ~ pin excludes the newest version: the caret locks the
     * major (the minor while the major is 0), the tilde locks the minor.
     * Anything else lets `composer update` do its work.
     */
    public static function pinExcludesNewest(?string $constraint, string $newest): bool
    {
        if ($constraint === null || preg_match('/^([\^~])(\d+)(?:\.(\d+))?/', trim($constraint), $m) !== 1) {
            return false;
        }

        $parts = explode('.', $newest);
        $major = (int) $parts[0];
        $minor = (int) ($parts[1] ?? 0);
        $lockedMajor = (int) $m[2];
        $lockedMinor = isset($m[3]) ? (int) $m[3] : null;

        if ($m[1] === '^') {
            if ($lockedMajor > 0) {
                return $major !== $lockedMajor;
            }

            return $lockedMinor === null ? $major !== 0 : ($major !== 0 || $minor !== $lockedMinor);
        }

        return $lockedMinor === null ? $major !== $lockedMajor : ($major !== $lockedMajor || $minor !== $lockedMinor);
    }

    /** The suggested pin: the newest release's major.minor under a caret. */
    public static function suggestedPin(string $newest): string
    {
        $parts = explode('.', $newest);

        return sprintf('^%d.%d', (int) $parts[0], (int) ($parts[1] ?? 0));
    }

    private static function samePath(string $a, string $b): bool
    {
        $normalize = static fn(string $path): string => strtolower(str_replace('\\', '/', rtrim($path, '/')));

        return $normalize($a) === $normalize($b);
    }
}
