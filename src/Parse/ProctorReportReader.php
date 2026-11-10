<?php

declare(strict_types=1);

namespace Waynestate\Rollcall\Parse;

use Waynestate\Rollcall\Model\OverrideNote;
use Waynestate\Rollcall\RollcallException;

/**
 * Reads the JSON PROCTOR prints with `--format=json`. Anything that is not
 * that shape is rejected, because the report is outside input and may be
 * hostile or simply stale.
 */
final class ProctorReportReader
{
    private const MAX_OVERRIDES = 50;

    /**
     * @return array{result: string, version: ?string, overrides: list<OverrideNote>, mysql56: string}
     */
    public function read(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RollcallException('PROCTOR report is not valid JSON.');
        }
        if (! is_array($data) || ($data['tool'] ?? null) !== 'proctor') {
            throw new RollcallException('PROCTOR report must have "tool": "proctor".');
        }
        $result = $data['result'] ?? null;
        if ($result !== 'pass' && $result !== 'fail') {
            throw new RollcallException('PROCTOR report "result" must be "pass" or "fail".');
        }
        $version = is_string($data['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+([-+.\w]*)$/', $data['version']) === 1
            ? $data['version']
            : null;

        $overrides = [];
        foreach (is_array($data['overrides'] ?? null) ? $data['overrides'] : [] as $override) {
            if (! is_array($override) || ! is_string($override['check'] ?? null)) {
                continue;
            }
            $overrides[] = new OverrideNote(
                substr($override['check'], 0, 100),
                substr(is_string($override['reason'] ?? null) ? $override['reason'] : '', 0, 500),
                is_string($override['expires'] ?? null) ? substr($override['expires'], 0, 20) : null,
                ($override['expired'] ?? false) === true,
            );
            if (count($overrides) >= self::MAX_OVERRIDES) {
                break;
            }
        }

        $missing = false;
        $held = false;
        foreach (is_array($data['findings'] ?? null) ? $data['findings'] : [] as $finding) {
            if (! is_array($finding) || ! is_string($finding['rule_id'] ?? null)) {
                continue;
            }
            if ($finding['rule_id'] === 'proctor/mysql56-ci/no-job') {
                $missing = true;
            } elseif ($finding['rule_id'] === 'proctor/mysql56-ci/held') {
                $held = true;
            }
        }
        $mysql56 = 'unknown';
        if ($missing) {
            $mysql56 = 'missing';
        } elseif ($held) {
            $mysql56 = 'held';
        } elseif ($version !== null && preg_match('/^(\d+\.\d+\.\d+)/', $version, $core) === 1 && version_compare($core[1], '0.15.1', '>=') === true) {
            $mysql56 = 'not_applicable';
        }

        return ['result' => $result, 'version' => $version, 'overrides' => $overrides, 'mysql56' => $mysql56];
    }
}
