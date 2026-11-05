<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Parse;

use Composer\Semver\VersionParser;
use Waynestate\Rollcall\Model\AppFacts;

/**
 * Reads composer.json and composer.lock as data. It never runs Composer, a
 * script or a plugin from a scanned repository: the lock file already records
 * what was resolved, and parsing it executes nothing.
 */
final class ComposerReader
{
    /**
     * Packages that count as an app's auth package, in the order they win.
     * An entry with a slash matches exactly; an entry without one is a bare
     * package name and matches that package under any vendor.
     */
    public const DEFAULT_AUTH_PACKAGES = [
        'waynestate/sesame',
        'waynestate/wayne-cas',
        'apereo/phpcas',
        'jasig/phpcas',
        'subfission/cas',
        'wayne-cas',
        'simplecas',
    ];

    /** @param  list<string>  $authPackages */
    public function __construct(private readonly array $authPackages = self::DEFAULT_AUTH_PACKAGES) {}

    public function read(string $id, ?string $composerJson, ?string $composerLock): AppFacts
    {
        $notes = [];
        $json = $this->decode($composerJson, 'composer.json', $notes);
        $lock = $this->decode($composerLock, 'composer.lock', $notes);

        $constraint = null;
        $floor = null;
        $require = is_array($json['require'] ?? null) ? $json['require'] : [];
        if (is_string($require['php'] ?? null)) {
            $constraint = $require['php'];
            $floor = $this->floorOf($constraint, $notes);
        } elseif ($json !== null) {
            $notes[] = 'composer.json declares no PHP constraint';
        }

        $laravel = null;
        $authPackage = null;
        $authVersion = null;
        if ($lock !== null) {
            $versions = $this->lockedVersions($lock);
            $laravel = $versions['laravel/framework'] ?? null;
            $match = $this->findAuthPackage($versions);
            if ($match !== null) {
                $authPackage = $match[0];
                $authVersion = is_string($match[1]) ? $match[1] : null;
            }
        } else {
            $match = $this->findAuthPackage($require);
            if ($match !== null) {
                $authPackage = $match[0];
            }
            if ($json !== null && ($require['laravel/framework'] ?? null) !== null) {
                $notes[] = 'Laravel version unknown without composer.lock';
            }
        }

        return new AppFacts(
            id: $id,
            phpConstraint: $constraint,
            phpFloor: $floor,
            laravel: $laravel,
            authPackage: $authPackage,
            authVersion: $authVersion,
            notes: $notes,
        );
    }

    /**
     * @param  list<string>  $notes
     * @return array<mixed>|null
     */
    private function decode(?string $raw, string $name, array &$notes): ?array
    {
        if ($raw === null) {
            $notes[] = $name . ' not found';

            return null;
        }
        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $notes[] = $name . ' is not valid JSON';

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /** @param  list<string>  $notes */
    private function floorOf(string $constraint, array &$notes): ?string
    {
        try {
            $bound = (new VersionParser())->parseConstraints($constraint)->getLowerBound();
        } catch (\UnexpectedValueException) {
            $notes[] = 'PHP constraint could not be read';

            return null;
        }
        if ($bound->isZero()) {
            $notes[] = 'PHP constraint has no lower bound';

            return null;
        }
        $parts = explode('.', $bound->getVersion());

        return $parts[0] . '.' . ($parts[1] ?? '0');
    }

    /**
     * @param  array<mixed>  $lock
     * @return array<string, string>
     */
    private function lockedVersions(array $lock): array
    {
        $versions = [];
        foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null)) {
                continue;
            }
            $versions[strtolower($package['name'])] = ltrim($package['version'], 'vV');
        }

        return $versions;
    }

    /**
     * Finds the first auth package in list order among the given names. An
     * entry with a slash matches exactly; an entry without one is a bare
     * package name and matches that package under any vendor, so
     * `wayne-cas` matches `anyvendor/wayne-cas`. The answer carries the real
     * name found, vendor included, and its value.
     *
     * @param  array<array-key, mixed>  $available  package name to version or constraint
     * @return array{0: string, 1: mixed}|null  the real package name found, and its value
     */
    private function findAuthPackage(array $available): ?array
    {
        $byName = [];
        foreach ($available as $name => $value) {
            if (! is_string($name)) {
                continue;
            }
            $byName[strtolower($name)] = [$name, $value];
        }

        foreach ($this->authPackages as $entry) {
            $needle = strtolower(trim($entry));
            if ($needle === '') {
                continue;
            }
            if (str_contains($needle, '/')) {
                if (isset($byName[$needle])) {
                    return $byName[$needle];
                }

                continue;
            }
            foreach ($byName as $name => $found) {
                $slash = strrchr($name, '/');
                $last = $slash === false ? $name : substr($slash, 1);
                if ($last === $needle) {
                    return $found;
                }
            }
        }

        return null;
    }
}
