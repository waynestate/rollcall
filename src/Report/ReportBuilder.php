<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Report;

use Composer\InstalledVersions;
use DateTimeImmutable;
use Waynestate\Rollcall\Creed\Policy;
use Waynestate\Rollcall\Lifecycle\Support;
use Waynestate\Rollcall\Model\AppFacts;
use Waynestate\Rollcall\Model\Snapshot;

/**
 * Turns snapshots and the policy into a report.
 *
 * The public part is counts and progress only: no app id, no owner, no ranking
 * (an app's number is only comparable to its own past, not to another app's).
 * The per-app list is built only on request and is internal.
 */
final class ReportBuilder
{
    public const VERSION = '0.1.4';          // fallback for a dev checkout
    private const PACKAGE = 'waynestate/rollcall';

    /**
     * The installed release version when Rollcall runs from a Packagist
     * install, the constant otherwise. A report must never print a stale
     * version.
     */
    public static function version(): string
    {
        return self::installedVersion() ?? self::VERSION;
    }

    private static function installedVersion(): ?string
    {
        try {
            if (InstalledVersions::getRootPackage()['name'] === self::PACKAGE) {
                return null; // this repo is the root package: no release version
            }
            $pretty = InstalledVersions::getPrettyVersion(self::PACKAGE);
        } catch (\Throwable) {
            return null;
        }
        if ($pretty === null || $pretty === '' || str_contains($pretty, 'no-version-set')) {
            return null;
        }

        return preg_replace('/^v/i', '', $pretty) ?? $pretty;
    }

    /** Every drift kind, so a report always lists all of them, zeros included. */
    public const DRIFT_KINDS = [
        'image_eol',
        'image_unknown',
        'image_below_floor',
        'floor_allows_eol',
        'floor_unbounded',
        'eol_past_grace',
        'laravel_eol',
        'proctor_missing',
        'proctor_failing',
        'override_expired',
    ];

    public function __construct(private readonly Policy $policy) {}

    /**
     * @param  list<Snapshot>  $history  every snapshot, oldest first, ending with $current
     * @return array<string, mixed>
     */
    public function build(Snapshot $current, array $history, DateTimeImmutable $today, bool $internal = false): array
    {
        $apps = $current->apps;
        $previous = $this->previousOf($current, $history);

        $drift = array_fill_keys(self::DRIFT_KINDS, 0);
        $states = $this->emptyStates();
        $proctor = ['pass' => 0, 'fail' => 0, 'none' => 0];
        $auth = [];
        $perApp = [];
        $upgradedOn = $internal ? $this->upgradeDates($history) : [];

        foreach ($apps as $app) {
            $flags = $this->flagsFor($app, $today);
            foreach ($flags as $flag) {
                $drift[$flag]++;
            }
            $imageState = $this->state('php', $app->phpImage, $today);
            $states[$imageState->value]++;
            $proctor[$app->proctorResult ?? 'none']++;
            $authKey = $app->authPackage ?? 'none';
            $auth[$authKey] = ($auth[$authKey] ?? 0) + 1;

            if ($internal) {
                $perApp[] = [
                    'id' => $app->id,
                    'php_image' => $app->phpImage,
                    'image_state' => $this->stateLabel('php', $app->phpImage, $today),
                    'php_floor' => $app->phpFloor,
                    'floor_state' => $this->stateLabel('php', $app->phpFloor, $today),
                    'laravel' => $app->laravel,
                    'laravel_state' => $this->stateLabel('laravel', $app->laravelMajor(), $today),
                    'auth_package' => $app->authPackage,
                    'auth_version' => $app->authVersion,
                    'proctor' => $app->proctorResult,
                    'overrides' => array_map(static fn($o): array => $o->toArray(), $app->overrides),
                    'drift' => $flags,
                    'upgraded_on' => $upgradedOn[$app->id] ?? null,
                    'notes' => $app->notes,
                ];
            }
        }
        ksort($auth);

        $report = [
            'tool' => 'rollcall',
            'version' => self::version(),
            'scan_date' => $current->date->format('Y-m-d'),
            'as_of' => $today->format('Y-m-d'),
            'visibility' => $internal ? 'internal' : 'public',
            'totals' => ['apps' => count($apps), 'scan_errors' => count($current->errors)],
            'image_states' => $states,
            'php_image' => $this->tally('php', array_map(static fn(AppFacts $a): ?string => $a->phpImage, $apps), $today),
            'php_floor' => $this->tally('php', array_map(static fn(AppFacts $a): ?string => $a->phpFloor, $apps), $today),
            'laravel' => $this->tally('laravel', array_map(static fn(AppFacts $a): ?string => $a->laravelMajor(), $apps), $today),
            'auth_package' => $auth,
            'proctor' => $proctor,
            'drift' => $drift,
            'progress' => $this->progress($current, $previous, $today),
            'burn_down' => $this->burnDown($history),
        ];
        if ($internal) {
            $report['apps'] = $perApp;
            $report['scan_errors'] = $current->errors;
        }

        return $report;
    }

