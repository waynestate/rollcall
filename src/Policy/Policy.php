<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Policy;

use DateTimeImmutable;
use Waynestate\Rollcall\RollcallException;

/**
 * CREED's policy.json, read and checked. State is derived from the dates by
 * the day asked about; no version in this file is special-cased in code.
 */
final class Policy
{
    private const DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @param  array<string, array<string, array{active_until: ?string, security_until: ?string}>>  $components  component, then version, then its dates
     */
    private function __construct(
        private readonly array $components,
        public readonly int $eolGraceDays,
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RollcallException(sprintf('Policy file "%s" is missing or unreadable. Set ROLLCALL_POLICY_FILE.', $path));
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RollcallException(sprintf('Policy file "%s" could not be read.', $path));
        }

        return self::fromJson($raw);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RollcallException('Policy file is not valid JSON: ' . $e->getMessage());
        }
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1) {
            throw new RollcallException('Policy file must have "schema_version": 1.');
        }
        $rawComponents = $data['components'] ?? null;
        if (! is_array($rawComponents) || $rawComponents === []) {
            throw new RollcallException('Policy file has no "components".');
        }

        $components = [];
        foreach ($rawComponents as $name => $component) {
            $name = (string) $name;
            if (! is_array($component) || ! is_array($component['versions'] ?? null)) {
                throw new RollcallException(sprintf('Policy component "%s" has no "versions".', $name));
            }
            $components[$name] = [];
            foreach ($component['versions'] as $version => $dates) {
                $version = (string) $version;
                if (! is_array($dates)) {
                    throw new RollcallException(sprintf('Policy version "%s %s" must be an object.', $name, $version));
                }
                $components[$name][$version] = [
                    'active_until' => self::date($dates['active_until'] ?? null, $name, $version),
                    'security_until' => self::date($dates['security_until'] ?? null, $name, $version),
                ];
            }
        }

        $rules = $data['rules'] ?? [];
        $grace = is_array($rules) ? ($rules['eol_grace_days'] ?? 90) : 90;
        if (! is_int($grace) || $grace < 0) {
            throw new RollcallException('Policy rules.eol_grace_days must be a non-negative integer.');
        }

        return new self($components, $grace);
    }

    private static function date(mixed $value, string $component, string $version): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match(self::DATE, $value) !== 1) {
            throw new RollcallException(sprintf('Policy date for "%s %s" must be YYYY-MM-DD.', $component, $version));
        }

        return $value;
    }

    public function hasComponent(string $component): bool
    {
        return isset($this->components[$component]);
    }

    /** @return list<string> */
    public function versions(string $component): array
    {
        return array_map('strval', array_keys($this->components[$component] ?? []));
    }

    public function securityUntil(string $component, string $version): ?DateTimeImmutable
    {
        $date = $this->components[$component][$version]['security_until'] ?? null;

        return $date === null ? null : self::parse($date);
    }

    public function activeUntil(string $component, string $version): ?DateTimeImmutable
    {
        $date = $this->components[$component][$version]['active_until'] ?? null;

        return $date === null ? null : self::parse($date);
    }

    public function stateOf(string $component, ?string $version, DateTimeImmutable $today): State
    {
        if ($version === null || ! isset($this->components[$component])) {
            return State::Unknown;
        }
        $dates = $this->components[$component][$version] ?? null;
        if ($dates === null) {
            // A version older than everything the policy lists is long past its end.
            // A newer one is unknown: the policy has not caught up yet.
            return $this->isOlderThanListed($component, $version) ? State::Eol : State::Unknown;
        }

        $day = $today->format('Y-m-d');
        $active = $dates['active_until'];
        $security = $dates['security_until'] ?? $active;
        if ($active === null && $security === null) {
            return State::Unknown;
        }
        if ($active !== null && $day <= $active) {
            return State::Active;
        }
        if ($security !== null && $day <= $security) {
            return State::SecurityOnly;
        }

        return State::Eol;
    }

    /** Whole days from today to the end of security support; negative once past. */
    public function daysToEol(string $component, string $version, DateTimeImmutable $today): ?int
    {
        $end = $this->securityUntil($component, $version);
        if ($end === null) {
            return null;
        }

        return (int) $today->setTime(0, 0)->diff($end->setTime(0, 0))->format('%r%a');
    }

    /** True once a version is past its end of support by more than the policy's grace days. */
    public function isPastGrace(string $component, string $version, DateTimeImmutable $today): bool
    {
        $days = $this->daysToEol($component, $version, $today);

        return $days !== null && $days < -$this->eolGraceDays;
    }

    private function isOlderThanListed(string $component, string $version): bool
    {
        $listed = $this->versions($component);
        if ($listed === []) {
            return false;
        }
        usort($listed, static fn(string $a, string $b): int => version_compare($a, $b));

        return version_compare($version, $listed[0], '<');
    }

    private static function parse(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false) {
            throw new RollcallException(sprintf('Bad date "%s" in the policy.', $date));
        }

        return $parsed;
    }
}
