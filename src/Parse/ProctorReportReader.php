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
     * @return array{result: string, version: ?string, overrides: list<OverrideNote>}
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

        return ['result' => $result, 'version' => $version, 'overrides' => $overrides];
    }
}