    /**
     * The drift flags for one app. Each flag is a fact about the app's own versions.
     *
     * @return list<string>
     */
    public function flagsFor(AppFacts $app, DateTimeImmutable $today): array
    {
        $flags = [];
        $image = $this->state('php', $app->phpImage, $today);
        if ($app->phpImage === null) {
            $flags[] = 'image_unknown';
        } elseif ($image === Support::Eol) {
            $flags[] = 'image_eol';
            if ($this->policy->isPastGrace('php', $app->phpImage, $today)) {
                $flags[] = 'eol_past_grace';
            }
        }
        if ($app->phpFloor === null) {
            $flags[] = 'floor_unbounded';
        } else {
            if ($this->state('php', $app->phpFloor, $today) === Support::Eol) {
                $flags[] = 'floor_allows_eol';
            }
            if ($app->phpImage !== null && version_compare($app->phpImage, $app->phpFloor, '<')) {
                $flags[] = 'image_below_floor';
            }
        }
        if ($this->state('laravel', $app->laravelMajor(), $today) === Support::Eol) {
            $flags[] = 'laravel_eol';
        }
        if ($app->proctorResult === null) {
            $flags[] = 'proctor_missing';
        } elseif ($app->proctorResult === 'fail') {
            $flags[] = 'proctor_failing';
        }
        foreach ($app->overrides as $override) {
            if ($override->expired) {
                $flags[] = 'override_expired';
                break;
            }
        }

        return array_values(array_unique($flags));
    }

    /** The report state for a version: CREED's answer, in ROLLCALL's words. */
    private function state(string $component, ?string $version, DateTimeImmutable $day): Support
    {
        return Support::fromTryStateOf($this->policy->tryStateOf($component, $version, $day));
    }

    /**
     * The state a version shows in the report.
     *
     * A version the policy does not list but that sorts above every listed
     * line is newer than the policy; CREED treats it as current, and the
     * report says so instead of a bare "active".
     */
    private function stateLabel(string $component, ?string $version, DateTimeImmutable $day): string
    {
        if ($version !== null && $this->policy->hasComponent($component) && $this->policy->isAheadOfPolicy($component, $version)) {
            return 'newer than CREED\'s policy; treated as current';
        }

        return $this->state($component, $version, $day)->value;
    }

    /** @return array<string, int> */
    private function emptyStates(): array
    {
        return [Support::Active->value => 0, Support::SecurityOnly->value => 0, Support::Eol->value => 0, Support::Unknown->value => 0];
    }

