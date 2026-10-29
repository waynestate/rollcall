<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Lifecycle;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Waynestate\Rollcall\RollcallException;

/**
 * Checks locked package versions against a saved Packagist security-advisories
 * response (GET /api/security-advisories/?packages[]=vendor/name, saved to a
 * file by a person). ROLLCALL never fetches the feed itself: there is no
 * unattended outbound call, the same default as the endoflife.date drift check.
 *
 * The rule for an absent row: a package with no advisory data is "no data",
 * never "safe". A missing version, a package the feed was never asked about, or
 * a range nobody can parse proves nothing about the package, so the answer
 * stays "no data" instead of guessing.
 */
final class PackageAdvisories
{
    /** @var array<string, list<array<mixed>>> */
    private readonly array $advisories;

    public function __construct(string $feedJson)
    {
        try {
            $data = json_decode($feedJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RollcallException('The advisories file is not valid JSON.');
        }
        if (! is_array($data) || ! is_array($data['advisories'] ?? null)) {
            throw new RollcallException('The advisories file has no advisories object (expected the Packagist shape {"advisories":{"vendor/name":[...]}}).');
        }
        $advisories = [];
        foreach ($data['advisories'] as $package => $list) {
            if (is_string($package) && is_array($list)) {
                $advisories[strtolower($package)] = $list;
            }
        }
        $this->advisories = $advisories;
    }

    /**
     * One package at one version, against the saved feed.
     *
     * "affected" when at least one saved advisory covers the version; "clear"
     * when the feed was asked about this package and lists none that apply
     * (no known advisories for this version in the saved feed); "no data" when
     * the question cannot be answered from the feed: the version is missing,
     * the package is absent from the feed, or a version or an affectedVersions
     * range cannot be parsed.
     *
     * @return array{status: string, count: int, ids: list<string>}
     */
    public function check(string $package, ?string $version): array
    {
        if ($version === null || trim($version) === '') {
            return $this->result('no data', []);
        }
        $key = strtolower(trim($package));
        if ($key === '' || ! array_key_exists($key, $this->advisories)) {
            return $this->result('no data', []);
        }

        // Normalise before matching: a lock file may carry a leading "v".
        $normalized = ltrim(trim($version), 'vV');
        try {
            $normalized = (new VersionParser())->normalize($normalized);
        } catch (\UnexpectedValueException) {
            return $this->result('no data', []);
        }

        $matched = 0;
        $ids = [];
        foreach ($this->advisories[$key] as $advisory) {
            if (! is_array($advisory) || ! is_string($advisory['affectedVersions'] ?? null)) {
                // An advisory without a readable range cannot be judged.
                return $this->result('no data', []);
            }
            try {
                $covers = Semver::satisfies($normalized, $advisory['affectedVersions']);
            } catch (\UnexpectedValueException) {
                // An unparseable range cannot be ruled out: "no data", not "clear".
                return $this->result('no data', []);
            }
            if ($covers) {
                $matched++;
                if (is_string($advisory['advisoryId'] ?? null)) {
                    $ids[] = $advisory['advisoryId'];
                }
            }
        }

        if ($matched === 0) {
            return $this->result('clear');
        }

        // A matched advisory without an id still counts; its id is simply not listed.
        return ['status' => 'affected', 'count' => $matched, 'ids' => $ids];
    }

    /**
     * @return array{status: string, count: int, ids: list<string>}
     */
    private function result(string $status): array
    {
        return ['status' => $status, 'count' => 0, 'ids' => []];
    }
}
