<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;

/**
 * The fleet census as Prometheus text (ADR-0011), for a Grafana burn-down.
 *
 * Built from the public report only, so no app id, owner or other personal
 * data can reach a label. Labels are version numbers, state names, drift
 * kinds and public package names, which keeps cardinality small. A value that
 * does not look like one of those becomes "unknown" or "other" instead of
 * becoming a new series.
 */
final class PrometheusRenderer
{
    private const NS = 'rollcall';

    /** @param  array<string, mixed>  $report  a public report from ReportBuilder */
    public function render(array $report): string
    {
        $registry = new CollectorRegistry(new InMemory(), false);

        $totals = self::arr($report['totals'] ?? []);
        $this->gauge($registry, 'apps', 'Apps that answered present in the last scan.', [], [[[], $totals['apps'] ?? 0]]);
        $this->gauge($registry, 'scan_errors', 'Problems in the last scan that did not stop it.', [], [[[], $totals['scan_errors'] ?? 0]]);

        $scan = \DateTimeImmutable::createFromFormat('!Y-m-d', is_string($report['scan_date'] ?? null) ? $report['scan_date'] : '');
        if ($scan !== false) {
            $this->gauge($registry, 'last_scan_timestamp_seconds', 'Unix time of midnight on the last scan day, in the server time zone.', [], [[[], $scan->getTimestamp()]]);
        }

        $this->gauge($registry, 'image_state_apps', 'Apps by the state of their PHP image.', ['state'], $this->pairs($report['image_states'] ?? [], self::state(...)));

        foreach (['php_image' => 'php_image_apps', 'php_floor' => 'php_floor_apps', 'laravel' => 'laravel_apps'] as $key => $name) {
            $rows = [];
            foreach (self::arr($report[$key] ?? []) as $row) {
                $row = self::arr($row);
                $rows[] = [[self::version($row['version'] ?? null), self::state($row['state'] ?? null)], $row['apps'] ?? 0];
            }
            $this->gauge($registry, $name, 'Apps by version and the version\'s state, from the policy.', ['version', 'state'], $rows);
        }

        $days = [];
        foreach (self::arr($report['php_image'] ?? []) as $row) {
            $row = self::arr($row);
            if (is_int($row['days_to_eol'] ?? null)) {
                $days[] = [[self::version($row['version'] ?? null)], $row['days_to_eol']];
            }
        }
        $this->gauge($registry, 'php_image_days_to_eol', 'Days until security support ends for a PHP image in use; negative once past.', ['version'], $days);

        $this->gauge($registry, 'auth_package_apps', 'Apps by auth package.', ['package'], $this->pairs($report['auth_package'] ?? [], self::package(...)));
        $this->gauge($registry, 'proctor_apps', 'Apps by PROCTOR result.', ['result'], $this->pairs($report['proctor'] ?? [], self::result(...)));
        $this->gauge($registry, 'drift_apps', 'Apps showing each kind of drift.', ['kind'], $this->pairs($report['drift'] ?? [], self::kind(...)));

        $progress = self::arr($report['progress'] ?? []);
        $this->gauge($registry, 'moved_apps', 'Apps whose PHP image changed since the previous scan.', [], [[[], $progress['moved'] ?? 0]]);
        $this->gauge($registry, 'upgraded_apps', 'Apps that went from an EOL image to a supported one since the previous scan.', [], [[[], $progress['upgraded_to_supported'] ?? 0]]);

        return (new RenderTextFormat())->render($registry->getMetricFamilySamples());
    }

    /**
     * @param  list<string>  $labels
     * @param  list<array{list<string>, mixed}>  $samples  label values, then the number
     */
    private function gauge(CollectorRegistry $registry, string $name, string $help, array $labels, array $samples): void
    {
        $gauge = $registry->getOrRegisterGauge(self::NS, $name, $help, $labels);
        foreach ($samples as [$values, $number]) {
            $gauge->set(is_int($number) || is_float($number) ? $number : 0, $values);
        }
    }

    /**
     * @param  callable(string): string  $clean
     * @return list<array{list<string>, mixed}>
     */
    private function pairs(mixed $map, callable $clean): array
    {
        $rows = [];
        foreach (self::arr($map) as $key => $count) {
            $rows[] = [[$clean((string) $key)], $count];
        }

        return $rows;
    }

    /** @return array<mixed> */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function version(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{1,3}(\.\d{1,3})?$/', $value) === 1 ? $value : 'unknown';
    }

    private static function state(mixed $value): string
    {
        return in_array($value, ['active', 'security_only', 'eol', 'unknown'], true) ? (string) $value : 'unknown';
    }

    private static function package(string $value): string
    {
        return $value === 'none' || preg_match('#^[a-z0-9._-]{1,60}/[a-z0-9._-]{1,60}$#', $value) === 1 ? $value : 'other';
    }

    private static function result(string $value): string
    {
        return in_array($value, ['pass', 'fail', 'none'], true) ? $value : 'none';
    }

    private static function kind(string $value): string
    {
        return preg_match('/^[a-z_]{1,40}$/', $value) === 1 ? $value : 'other';
    }
}