    /**
     * @param  list<?string>  $values
     * @return list<array{version: string, apps: int, state: string, security_until: ?string, days_to_eol: ?int}>
     */
    private function tally(string $component, array $values, DateTimeImmutable $today): array
    {
        $counts = [];
        foreach ($values as $value) {
            $key = $value ?? 'unknown';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        uksort($counts, static function (string $a, string $b): int {
            if ($a === 'unknown' || $b === 'unknown') {
                return $a === 'unknown' ? ($b === 'unknown' ? 0 : 1) : -1;
            }

            return version_compare($a, $b);
        });

        $rows = [];
        foreach ($counts as $version => $apps) {
            $version = (string) $version;
            $known = $version !== 'unknown';
            $until = $known ? $this->policy->securityUntil($component, $version) : null;
            $rows[] = [
                'version' => $version,
                'apps' => $apps,
                'state' => $this->stateLabel($component, $known ? $version : null, $today),
                'security_until' => $until?->format('Y-m-d'),
                'days_to_eol' => $known ? $this->policy->daysToEol($component, $version, $today) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<Snapshot>  $history
     */
    private function previousOf(Snapshot $current, array $history): ?Snapshot
    {
        $previous = null;
        foreach ($history as $snapshot) {
            if ($snapshot->date < $current->date) {
                $previous = $snapshot;
            }
        }

        return $previous;
    }

    /**
     * @return array<string, mixed>
     */
    private function progress(Snapshot $current, ?Snapshot $previous, DateTimeImmutable $today): array
    {
        if ($previous === null) {
            return ['since' => null, 'moved' => 0, 'upgraded_to_supported' => 0, 'new_apps' => count($current->apps), 'gone_apps' => 0];
        }
        $was = [];
        foreach ($previous->apps as $app) {
            $was[$app->id] = $app;
        }
        $moved = 0;
        $upgraded = 0;
        $new = 0;
        foreach ($current->apps as $app) {
            $old = $was[$app->id] ?? null;
            if ($old === null) {
                $new++;

                continue;
            }
            unset($was[$app->id]);
            if ($old->phpImage !== null && $app->phpImage !== null && $old->phpImage !== $app->phpImage) {
                $moved++;
                $wasState = $this->state('php', $old->phpImage, $previous->date);
                if ($wasState === Support::Eol && $this->state('php', $app->phpImage, $today)->isSupported()) {
                    $upgraded++;
                }
            }
        }

        return [
            'since' => $previous->date->format('Y-m-d'),
            'moved' => $moved,
            'upgraded_to_supported' => $upgraded,
            'new_apps' => $new,
            'gone_apps' => count($was),
        ];
    }

    /**
     * The EOL burn-down: for each scan, how many apps ran an EOL image on that day.
     *
     * @param  list<Snapshot>  $history
     * @return list<array{date: string, apps: int, eol: int, supported: int, unknown: int}>
     */
    private function burnDown(array $history): array
    {
        $series = [];
        foreach ($history as $snapshot) {
            $eol = 0;
            $supported = 0;
            $unknown = 0;
            foreach ($snapshot->apps as $app) {
                $state = $this->state('php', $app->phpImage, $snapshot->date);
                match (true) {
                    $state === Support::Eol => $eol++,
                    $state->isSupported() => $supported++,
                    default => $unknown++,
                };
            }
            $series[] = ['date' => $snapshot->date->format('Y-m-d'), 'apps' => count($snapshot->apps), 'eol' => $eol, 'supported' => $supported, 'unknown' => $unknown];
        }

        return $series;
    }

    /**
     * The day each app first showed a supported image after an EOL one.
     *
     * @param  list<Snapshot>  $history
     * @return array<string, string>
     */
    private function upgradeDates(array $history): array
    {
        $dates = [];
        $lastState = [];
        foreach ($history as $snapshot) {
            foreach ($snapshot->apps as $app) {
                $state = $this->state('php', $app->phpImage, $snapshot->date);
                if (($lastState[$app->id] ?? null) === Support::Eol && $state->isSupported() && ! isset($dates[$app->id])) {
                    $dates[$app->id] = $snapshot->date->format('Y-m-d');
                }
                $lastState[$app->id] = $state;
            }
        }

        return $dates;
    }
}
