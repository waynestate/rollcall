<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Digest;

use Waynestate\Rollcall\Owner\Owner;

/**
 * Builds one digest per opted-in owner from two internal reports: the latest
 * scan and the one before it. It reads the report's per-app rows, so every
 * state and drift flag is the report builder's (and so CREED's), never a rule
 * of its own. A digest with no changes is still built; the caller sends or
 * prints only the ones with news, so a quiet week makes no noise.
 */
final class DigestBuilder
{
    /** The report's drift kinds in plain words, as "<app> now ..." and "<app> no longer ...". */
    public const DRIFT_WORDS = [
        'image_eol' => 'runs an end-of-life PHP image',
        'image_unknown' => 'has no known PHP image',
        'image_below_floor' => 'runs a PHP image below its declared PHP floor',
        'floor_allows_eol' => 'declares a PHP floor that allows an end-of-life PHP',
        'floor_unbounded' => 'declares no lower bound for PHP',
        'eol_past_grace' => 'is past the policy\'s grace days on an end-of-life PHP image',
        'laravel_eol' => 'runs an end-of-life Laravel',
        'proctor_missing' => 'has no PROCTOR result',
        'proctor_failing' => 'has a failing PROCTOR result',
        'override_expired' => 'has an expired PROCTOR override',
    ];

    private const STATE_WORDS = [
        'active' => 'active',
        'security_only' => 'security fixes only',
        'eol' => 'end of life',
        'unknown' => 'unknown',
    ];

    /**
     * @param  list<Owner>  $owners  the opted-in owners
     * @param  array<string, mixed>  $current  the internal report of the latest scan
     * @param  array<string, mixed>|null  $previous  the internal report of the scan before it, or null on the first scan
     * @return list<Digest>
     */
    public function build(array $owners, array $current, ?array $previous): array
    {
        $now = $this->rows($current);
        $was = $previous === null ? null : $this->rows($previous);
        $scanDate = is_string($current['scan_date'] ?? null) ? $current['scan_date'] : '';
        $since = $previous !== null && is_string($previous['scan_date'] ?? null) ? $previous['scan_date'] : null;

        $digests = [];
        foreach ($owners as $owner) {
            $changes = [];
            $standing = [];
            $present = 0;
            foreach ($owner->projects as $project) {
                $row = $now[$project] ?? null;
                $old = $was === null ? null : ($was[$project] ?? null);
                if ($row === null) {
                    $standing[] = $project . ': not in this scan';
                    if ($old !== null) {
                        $changes[] = $project . ': was in the last scan and is not in this one';
                    }

                    continue;
                }
                $present++;
                $standing[] = $this->standing($project, $row);
                if ($was !== null) {
                    array_push($changes, ...($old === null ? [$project . ': answered present for the first time'] : $this->changes($project, $old, $row)));
                }
            }
            if ($was === null && $present > 0) {
                $changes[] = sprintf('First roll call: %d of your %d %s answered present.', $present, count($owner->projects), count($owner->projects) === 1 ? 'app' : 'apps');
            }
            $digests[] = new Digest($owner, $scanDate, $since, $changes, $standing);
        }

        return $digests;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, array<string, mixed>>
     */
    private function rows(array $report): array
    {
        $rows = [];
        foreach (is_array($report['apps'] ?? null) ? $report['apps'] : [] as $row) {
            if (is_array($row) && is_string($row['id'] ?? null)) {
                $rows[$row['id']] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<string>
     */
    private function changes(string $project, array $old, array $new): array
    {
        $changes = [];
        if ($this->str($old, 'php_image') !== $this->str($new, 'php_image')) {
            $upgraded = $this->str($old, 'image_state') === 'eol' && in_array($this->str($new, 'image_state'), ['active', 'security_only'], true);
            $changes[] = $upgraded
                ? sprintf('%s: upgraded from end-of-life PHP %s to supported PHP %s. Present!', $project, $this->show($old, 'php_image'), $this->show($new, 'php_image'))
                : sprintf('%s: PHP image %s to %s (now %s)', $project, $this->show($old, 'php_image'), $this->show($new, 'php_image'), $this->state($new, 'image_state'));
        }
        if ($this->str($old, 'php_floor') !== $this->str($new, 'php_floor')) {
            $changes[] = sprintf('%s: declared PHP floor %s to %s', $project, $this->show($old, 'php_floor'), $this->show($new, 'php_floor'));
        }
        if ($this->str($old, 'laravel') !== $this->str($new, 'laravel')) {
            $changes[] = sprintf('%s: Laravel %s to %s (now %s)', $project, $this->show($old, 'laravel'), $this->show($new, 'laravel'), $this->state($new, 'laravel_state'));
        }
        if ($this->str($old, 'proctor') !== $this->str($new, 'proctor')) {
            $changes[] = sprintf('%s: PROCTOR result %s to %s', $project, $this->str($old, 'proctor') ?? 'none', $this->str($new, 'proctor') ?? 'none');
        }
        $dueBefore = $this->str($old, 'upgrade_due');
        $dueAfter = $this->str($new, 'upgrade_due');
        if ($dueBefore !== $dueAfter) {
            $changes[] = $dueAfter === null
                ? sprintf('%s: no forced image upgrade is pending any more', $project)
                : sprintf('%s: a forced image upgrade is scheduled, due %s', $project, $dueAfter);
        }
        $before = $this->drift($old);
        $after = $this->drift($new);
        foreach (array_diff($after, $before) as $kind) {
            $changes[] = sprintf('%s: now %s', $project, self::DRIFT_WORDS[$kind] ?? $kind);
        }
        foreach (array_diff($before, $after) as $kind) {
            $changes[] = sprintf('%s: no longer %s', $project, self::DRIFT_WORDS[$kind] ?? $kind);
        }

        return array_map($this->clean(...), $changes);
    }

    /** @param  array<string, mixed>  $row */
    private function standing(string $project, array $row): string
    {
        $open = array_map(static fn(string $kind): string => self::DRIFT_WORDS[$kind] ?? $kind, $this->drift($row));

        return $this->clean(sprintf(
            '%s: PHP image %s (%s), PHP floor %s (%s), Laravel %s (%s), PROCTOR %s.%s Open items: %s.',
            $project,
            $this->show($row, 'php_image'),
            $this->state($row, 'image_state'),
            $this->show($row, 'php_floor'),
            $this->state($row, 'floor_state'),
            $this->str($row, 'laravel') ?? 'none',
            $this->str($row, 'laravel') === null ? 'not a Laravel app' : $this->state($row, 'laravel_state'),
            $this->str($row, 'proctor') ?? 'none',
            $this->str($row, 'upgrade_due') === null ? '' : ' Forced image upgrade due ' . $this->str($row, 'upgrade_due') . '.',
            $open === [] ? 'none' : implode('; ', $open),
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function drift(array $row): array
    {
        return array_values(array_filter(is_array($row['drift'] ?? null) ? $row['drift'] : [], 'is_string'));
    }

    /** @param  array<string, mixed>  $row */
    private function str(array $row, string $key): ?string
    {
        return is_string($row[$key] ?? null) ? $row[$key] : null;
    }

    /** @param  array<string, mixed>  $row */
    private function show(array $row, string $key): string
    {
        return $this->str($row, $key) ?? 'unknown';
    }

    /** @param  array<string, mixed>  $row */
    private function state(array $row, string $key): string
    {
        $state = $this->str($row, $key) ?? 'unknown';

        return self::STATE_WORDS[$state] ?? $state;
    }

    /** Outside text (app ids, versions) never carries a control character into a mail or a terminal. */
    private function clean(string $line): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $line);
    }
}
