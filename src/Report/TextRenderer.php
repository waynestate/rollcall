<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Report;

/**
 * The report as plain terminal tables. Every cell is stripped of control
 * characters first, because app ids and notes come from outside input and a
 * terminal will happily obey an escape sequence it should only print.
 */
final class TextRenderer
{
    /** @param  array<string, mixed>  $report */
    public function render(array $report): string
    {
        $out = [];
        $out[] = sprintf(
            'ROLLCALL %s | scan %s | as of %s | %s',
            self::cell($report['version'] ?? ''),
            self::cell($report['scan_date'] ?? ''),
            self::cell($report['as_of'] ?? ''),
            self::cell($report['visibility'] ?? ''),
        );
        $totals = self::arr($report['totals'] ?? []);
        $out[] = sprintf('Apps present: %s (scan problems: %s)', self::cell($totals['apps'] ?? 0), self::cell($totals['scan_errors'] ?? 0));
        $out[] = '';

        foreach (['php_image' => 'PHP image', 'php_floor' => 'Declared PHP floor', 'laravel' => 'Laravel'] as $key => $title) {
            $rows = [];
            foreach (self::arr($report[$key] ?? []) as $row) {
                $row = self::arr($row);
                $rows[] = [$row['version'] ?? '', $row['apps'] ?? '', $row['state'] ?? '', $row['security_until'] ?? '-', $row['days_to_eol'] ?? '-'];
            }
            $out[] = $title;
            $out[] = self::table(['VERSION', 'APPS', 'STATE', 'SECURITY UNTIL', 'DAYS TO EOL'], $rows);
            $out[] = '';
        }

        $out[] = 'Auth package';
        $out[] = self::table(['PACKAGE', 'APPS'], self::pairs($report['auth_package'] ?? []));
        $out[] = '';
        $out[] = 'PROCTOR';
        $out[] = self::table(['RESULT', 'APPS'], self::pairs($report['proctor'] ?? []));
        $out[] = '';
        $out[] = 'Drift';
        $out[] = self::table(['KIND', 'APPS'], self::pairs($report['drift'] ?? []));
        $out[] = '';

        $progress = self::arr($report['progress'] ?? []);
        $out[] = sprintf(
            'Since %s: %s moved, %s upgraded to a supported image, %s new, %s gone.',
            self::cell($progress['since'] ?? 'the first scan'),
            self::cell($progress['moved'] ?? 0),
            self::cell($progress['upgraded_to_supported'] ?? 0),
            self::cell($progress['new_apps'] ?? 0),
            self::cell($progress['gone_apps'] ?? 0),
        );
        $out[] = '';

        $burn = [];
        foreach (self::arr($report['burn_down'] ?? []) as $point) {
            $point = self::arr($point);
            $burn[] = [$point['date'] ?? '', $point['apps'] ?? '', $point['eol'] ?? '', $point['supported'] ?? '', $point['unknown'] ?? ''];
        }
        $out[] = 'EOL burn-down (state on each scan day)';
        $out[] = self::table(['DATE', 'APPS', 'EOL', 'SUPPORTED', 'UNKNOWN'], $burn);

        if (isset($report['apps']) && is_array($report['apps'])) {
            $out[] = '';
            $out[] = 'Apps (internal, do not publish)';
            $rows = [];
            foreach ($report['apps'] as $app) {
                $app = self::arr($app);
                $rows[] = [
                    $app['id'] ?? '', $app['php_image'] ?? '-', $app['image_state'] ?? '', $app['php_floor'] ?? '-',
                    $app['laravel'] ?? '-', $app['auth_package'] ?? '-', $app['proctor'] ?? '-',
                    implode(',', array_map('strval', self::arr($app['drift'] ?? []))),
                ];
            }
            $out[] = self::table(['APP', 'IMAGE', 'STATE', 'FLOOR', 'LARAVEL', 'AUTH', 'PROCTOR', 'DRIFT'], $rows);
        }

        return implode("\n", $out) . "\n";
    }

    /** @return array<mixed> */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array{string, mixed}> */
    private static function pairs(mixed $value): array
    {
        $rows = [];
        foreach (self::arr($value) as $key => $count) {
            $rows[] = [(string) $key, $count];
        }

        return $rows;
    }

    private static function cell(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }
        $text = is_scalar($value) ? (string) $value : '?';

        return (string) preg_replace('/[\x00-\x1F\x7F]/u', '?', $text);
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<mixed>>  $rows
     */
    private static function table(array $headers, array $rows): string
    {
        $cells = [$headers];
        foreach ($rows as $row) {
            $cells[] = array_map(self::cell(...), array_values($row));
        }
        $widths = [];
        foreach ($cells as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }
        $lines = [];
        foreach ($cells as $row) {
            $parts = [];
            foreach ($row as $i => $cell) {
                $parts[] = $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell));
            }
            $lines[] = rtrim(implode('  ', $parts));
        }

        return implode("\n", $lines);
    }
}
